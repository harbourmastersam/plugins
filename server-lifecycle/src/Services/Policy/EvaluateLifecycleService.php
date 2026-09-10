<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Policy;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\FinalDeliveryMode;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Enums\WarningPhase;
use HarbourmasterSam\ServerLifecycle\Jobs\ArchiveServerJob;
use HarbourmasterSam\ServerLifecycle\Jobs\DeleteArchiveJob;
use HarbourmasterSam\ServerLifecycle\Jobs\DeliverFinalArchiveJob;
use HarbourmasterSam\ServerLifecycle\Jobs\DeliverLifecycleNotificationJob;
use HarbourmasterSam\ServerLifecycle\Jobs\RetryServerDeletionJob;
use HarbourmasterSam\ServerLifecycle\Models\LifecycleNotificationDelivery;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use Illuminate\Support\Carbon;

class EvaluateLifecycleService
{
    public function handle(): void
    {
        $now = now();
        $this->initializeMissingStates($now);
        $this->queueArchiveWarnings($now);
        $this->queueDueArchives($now);
        $this->queueDeletionWarnings($now);
        $this->beginPendingDeletion($now);
        $this->queueFinalDelivery();
        $this->queuePermanentDeletion($now);
        $this->reconcileRecoverableArchives();
        $this->queueUndeliveredNotifications();
    }

    private function initializeMissingStates(Carbon $now): void
    {
        Server::query()
            ->whereNotIn('id', ServerLifecycleState::query()->select('server_id'))
            ->select('id')
            ->chunkById(100, function ($servers) use ($now): void {
                foreach ($servers as $server) {
                    ServerLifecycleState::query()->firstOrCreate(
                        ['server_id' => $server->id],
                        [
                            'automatic_enabled' => false,
                            'last_activity_at' => $now,
                            'status' => LifecycleStatus::Active,
                        ],
                    );
                }
            });
    }

    private function queueDueArchives(Carbon $now): void
    {
        ServerLifecycleState::query()
            ->where('automatic_enabled', true)
            ->where('is_exempt', false)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Warning])
            ->whereNotNull('archive_due_at')
            ->where('archive_due_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('exempt_until')->orWhere('exempt_until', '<=', $now))
            ->pluck('server_id')
            ->each(fn (int $id) => ArchiveServerJob::dispatch($id));
    }

    private function queueArchiveWarnings(Carbon $now): void
    {
        ServerLifecycleState::query()
            ->with('policy.warningRules')
            ->where('automatic_enabled', true)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Warning])
            ->whereNotNull('archive_due_at')
            ->each(function (ServerLifecycleState $state) use ($now): void {
                foreach ($state->policy?->warningRules ?? [] as $rule) {
                    if ($rule->phase !== WarningPhase::Archive
                        || $now->lt($state->archive_due_at->subMinutes($rule->offset_minutes))
                        || $now->gte($state->archive_due_at)) {
                        continue;
                    }
                    $state->update(['status' => LifecycleStatus::Warning]);
                    $this->createDeliveries($rule, "server:$state->server_id", $state->archive_due_at, $state->server_id);
                }
            });
    }

    private function queueDeletionWarnings(Carbon $now): void
    {
        ServerArchive::query()
            ->whereIn('status', [LifecycleStatus::Archived, LifecycleStatus::DeletionWarning, LifecycleStatus::Restored])
            ->whereNotNull('retention_expires_at')
            ->each(function (ServerArchive $archive) use ($now): void {
                $policy = LifecyclePolicy::query()->with('warningRules')->find(data_get($archive->policy_snapshot, 'policy_id'));
                foreach ($policy?->warningRules ?? [] as $rule) {
                    if ($rule->phase !== WarningPhase::Delete
                        || $now->lt($archive->retention_expires_at->subMinutes($rule->offset_minutes))
                        || $now->gte($archive->retention_expires_at)) {
                        continue;
                    }
                    $archive->update(['status' => LifecycleStatus::DeletionWarning]);
                    $this->createDeliveries($rule, "archive:$archive->id", $archive->retention_expires_at, null, $archive->id);
                }
            });
    }

    private function createDeliveries($rule, string $subjectKey, Carbon $target, ?int $serverId = null, ?string $archiveId = null): void
    {
        foreach (['database' => $rule->database_enabled, 'mail' => $rule->email_enabled] as $channel => $enabled) {
            if (! $enabled) {
                continue;
            }
            LifecycleNotificationDelivery::query()->firstOrCreate([
                'warning_rule_id' => $rule->id,
                'server_id' => $serverId,
                'archive_id' => $archiveId,
                'subject_key' => $subjectKey,
                'channel' => $channel,
                'target_at' => $target,
            ]);
        }
    }

    private function beginPendingDeletion(Carbon $now): void
    {
        ServerArchive::query()
            ->whereIn('status', [LifecycleStatus::Archived, LifecycleStatus::DeletionWarning, LifecycleStatus::Restored])
            ->whereNotNull('retention_expires_at')
            ->where('retention_expires_at', '<=', $now)
            ->each(function (ServerArchive $archive) use ($now): void {
                $archive->update([
                    'status' => LifecycleStatus::PendingDeletion,
                    'pending_deletion_at' => $now,
                    'final_delivery_sent_at' => null,
                    'final_delivery_expires_at' => null,
                ]);
            });
    }

    private function queueFinalDelivery(): void
    {
        ServerArchive::query()
            ->where('status', LifecycleStatus::PendingDeletion)
            ->whereNull('final_delivery_sent_at')
            ->pluck('id')
            ->each(fn (string $id) => DeliverFinalArchiveJob::dispatch($id));
    }

    private function queuePermanentDeletion(Carbon $now): void
    {
        ServerArchive::query()
            ->whereIn('status', [LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed])
            ->whereNotNull('final_delivery_sent_at')
            ->where('final_delivery_expires_at', '<=', $now)
            ->get()
            ->filter(function (ServerArchive $archive): bool {
                $mode = FinalDeliveryMode::from(data_get($archive->policy_snapshot, 'final_delivery_mode', 'none'));

                return $mode === FinalDeliveryMode::None || $archive->final_delivery_sent_at !== null;
            })
            ->each(fn (ServerArchive $archive) => DeleteArchiveJob::dispatch($archive->id));
    }

    private function reconcileRecoverableArchives(): void
    {
        ServerArchive::query()
            ->where('status', LifecycleStatus::ArchiveCreatedDeleteFailed)
            ->where(fn ($query) => $query->whereNull('retry_after')->orWhere('retry_after', '<=', now()))
            ->pluck('id')
            ->each(fn (string $id) => RetryServerDeletionJob::dispatch($id));
    }

    private function queueUndeliveredNotifications(): void
    {
        LifecycleNotificationDelivery::query()
            ->whereNull('delivered_at')
            ->pluck('id')
            ->each(fn (int $id) => DeliverLifecycleNotificationJob::dispatch($id));
    }
}
