<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Models\Objects\DeploymentObject;

final readonly class RestoreDeploymentPlan
{
    /**
     * @param array<int, int> $additionalAllocationIds
     * @param array<int, array<string, int|string>> $portChanges
     */
    public function __construct(
        public DeploymentObject $deployment,
        public int $primaryAllocationId,
        public array $additionalAllocationIds,
        public array $portChanges,
    ) {}
}
