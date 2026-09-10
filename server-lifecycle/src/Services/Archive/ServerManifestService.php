<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;

class ServerManifestService
{
    public function capture(Server $server): array
    {
        $server->loadMissing(['allocations', 'variables', 'egg', 'node']);
        return [
            'version' => 1,
            'owner_id' => $server->owner_id, 'name' => $server->name, 'description' => $server->description,
            'external_id' => $server->external_id, 'egg_id' => $server->egg_id, 'image' => $server->image,
            'startup' => $server->startup, 'cpu' => $server->cpu, 'memory' => $server->memory, 'swap' => $server->swap,
            'disk' => $server->disk, 'io' => $server->io, 'threads' => $server->threads, 'oom_disabled' => $server->oom_disabled,
            'database_limit' => $server->database_limit, 'allocation_limit' => $server->allocation_limit, 'backup_limit' => $server->backup_limit,
            'docker_labels' => $server->docker_labels,
            'environment' => $server->variables->mapWithKeys(fn ($variable) => [$variable->env_variable => $variable->server_value ?? $variable->default_value])->all(),
            'node_id' => $server->node_id, 'allocation_id' => $server->allocation_id,
            'allocations' => $server->allocations->map(fn ($allocation) => ['id' => $allocation->id, 'ip' => $allocation->ip, 'port' => $allocation->port])->all(),
        ];
    }
}
