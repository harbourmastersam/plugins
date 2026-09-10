<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

final readonly class RestoreDeploymentPlan
{
    /**
     * @param array<int, int> $additionalAllocationIds
     * @param array<int, array<string, int|string>> $portChanges
     */
    public function __construct(
        public int $nodeId,
        public int $primaryAllocationId,
        public array $additionalAllocationIds,
        public array $portChanges,
    ) {}
}
