<?php

namespace HarbourmasterSam\ServerLifecycle\Listeners;

use App\Events\ActivityLogged;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Activity\MeaningfulActivity;

class TrackServerActivity
{
    public function __construct(private MeaningfulActivity $meaningful) {}
    public function handle(ActivityLogged $event): void
    {
        $activity = $event->activity;
        if (! $this->meaningful->includes((string) $activity->event)) return;
        $server = $activity->subjects->first(fn ($subject) => $subject instanceof Server);
        if (! $server) return;
        $state = ServerLifecycleState::query()->firstOrCreate(['server_id' => $server->id], ['last_activity_at' => now(), 'automatic_enabled' => false, 'status' => LifecycleStatus::Active]);
        $policy = $state->policy ?: \HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy::query()->where('enabled', true)->where('is_default', true)->first();
        $state->update(['last_activity_at' => now(), 'last_activity_event' => $activity->event, 'archive_due_at' => $policy?->inactivity_minutes === null ? null : now()->addMinutes($policy->inactivity_minutes), 'status' => LifecycleStatus::Active, 'last_error' => null]);
    }
}
