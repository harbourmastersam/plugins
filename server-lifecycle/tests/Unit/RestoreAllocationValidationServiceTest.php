<?php

use App\Models\Allocation;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Services\Restore\RestoreAllocationValidationService;
use HarbourmasterSam\ServerLifecycle\Services\Restore\RestoreDeploymentPlan;
use Illuminate\Database\Eloquent\Collection;
use Mockery as M;

function restoredServer(array $allocationIds): Server
{
    $server = M::mock(Server::class)->makePartial();
    $server->forceFill(['id' => 99, 'node_id' => 4, 'allocation_id' => $allocationIds[0]]);
    $allocations = collect($allocationIds)->map(function (int $id): Allocation {
        $allocation = new Allocation();
        $allocation->forceFill(['id' => $id, 'node_id' => 4, 'server_id' => 99]);

        return $allocation;
    });
    $server->setRelation('allocations', new Collection($allocations));
    $server->shouldReceive('refresh')->once()->andReturnSelf();
    $server->shouldReceive('load')->once()->with('allocations')->andReturnSelf();

    return $server;
}

it('accepts the exact complete same-node allocation plan', function (): void {
    $plan = new RestoreDeploymentPlan(4, 10, [11, 12], []);

    (new RestoreAllocationValidationService())->assertAssigned(restoredServer([10, 11, 12]), $plan);

    expect(true)->toBeTrue();
});

it('rejects a restore when an additional allocation was lost to a race', function (): void {
    $plan = new RestoreDeploymentPlan(4, 10, [11, 12], []);

    expect(fn () => (new RestoreAllocationValidationService())->assertAssigned(restoredServer([10, 11]), $plan))
        ->toThrow(\RuntimeException::class, 'complete restore allocation plan');
});

it('rejects a primary allocation selected on a different node', function (): void {
    $plan = new RestoreDeploymentPlan(7, 10, [11], []);

    expect(fn () => (new RestoreAllocationValidationService())->assertAssigned(restoredServer([10, 11]), $plan))
        ->toThrow(\RuntimeException::class);
});
