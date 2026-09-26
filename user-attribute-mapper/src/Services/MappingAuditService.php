<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Models\AttributeMappingAudit;

class MappingAuditService
{
    /** @param list<array<string, mixed>> $changes */
    public function record(string $provider, array $changes, ?int $actorId = null, string $action = 'mappings_updated'): void
    {
        if ($changes === []) return;
        AttributeMappingAudit::query()->create(compact('provider', 'action', 'changes') + ['actor_id' => $actorId]);
    }
}
