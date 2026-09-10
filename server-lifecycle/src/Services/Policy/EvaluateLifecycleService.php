<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Policy;

use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Jobs\ArchiveServerJob;
use HarbourmasterSam\ServerLifecycle\Jobs\DeleteArchiveJob;
use HarbourmasterSam\ServerLifecycle\Models\LifecycleNotificationDelivery;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;

class EvaluateLifecycleService
{
    public function handle(): void
    {
        $now = now();
        ServerLifecycleState::query()->where('automatic_enabled', true)->whereNotNull('archive_due_at')->where('archive_due_at', '<=', $now)->where(fn ($q) => $q->whereNull('exempt_until')->orWhere('exempt_until', '<=', $now))->pluck('server_id')->each(fn (int $id) => ArchiveServerJob::dispatch($id));
        ServerArchive::query()->where('status', LifecycleStatus::Archived)->whereNotNull('retention_expires_at')->where('retention_expires_at', '<=', $now)->each(function (ServerArchive $archive) use ($now): void {
            $grace = (int) data_get($archive->policy_snapshot, 'final_delivery_grace_minutes', 0);
            $archive->update(['status' => LifecycleStatus::PendingDeletion, 'pending_deletion_at' => $now, 'final_delivery_expires_at' => $now->copy()->addMinutes($grace)]);
        });
        ServerArchive::query()->whereIn('status', [LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed])->where('final_delivery_expires_at', '<=', $now)->pluck('id')->each(fn (string $id) => DeleteArchiveJob::dispatch($id));
        $this->recordDueWarnings($now);
    }

    private function recordDueWarnings($now): void
    {
        ServerLifecycleState::query()->with('policy.warningRules')->whereNotNull('archive_due_at')->each(function ($state) use ($now): void {
            foreach ($state->policy?->warningRules ?? [] as $rule) {
                if ($rule->phase->value !== 'archive' || $now->lt($state->archive_due_at->subMinutes($rule->offset_minutes)) || $now->gte($state->archive_due_at)) continue;
                foreach (['database' => $rule->database_enabled, 'mail' => $rule->email_enabled] as $channel => $enabled) if ($enabled) LifecycleNotificationDelivery::query()->firstOrCreate(['warning_rule_id' => $rule->id, 'server_id' => $state->server_id, 'archive_id' => null, 'channel' => $channel, 'target_at' => $state->archive_due_at]);
            }
        });
    }
}
