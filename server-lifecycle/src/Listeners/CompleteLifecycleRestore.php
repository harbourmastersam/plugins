<?php

namespace HarbourmasterSam\ServerLifecycle\Listeners;

use App\Events\ActivityLogged;
use App\Models\Backup;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;

class CompleteLifecycleRestore
{
    private const SUCCESS_EVENTS = [
        'server:backup.restore-complete',
    ];

    private const FAILURE_EVENTS = [
        'server.backup.restore-failed',
    ];

    public function handle(ActivityLogged $event): void
    {
        $activity = $event->model->loadMissing('subjects.subject');
        if (! in_array($activity->event, [...self::SUCCESS_EVENTS, ...self::FAILURE_EVENTS], true)) {
            return;
        }

        $backup = $activity->subjects
            ->first(fn ($subject) => $subject->subject instanceof Backup)
            ?->subject;
        if (! $backup) {
            return;
        }

        $archive = ServerArchive::query()->where('restore_backup_id', $backup->id)->first();
        if (! $archive) {
            return;
        }

        $server = Server::query()->find($archive->restored_server_id);
        Backup::query()->whereKey($backup->id)->delete();

        if (in_array($activity->event, self::SUCCESS_EVENTS, true)) {
            $archive->update([
                'status' => LifecycleStatus::Restored,
                'restore_backup_id' => null,
                'restored_at' => now(),
                'last_error' => null,
            ]);
            if ($server) {
                ServerLifecycleState::query()->updateOrCreate(
                    ['server_id' => $server->id],
                    [
                        'automatic_enabled' => false,
                        'last_activity_at' => now(),
                        'last_activity_event' => $activity->event,
                        'archive_due_at' => null,
                        'status' => LifecycleStatus::Active,
                        'last_error' => null,
                    ],
                );
            }

            return;
        }

        $server?->update(['status' => null]);
        $archive->update([
            'status' => LifecycleStatus::RestoreFailed,
            'restore_backup_id' => null,
            'last_error' => 'Wings restore failed; archive retained.',
        ]);
    }
}
