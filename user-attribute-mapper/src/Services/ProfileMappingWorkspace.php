<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
                'nullable' => $definition->nullable,
                'clear_supported' => $definition->clearer !== null,
                'owner' => $definition->owner,
                'group' => $definition->group ?? $definition->owner,
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
        if ($provider === '' || $provider === '*') {
            throw new InvalidArgumentException('Mappings must belong to a configured identity provider.');
        }

        DB::transaction(function () use ($provider, $groups, $unavailable): void {
            $existing = AttributeMapping::query()->where('provider', $provider)->get()->keyBy('id');
            $kept = [];

            foreach ($groups as $rows) {
                foreach ($rows as $row) {
                    $definition = $this->registry->get((string) ($row['key'] ?? ''));
                    if (!$definition?->writableFromIdentity) continue;

                    foreach (($row['mappings'] ?? []) as $state) {
                        $sourceType = MappingSourceType::tryFrom((string) ($state['source_type'] ?? '')) ?? MappingSourceType::Claim;
                        $source = (string) ($state['source_value'] ?? '');
                        if ($sourceType === MappingSourceType::Claim) $source = trim($source);
                        $id = isset($state['id']) ? (int) $state['id'] : null;
                        $mapping = $id ? $existing->get($id) : null;
                        if ($mapping && $mapping->target_attribute !== $definition->key) continue;
                        if (strlen($source) === 0) continue;

                        $values = $this->values($state, $provider, $definition->key, $sourceType, $source);
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
                $sourceType = MappingSourceType::tryFrom((string) ($state['source_type'] ?? '')) ?? MappingSourceType::Claim;
                $source = (string) ($state['source_value'] ?? '');
                if ($sourceType === MappingSourceType::Claim) $source = trim($source);
                if (strlen($source) === 0) continue;
                $mapping->fill($this->values($state, $provider, $mapping->target_attribute, $sourceType, $source))->save();
                $kept[] = $mapping->id;
            }

            $existing->except($kept)->each->delete();
        });
    }

    /** @return array<string, mixed> */
    public function emptyMappingState(): array
    {
        return ['id' => null, 'source_type' => MappingSourceType::Claim->value, 'source_value' => '', 'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100, 'description' => ''];
    }

    /** @return array<string, mixed> */
    private function mappingState(AttributeMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'source_type' => $mapping->source_type->value,
            'source_value' => $mapping->source_value,
            'enabled' => $mapping->enabled,
            'missing_claim_behavior' => $mapping->missing_claim_behavior->value,
            'priority' => $mapping->priority,
            'description' => $mapping->description ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function values(array $state, string $provider, string $target, MappingSourceType $sourceType, string $source): array
    {
        return [
            'provider' => $provider,
            'source_type' => $sourceType->value,
            'source_value' => $source,
            'target_attribute' => $target,
            'enabled' => (bool) ($state['enabled'] ?? false),
            'missing_claim_behavior' => ($state['missing_claim_behavior'] ?? 'preserve') === 'clear' ? 'clear' : 'preserve',
            'priority' => max(0, (int) ($state['priority'] ?? 100)),
            'description' => filled($state['description'] ?? null) ? (string) $state['description'] : null,
        ];
    }
}
