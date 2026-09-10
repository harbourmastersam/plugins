<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Restore;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Objects\DeploymentObject;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use Illuminate\Support\Collection;
use RuntimeException;

class RestoreDeploymentService
{
    public function plan(ServerArchive $archive): RestoreDeploymentPlan
    {
        $manifest = $archive->manifest;
        $requested = collect($manifest['allocations'] ?? $archive->original_allocations ?? [])
            ->map(fn (array $allocation): array => $allocation + [
                'primary' => isset($manifest['allocation_id'])
                    && (int) $allocation['id'] === (int) $manifest['allocation_id'],
            ])
            ->sortByDesc('primary')
            ->values();

        if ($requested->isEmpty() || ! $requested->contains('primary', true)) {
            throw new RuntimeException('The archive does not identify its original primary allocation.');
        }

        foreach ($this->candidateNodeIds($archive, $requested->count()) as $nodeId) {
            $selected = $this->selectOnNode($nodeId, $requested);
            if ($selected === null) {
                continue;
            }

            return $this->makePlan($selected, $requested);
        }

        throw new RuntimeException('No single eligible node has sufficient free allocations for this restore.');
    }

    /** @return Collection<int, int> */
    private function candidateNodeIds(ServerArchive $archive, int $required): Collection
    {
        $originalNodeId = (int) data_get($archive->original_node, 'id');
        $eligibleNodes = Node::query()
            ->where('maintenance_mode', false)
            ->pluck('id');
        $available = Allocation::query()
            ->whereNull('server_id')
            ->whereIn('node_id', $eligibleNodes)
            ->selectRaw('node_id, COUNT(*) AS allocation_count')
            ->groupBy('node_id')
            ->having('allocation_count', '>=', $required)
            ->pluck('node_id');

        return $available
            ->sortByDesc(fn ($nodeId): bool => (int) $nodeId === $originalNodeId)
            ->map(fn ($nodeId): int => (int) $nodeId)
            ->values();
    }

    /** @return Collection<int, Allocation>|null */
    private function selectOnNode(int $nodeId, Collection $requested): ?Collection
    {
        $available = Allocation::query()
            ->where('node_id', $nodeId)
            ->whereNull('server_id')
            ->get();
        $selected = collect();

        foreach ($requested as $original) {
            $allocation = $available->first(fn (Allocation $item): bool =>
                ! $selected->contains('id', $item->id)
                && $item->ip === $original['ip']
                && (int) $item->port === (int) $original['port'])
                ?? $available->first(fn (Allocation $item): bool =>
                    ! $selected->contains('id', $item->id)
                    && (int) $item->port === (int) $original['port'])
                ?? $available->first(fn (Allocation $item): bool => ! $selected->contains('id', $item->id));

            if (! $allocation) {
                return null;
            }
            $selected->push($allocation);
        }

        return $selected;
    }

    private function makePlan(Collection $selected, Collection $requested): RestoreDeploymentPlan
    {
        /** @var Allocation $primary */
        $primary = $selected->first();
        $changes = $selected->values()->map(function (Allocation $allocation, int $index) use ($requested): ?array {
            $original = $requested[$index];
            if ($allocation->ip === $original['ip'] && (int) $allocation->port === (int) $original['port']) {
                return null;
            }

            return [
                'from_ip' => $original['ip'],
                'from_port' => (int) $original['port'],
                'to_ip' => $allocation->ip,
                'to_port' => (int) $allocation->port,
            ];
        })->filter()->values()->all();

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
}
