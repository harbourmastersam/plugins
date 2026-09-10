<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Models\Server;
use RuntimeException;

class RestoreAllocationValidationService
{
    public function assertAssigned(Server $server, RestoreDeploymentPlan $plan): void
    {
        $server->refresh()->load('allocations');
        $expected = collect([$plan->primaryAllocationId, ...$plan->additionalAllocationIds])->sort()->values();
        $actual = $server->allocations->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values();

        if ((int) $server->allocation_id !== $plan->primaryAllocationId
            || (int) $server->node_id !== $plan->nodeId
            || $actual->count() !== $expected->count()
            || $actual->all() !== $expected->all()
            || $server->allocations->contains(fn ($allocation): bool =>
                (int) $allocation->server_id !== (int) $server->id
                || (int) $allocation->node_id !== $plan->nodeId)) {
            throw new RuntimeException('Pelican did not assign the complete restore allocation plan.');
        }
    }
}
