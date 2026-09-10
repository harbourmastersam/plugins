<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;
use App\Services\Servers\ServerDeletionService;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Enums\RetryDeletionDecision;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class RetryServerDeletionService
{
    public function __construct(
        private ArchiveStorageInterface $storage,
        private ServerDeletionService $deletion,
        private RetryDeletionEligibilityService $eligibility,
    ) {}

    public function handle(ServerArchive $archive): void
    {
        Cache::lock("server-lifecycle:archive:$archive->original_server_id", 300)
            ->block(5, fn () => $this->retryWhileLocked($archive));
    }

    private function retryWhileLocked(ServerArchive $archive): void
    {
        $archive->refresh();
        if ($archive->status !== LifecycleStatus::ArchiveCreatedDeleteFailed) {
            return;
        }
        if (! $archive->object_key || ! $this->storage->exists($archive)) {
            throw new RuntimeException('The adopted archive cannot be confirmed; server deletion is refused.');
        }

        $state = ServerLifecycleState::query()->where('server_id', $archive->original_server_id)->first();
        $server = Server::query()->find($archive->original_server_id);
        if (! $server) {
            $this->markArchived($archive);
            return;
        }
        if (! $state
            || $state->status !== LifecycleStatus::ArchiveCreatedDeleteFailed
            || $state->current_archive_id !== $archive->id) {
            throw new RuntimeException('The adopted archive no longer owns the live server lifecycle attempt.');
        }
        if ($state->last_activity_at->gt($archive->activity_snapshot_at)) {
            $this->cancelAuthority($archive, $state);
            return;
        }

        try {
            $decision = $this->eligibility->decide($server);
        } catch (Throwable $exception) {
            $archive->update([
                'retry_count' => $archive->retry_count + 1,
                'retry_after' => now()->addMinutes(min(60, 2 ** min(6, $archive->retry_count))),
                'last_error' => 'Wings status could not be confirmed; deletion retry remains blocked.',
            ]);
            throw $exception;
        }
        if ($decision === RetryDeletionDecision::RevokeAuthority) {
            $this->cancelAuthority($archive, $state);
            return;
        }
        $state->refresh();
        if ($state->status !== LifecycleStatus::ArchiveCreatedDeleteFailed
            || $state->current_archive_id !== $archive->id
            || $state->last_activity_at->gt($archive->activity_snapshot_at)) {
            $this->cancelAuthority($archive, $state);
            return;
        }

        $server->unsetRelation('backups')->refresh();
        try {
            $this->deletion->handle($server);
        } catch (Throwable $exception) {
            $archive->update([
                'retry_count' => $archive->retry_count + 1,
                'retry_after' => now()->addMinutes(min(60, 2 ** min(6, $archive->retry_count))),
                'last_error' => 'Native server deletion retry failed; archive retained.',
            ]);
            throw $exception;
        }

        $this->markArchived($archive);
    }

    private function cancelAuthority(ServerArchive $archive, ServerLifecycleState $state): void
    {
        $policy = $state->policy
            ?: LifecyclePolicy::query()->where('enabled', true)->where('is_default', true)->first();
        $retention = data_get($archive->policy_snapshot, 'archive_retention_minutes');
        $state->update([
            'status' => LifecycleStatus::Active,
            'current_archive_id' => null,
            'archive_due_at' => $policy?->inactivity_minutes === null
                ? null
                : $state->last_activity_at->addMinutes($policy->inactivity_minutes),
            'last_error' => null,
        ]);
        $archive->update([
            'status' => LifecycleStatus::ArchiveSuperseded,
            'archived_at' => $archive->archived_at ?? now(),
            'retention_expires_at' => $archive->retention_expires_at
                ?? ($retention === null ? null : now()->addMinutes((int) $retention)),
            'retry_after' => null,
            'last_error' => 'Adopted archive no longer authorizes deletion because the live server became active.',
        ]);
    }

    private function markArchived(ServerArchive $archive): void
    {
        $retention = data_get($archive->policy_snapshot, 'archive_retention_minutes');
        $archive->update([
            'status' => LifecycleStatus::Archived,
            'archived_at' => $archive->archived_at ?? now(),
            'retention_expires_at' => $archive->retention_expires_at
                ?? ($retention === null ? null : now()->addMinutes((int) $retention)),
            'last_error' => null,
            'retry_after' => null,
        ]);
    }
}
