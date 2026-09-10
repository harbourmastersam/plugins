<?php

use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Restore\RestoreDeploymentPlan;
use HarbourmasterSam\ServerLifecycle\Services\Restore\ServerCreationPayloadService;
use Mockery as M;

it('preserves docker labels and the authoritative allocation plan', function (): void {
    $manifest = [
        'name' => 'Test', 'description' => '', 'egg_id' => 2, 'image' => 'image', 'startup' => './start',
        'environment' => [], 'cpu' => 100, 'memory' => 1024, 'swap' => 0, 'disk' => 2048, 'io' => 500,
        'threads' => null, 'oom_killer' => false, 'database_limit' => 0, 'allocation_limit' => 2,
        'backup_limit' => 1, 'docker_labels' => ['example' => 'value'],
    ];
    $archive = M::mock(ServerArchive::class)->makePartial();
    $archive->shouldReceive('getAttribute')->with('manifest')->andReturn($manifest);
    $archive->shouldReceive('getAttribute')->with('owner_id')->andReturn(7);
    $plan = new RestoreDeploymentPlan(4, 10, [11, 12], []);

    $payload = (new ServerCreationPayloadService())->build($archive, $plan);

    expect($payload)->toMatchArray([
        'node_id' => 4,
        'allocation_id' => 10,
        'allocation_additional' => [11, 12],
        'docker_labels' => ['example' => 'value'],
        'start_on_completion' => false,
    ]);
});
