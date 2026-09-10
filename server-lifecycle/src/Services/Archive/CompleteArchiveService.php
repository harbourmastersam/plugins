<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Backup;
use App\Models\Server;
use App\Services\Servers\ServerDeletionService;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Status\FreshWingsServerStatusService;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use RuntimeException;
use Throwable;

class CompleteArchiveService
{
    public function __construct(
        private ArchiveStorageInterface $storage,
        private AdoptBackupAsArchiveService $adopter,
        private ServerDeletionService $deletion,
        private ArchiveEligibilityService $eligibility,
        private ArchiveFailureService $failures,
        private FreshWingsServerStatusService $statuses,
    ) {}

    public function handle(ServerArchive $archive, Backup $backup): void
    {
        try {
            $this->assertCompletedBackup($backup);
            $metadata = $this->storage->head($archive);
            if ($metadata->bytes !== (int) $backup->bytes) {
                throw new RuntimeException('Remote archive size does not match the completed backup.');
            }

            [$server, $state] = $this->revalidateAttempt($archive, $backup);
            $policy = LifecyclePolicy::query()->findOrFail(data_get($archive->policy_snapshot, 'policy_id'));
            // Native server deletion removes every remaining Backup row. The
            // adopted lifecycle backup is the sole permitted row at this point.
            $this->eligibility->assertEligible($server, $policy->load('archiveBackupHost'), $backup->id);
            $this->assertFreshOffline($server);

            // Re-read after the Wings call so activity occurring during status
            // retrieval also invalidates the destructive continuation.
            $state->refresh();
            if ($state->status !== LifecycleStatus::Archiving
                || $state->current_archive_id !== $archive->id
                || $state->last_activity_at->gt($archive->activity_snapshot_at)) {
                throw new RuntimeException('Archive attempt was invalidated by newer server activity.');
            }
            // No existing Pelican conflict state accurately represents lifecycle
            // finalization. A second uncached read immediately before adoption
            // narrows the final status-to-delete window without misusing core state.
            $this->assertFreshOffline($server);
            $state->refresh();
            if ($state->status !== LifecycleStatus::Archiving
                || $state->current_archive_id !== $archive->id
                || $state->last_activity_at->gt($archive->activity_snapshot_at)) {
                throw new RuntimeException('Archive attempt changed during final status validation.');
            }
        } catch (Throwable $exception) {
            $this->failures->failBeforeAdoption(
                $archive,
                'Final archive revalidation failed; live server retained.',
                $backup,
            );
            throw $exception;
        }

        $this->adopter->handle($archive, $backup);
        $server->unsetRelation('backups')->refresh();

        try {
            $this->deletion->handle($server);
        } catch (Throwable $exception) {
            $archive->update([
                'status' => LifecycleStatus::ArchiveCreatedDeleteFailed,
                'last_error' => 'Native server deletion failed; archive retained.',
                'retry_count' => $archive->retry_count + 1,
                'retry_after' => now()->addMinutes(5),
            ]);
            $state->update([
                'status' => LifecycleStatus::ArchiveCreatedDeleteFailed,
                'last_error' => 'Native server deletion failed; archive retained.',
            ]);
            throw $exception;
        }

        $retention = data_get($archive->policy_snapshot, 'archive_retention_minutes');
        $archive->update([
            'status' => LifecycleStatus::Archived,
            'archived_at' => now(),
            'retention_expires_at' => $retention === null ? null : now()->addMinutes((int) $retention),
            'last_error' => null,
        ]);
    }

    private function assertFreshOffline(Server $server): void
    {
        if ($this->statuses->get($server) !== AuthoritativeServerState::Offline) {
            throw new RuntimeException('Fresh Wings status is not exactly Offline.');
        }
    }

    private function assertCompletedBackup(Backup $backup): void
    {
        if (! $backup->completed_at || ! $backup->is_successful || $backup->bytes < 1 || blank($backup->checksum)) {
            throw new RuntimeException('Backup completion verification failed.');
        }
    }

    /** @return array{Server, ServerLifecycleState} */
    private function revalidateAttempt(ServerArchive $archive, Backup $backup): array
    {
        $state = ServerLifecycleState::query()->where('server_id', $archive->original_server_id)->first();
        $server = Server::query()->find($archive->original_server_id);

        if (! $state || ! $server || (int) $backup->server_id !== (int) $server->id
            || $state->status !== LifecycleStatus::Archiving
            || $state->current_archive_id !== $archive->id
            || ! $archive->activity_snapshot_at
            || $state->last_activity_at->gt($archive->activity_snapshot_at)) {
            throw new RuntimeException('Archive attempt is no longer current.');
        }

        return [$server, $state];
    }
}
