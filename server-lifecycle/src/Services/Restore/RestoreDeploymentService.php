<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Models\Allocation;
use App\Models\Objects\DeploymentObject;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use Illuminate\Support\Collection;
use RuntimeException;

class RestoreDeploymentService
{
    public function plan(ServerArchive $archive): RestoreDeploymentPlan
    {
        $requested = collect($archive->original_allocations ?? []);
        if ($requested->isEmpty()) {
            throw new RuntimeException('The archive has no allocation manifest.');
        }

        $selected = collect();
        $changes = [];
        foreach ($requested as $original) {
            $allocation = $this->findOriginal((string) $original['ip'], (int) $original['port'], $selected)
                ?? $this->findReplacement((int) $original['port'], $selected);

            if (! $allocation) {
                throw new RuntimeException('Insufficient free allocations are available for this restore.');
            }

            $selected->push($allocation);
            if ($allocation->ip !== $original['ip'] || (int) $allocation->port !== (int) $original['port']) {
                $changes[] = [
                    'from_ip' => $original['ip'],
                    'from_port' => (int) $original['port'],
                    'to_ip' => $allocation->ip,
                    'to_port' => (int) $allocation->port,
                ];
            }
        }

        /** @var Allocation $primary */
        $primary = $selected->first();
        $deployment = new DeploymentObject();
        $deployment->setDedicated(false);
        $deployment->setPorts($selected->pluck('port')->map(fn ($port): string => (string) $port)->all());

        return new RestoreDeploymentPlan(
            $deployment,
            $primary->id,
            $selected->skip(1)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $changes,
        );
    }

    private function findOriginal(string $ip, int $port, Collection $selected): ?Allocation
    {
        return Allocation::query()
            ->whereNull('server_id')
            ->where('ip', $ip)
            ->where('port', $port)
            ->whereNotIn('id', $selected->pluck('id'))
            ->first();
    }

    private function findReplacement(int $preferredPort, Collection $selected): ?Allocation
    {
        return Allocation::query()
            ->whereNull('server_id')
            ->whereNotIn('id', $selected->pluck('id'))
            ->orderByRaw('CASE WHEN port = ? THEN 0 ELSE 1 END', [$preferredPort])
            ->orderBy('id')
            ->first();
    }
}
