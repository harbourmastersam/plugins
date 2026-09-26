<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Builds and reconciles the target-driven state used by the profile mapping UI. */
class ProfileMappingWorkspace
{
    public function __construct(private readonly UserAttributeRegistryContract $registry, private readonly ?UserAttributeService $attributes = null, private readonly ?AttributeTransformationService $transformations = null, private readonly ?MappingAuditService $audit = null) {}

    /** @return array{groups: array<string, array<int, array<string, mixed>>>, unavailable: array<int, array<string, mixed>>} */
    public function load(string $provider): array
    {
        $this->ensureSchemaIsCurrent();
        $existing = AttributeMapping::query()->where('provider', $provider)->orderBy('priority')->orderBy('id')->get();
        $byTarget = $existing->groupBy('target_attribute');
        $groups = [];

        foreach ($this->registry->writableFromIdentity() as $definition) {
            $mappings = $byTarget->get($definition->key, collect())->map(fn (AttributeMapping $mapping) => $this->mappingState($mapping))->values()->all();
            $groups[$definition->group ?? $definition->owner][] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'type' => $definition->type->value,
                'sensitive' => $definition->sensitive,
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
        $this->ensureSchemaIsCurrent();

        if ($provider === '' || $provider === '*') {
            throw new InvalidArgumentException('Mappings must belong to a configured identity provider.');
        }

        $this->validate($groups);
        DB::transaction(function () use ($provider, $groups, $unavailable): void {
            $existing = AttributeMapping::query()->where('provider', $provider)->get()->keyBy('id');
            $kept = [];
            $changes = [];

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
                            $operation = $mapping->enabled !== $values['enabled'] ? ($values['enabled'] ? 'enabled' : 'disabled') : 'modified';
                            if ($mapping->only(array_keys($values)) !== $values) $changes[] = $this->auditChange($operation, $mapping->id, $definition->key, $sourceType, $sourceType === MappingSourceType::Claim ? $source : null);
                            $mapping->fill($values)->save();
                        } else {
                            $mapping = AttributeMapping::query()->create($values);
                            $changes[] = $this->auditChange('created', $mapping->id, $definition->key, $sourceType, $sourceType === MappingSourceType::Claim ? $source : null);
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

            $existing->except($kept)->each(function (AttributeMapping $mapping) use (&$changes): void {
                $changes[] = $this->auditChange('removed', $mapping->id, $mapping->target_attribute, $mapping->source_type, $mapping->source_type === MappingSourceType::Claim ? $mapping->source_value : null);
                $mapping->delete();
            });
            ($this->audit ?? new MappingAuditService())->record($provider, $changes, auth()->id());
        });
    }

    /** @return array<string, mixed> */
    public function emptyMappingState(): array
    {
        return ['id' => null, '_ui_key' => (string) Str::uuid(), 'source_type' => MappingSourceType::Claim->value, 'source_value' => '', 'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100, 'description' => '', 'transforms' => []];
    }

    /** @return array<string, mixed> */
    private function mappingState(AttributeMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            '_ui_key' => 'mapping-'.$mapping->id,
            'source_type' => $mapping->source_type->value,
            'source_value' => $mapping->source_value,
            'enabled' => $mapping->enabled,
            'missing_claim_behavior' => $mapping->missing_claim_behavior->value,
            'priority' => $mapping->priority,
            'description' => $mapping->description ?? '',
            'transforms' => collect($mapping->transforms ?? [])->map(fn (array $transform): array => $transform + ['_ui_key' => (string) Str::uuid()])->all(),
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
            'transforms' => $this->persistableTransforms($state['transforms'] ?? []),
        ];
    }

    /** @param array<string, array<int, array<string, mixed>>> $groups */
    private function validate(array $groups): void
    {
        $attributes = $this->attributes ?? new UserAttributeService($this->registry, new AttributeValueConverter());
        $transformations = $this->transformations ?? new AttributeTransformationService();
        foreach ($groups as $rows) foreach ($rows as $row) {
            $definition = $this->registry->get((string) ($row['key'] ?? ''));
            if (!$definition?->writableFromIdentity) continue;
            foreach (($row['mappings'] ?? []) as $state) {
                $sourceType = MappingSourceType::tryFrom((string) ($state['source_type'] ?? '')) ?? MappingSourceType::Claim;
                $source = (string) ($state['source_value'] ?? '');
                if ($source === '') continue;
                $transforms = $this->persistableTransforms($state['transforms'] ?? []);
                if ($transforms !== [] && $definition->type !== AttributeType::String) {
                    throw new InvalidArgumentException('Transformations are supported only for string targets.');
                }
                $transformations->validate($transforms);
                if ($sourceType === MappingSourceType::Static) {
                    if (in_array($definition->type, [AttributeType::Array, AttributeType::Object], true)) {
                        throw new InvalidArgumentException('Static values are not currently supported for array/object targets. Use an identity provider claim.');
                    }
                    $attributes->convertAndValidate($definition->key, $transformations->transform($source, $transforms));
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $transforms */
    private function persistableTransforms(array $transforms): array
    {
        return array_values(array_map(function (array $transform): array {
            unset($transform['_ui_key']);

            return $transform;
        }, $transforms));
    }

    /** Fail with an actionable message rather than an opaque query error after an incomplete update. */
    public function ensureSchemaIsCurrent(): void
    {
        if (!Schema::hasTable('user_attribute_mappings') || !Schema::hasColumn('user_attribute_mappings', 'transforms') || !Schema::hasTable('user_attribute_mapping_audits')) {
            throw new InvalidArgumentException('User Attribute Mapper database schema is out of date (migrations 003 and 004 are required). Re-run the plugin update/install process to apply plugin migrations.');
        }
    }

    /** @return array<string, mixed> */
    private function auditChange(string $operation, int $id, string $target, MappingSourceType $sourceType, ?string $claimPath): array
    {
        return array_filter(['operation' => $operation, 'mapping_id' => $id, 'target_attribute' => $target, 'source_type' => $sourceType->value, 'claim_path' => $claimPath], fn (mixed $value) => $value !== null);
    }
}
