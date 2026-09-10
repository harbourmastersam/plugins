<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Policy;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use Illuminate\Support\Carbon;

class ConfigureServerLifecycleService
{
    public function handle(
        Server $server,
        ?LifecyclePolicy $policy,
        bool $automaticEnabled,
        bool $isExempt = false,
        ?Carbon $exemptUntil = null,
    ): ServerLifecycleState {
        $state = ServerLifecycleState::query()->firstOrCreate(
            ['server_id' => $server->id],
            ['last_activity_at' => now(), 'automatic_enabled' => false, 'status' => LifecycleStatus::Active],
        );
        $effective = $policy ?? LifecyclePolicy::query()->where('enabled', true)->where('is_default', true)->first();
        $dueAt = $automaticEnabled && ! $isExempt && $effective?->inactivity_minutes !== null
            ? $state->last_activity_at->addMinutes($effective->inactivity_minutes)
            : null;

        $state->update([
            'policy_id' => $policy?->id,
            'automatic_enabled' => $automaticEnabled,
            'is_exempt' => $isExempt,
            'exempt_until' => $isExempt ? null : $exemptUntil,
            'archive_due_at' => $dueAt,
        ]);

        return $state->refresh();
    }
}
