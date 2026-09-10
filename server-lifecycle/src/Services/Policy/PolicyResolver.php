<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Policy;

use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;

class PolicyResolver
{
    public function resolve(ServerLifecycleState $state): ?LifecyclePolicy
    {
        return $state->policy()->where('enabled', true)->first() ?? LifecyclePolicy::query()->where('enabled', true)->where('is_default', true)->first();
    }
}
