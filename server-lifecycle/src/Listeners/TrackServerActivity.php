<?php

namespace HarbourmasterSam\ServerLifecycle\Listeners;

use App\Events\ActivityLogged;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Activity\MeaningfulActivity;
use Illuminate\Support\Facades\DB;

class TrackServerActivity
{
    public function __construct(private MeaningfulActivity $meaningful) {}

    public function handle(ActivityLogged $event): void
    {
        $activity = $event->model->loadMissing('subjects.subject');
        if (! $this->meaningful->includes((string) $activity->event)) {
            return;
        }

        $server = $activity->subjects
            ->first(fn ($subject) => $subject->subject instanceof Server)
            ?->subject;
        if (! $server) {
            return;
        }

        DB::transaction(function () use ($server, $activity): void {
            $state = ServerLifecycleState::query()->where('server_id', $server->id)->lockForUpdate()->first();
            if (! $state) {
                $state = ServerLifecycleState::query()->create([
                    'server_id' => $server->id,
                    'last_activity_at' => now(),
                    'automatic_enabled' => false,
                    'status' => LifecycleStatus::Active,
                ]);
            }

            if (in_array($state->status, [LifecycleStatus::Archiving, LifecycleStatus::ArchiveCreatedDeleteFailed], true) && $state->current_archive_id) {
                $archive = ServerArchive::query()->find($state->current_archive_id);
                $superseded = $state->status === LifecycleStatus::ArchiveCreatedDeleteFailed;
                $retention = $archive ? data_get($archive->policy_snapshot, 'archive_retention_minutes') : null;
                $archive?->update([
                    'status' => $superseded ? LifecycleStatus::ArchiveSuperseded : LifecycleStatus::ArchiveCancelled,
                    'archived_at' => $superseded ? ($archive->archived_at ?? now()) : $archive->archived_at,
                    'retention_expires_at' => $superseded && ! $archive->retention_expires_at && $retention !== null
                        ? now()->addMinutes((int) $retention)
                        : $archive->retention_expires_at,
                    'last_error' => $superseded
                        ? 'Adopted archive retained as historical because the live server became active.'
                        : 'Archive cancelled because meaningful activity occurred after it started.',
                ]);
            }

            $policy = $state->policy
                ?: LifecyclePolicy::query()->where('enabled', true)->where('is_default', true)->first();
            $activityAt = now();
            $state->update([
                'last_activity_at' => $activityAt,
                'last_activity_event' => $activity->event,
                'archive_due_at' => $policy?->inactivity_minutes === null
                    ? null
                    : $activityAt->copy()->addMinutes($policy->inactivity_minutes),
                'status' => LifecycleStatus::Active,
                'current_archive_id' => null,
                'last_error' => null,
            ]);
        });
    }
}
