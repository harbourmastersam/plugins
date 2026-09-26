<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use Throwable;

class MappingPreviewService
{
    public function __construct(private readonly UserAttributeRegistryContract $registry, private readonly UserAttributeService $attributes, private readonly MappingCandidateResolver $candidates) {}

    /** @param array<string, array<int, array<string, mixed>>> $groups @param array<string, mixed> $claims @return list<array<string, mixed>> */
    public function preview(array $groups, array $claims): array
    {
        $results = [];
        foreach ($groups as $rows) foreach ($rows as $row) {
            $chain = collect($row['mappings'] ?? [])->filter(fn (array $mapping) => ($mapping['enabled'] ?? false) && strlen((string) ($mapping['source_value'] ?? '')) > 0)
                ->sortBy([['priority', 'asc'], ['id', 'asc']])->values();
            if ($chain->isEmpty()) continue;
            $definition = $this->registry->get((string) $row['key']);
            $result = ['target' => $row['key'], 'label' => $row['label'], 'status' => 'Missing / preserve', 'source_type' => null, 'source' => null, 'value' => null, 'converted_type' => null, 'sensitive' => $definition?->sensitive ?? false];
            if ($definition === null || !$definition->writableFromIdentity) {
                $result['status'] = 'Unavailable';
                $results[] = $result;
                continue;
            }
            foreach ($chain as $mapping) {
                $type = MappingSourceType::tryFrom((string) ($mapping['source_type'] ?? '')) ?? MappingSourceType::Claim;
                try {
                    $candidate = $this->candidates->resolve($type, (string) $mapping['source_value'], $mapping['transforms'] ?? [], $claims);
                    if (!$candidate['present']) continue;
                    $result['source_type'] = ucfirst($type->value);
                    $result['source'] = $type === MappingSourceType::Claim ? $mapping['source_value'] : null;
                    $value = $this->attributes->convertAndValidate($definition->key, $candidate['value']);
                    $result['status'] = 'Resolved successfully';
                    $result['value'] = $definition->sensitive ? '[redacted]' : $value;
                    $result['converted_type'] = get_debug_type($value);
                } catch (Throwable $exception) {
                    $result['status'] = 'Invalid';
                    $result['error'] = $exception->getMessage();
                }
                break;
            }
            if ($result['source_type'] === null && $result['status'] !== 'Invalid' && (($chain->first()['missing_claim_behavior'] ?? 'preserve') === 'clear')) {
                $result['status'] = $definition->clearer === null ? 'Missing / preserve' : 'Would clear';
            }
            $results[] = $result;
        }
        return $results;
    }
}
