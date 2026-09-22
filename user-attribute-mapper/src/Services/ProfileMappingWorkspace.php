<?php

namespace Boy132\UserAttributeMapper\Services;

use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Models\AttributeMapping;
use Illuminate\Support\Facades\DB;

/** Builds and reconciles the target-driven state used by the profile mapping UI. */
class ProfileMappingWorkspace
{
    public function __construct(private readonly UserAttributeRegistryContract $registry) {}

    /** @return array{groups: array<string, array<int, array<string, mixed>>>, unavailable: array<int, array<string, mixed>>} */
    public function load(string $provider): array
    {
        $existing = AttributeMapping::query()->where('provider', $provider)->orderBy('priority')->orderBy('id')->get();
        $byTarget = $existing->groupBy('target_attribute');
        $groups = [];

        foreach ($this->registry->writableFromIdentity() as $definition) {
            $mappings = $byTarget->get($definition->key, collect())->map(fn (AttributeMapping $mapping) => $this->mappingState($mapping))->values()->all();
            $groups[$definition->group ?? $definition->owner][] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'type' => $definition->type->value,
                'description' => $definition->description,
                'mappings' => $mappings ?: [$this->emptyMappingState()],
            ];
        }

        $available = $this->registry->writableFromIdentity()->keys();
        $unavailable = $existing->reject(fn (AttributeMapping $mapping) => $available->contains($mapping->target_attribute))
            ->map(fn (AttributeMapping $mapping) => $this->mappingState($mapping) + ['target_attribute' => $mapping->target_attribute])
            ->values()->all();

        return compact('groups', 'unavailable');
    }

    /**
     * Multiple mappings for one target are deliberately retained: priority is part of the
     * runtime mapping semantics and the database only de-duplicates an identical triplet.
     *
     * @param array<string, array<int, array<string, mixed>>> $groups
     * @param array<int, array<string, mixed>> $unavailable
     */
    public function save(string $provider, array $groups, array $unavailable): void
    {
        DB::transaction(function () use ($provider, $groups, $unavailable): void {
            $existing = AttributeMapping::query()->where('provider', $provider)->get()->keyBy('id');
            $kept = [];

            foreach ($groups as $rows) {
                foreach ($rows as $row) {
                    $definition = $this->registry->get((string) ($row['key'] ?? ''));
                    if (!$definition?->writableFromIdentity) continue;

                    foreach (($row['mappings'] ?? []) as $state) {
                        $source = trim((string) ($state['source_claim'] ?? ''));
                        $id = isset($state['id']) ? (int) $state['id'] : null;
                        $mapping = $id ? $existing->get($id) : null;
                        if ($mapping && $mapping->target_attribute !== $definition->key) continue;
                        if ($source === '') continue;

                        $values = $this->values($state, $provider, $definition->key, $source);
                        if ($mapping) {
                            $mapping->fill($values)->save();
                        } else {
                            $mapping = AttributeMapping::query()->create($values);
                        }
                        $kept[] = $mapping->id;
                    }
                }
            }

            // Historical targets can be edited or removed, but never created from this page.
            foreach ($unavailable as $state) {
                $id = isset($state['id']) ? (int) $state['id'] : 0;
                $mapping = $existing->get($id);
                if (!$mapping || $this->registry->get($mapping->target_attribute)?->writableFromIdentity) continue;
                $source = trim((string) ($state['source_claim'] ?? ''));
                if ($source === '') continue;
                $mapping->fill($this->values($state, $provider, $mapping->target_attribute, $source))->save();
                $kept[] = $mapping->id;
            }

            $existing->except($kept)->each->delete();
        });
    }

    /** @return array<string, mixed> */
    public function emptyMappingState(): array
    {
        return ['id' => null, 'source_claim' => '', 'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100, 'description' => ''];
    }

    /** @return array<string, mixed> */
    private function mappingState(AttributeMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'source_claim' => $mapping->source_claim,
            'enabled' => $mapping->enabled,
            'missing_claim_behavior' => $mapping->missing_claim_behavior->value,
            'priority' => $mapping->priority,
            'description' => $mapping->description ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function values(array $state, string $provider, string $target, string $source): array
    {
        return [
            'provider' => $provider,
            'source_claim' => $source,
            'target_attribute' => $target,
            'enabled' => (bool) ($state['enabled'] ?? false),
            'missing_claim_behavior' => ($state['missing_claim_behavior'] ?? 'preserve') === 'clear' ? 'clear' : 'preserve',
            'priority' => max(0, (int) ($state['priority'] ?? 100)),
            'description' => filled($state['description'] ?? null) ? (string) $state['description'] : null,
        ];
    }
}
