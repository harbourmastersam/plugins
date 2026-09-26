<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use HarbourmasterSam\UserAttributeMapper\Models\DiscoveredClaim;
use Illuminate\Support\Facades\Schema;

class SamplePayloadBuilder
{
    public function __construct(private readonly UserAttributeRegistryContract $registry, private readonly SensitiveClaimPolicy $sensitive) {}

    /** @param array<string, array<int, array<string, mixed>>> $groups @return array{payload: array<string, mixed>, warning: ?string} */
    public function build(string $provider, array $groups, bool $includeAllDiscovered = false): array
    {
        $types = Schema::hasTable('user_attribute_discovered_claims')
            ? DiscoveredClaim::query()->where('provider', $provider)->pluck('claim_type', 'claim_path')->all()
            : [];
        $entries = [];
        foreach ($groups as $rows) foreach ($rows as $row) foreach (($row['mappings'] ?? []) as $mapping) {
            if (($mapping['source_type'] ?? 'claim') !== MappingSourceType::Claim->value) continue;
            $path = trim((string) ($mapping['source_value'] ?? ''));
            if ($path === '' || $this->sensitive->isSensitivePath($path)) continue;
            $target = (string) ($row['key'] ?? '');
            $entries[$path] = $this->sampleValue($target, $types[$path] ?? null);
        }
        if ($includeAllDiscovered) foreach ($types as $path => $type) {
            $hasChild = collect(array_keys($types))->contains(fn (string $candidate): bool => str_starts_with($candidate, $path.'.'));
            if (!isset($entries[$path]) && !($type === 'object' && $hasChild) && !$this->sensitive->isSensitivePath($path)) $entries[$path] = $this->sampleValue('', $type);
        }

        $payload = [];
        foreach ($entries as $path => $value) {
            $conflict = $this->insert($payload, $path, $value);
            if ($conflict !== null) return ['payload' => $payload, 'warning' => "Cannot generate sample payload because claim paths [{$conflict[0]}] and [{$conflict[1]}] conflict."];
        }
        return ['payload' => $payload, 'warning' => null];
    }

    private function sampleValue(string $target, ?string $observedType): mixed
    {
        $known = ['pelican.username' => 'sam', 'pelican.email' => 'sam@example.com', 'pelican.external_id' => 'example-external-id', 'pelican.language' => 'en', 'pelican.timezone' => 'UTC'];
        if (array_key_exists($target, $known)) return $known[$target];
        $type = $this->registry->get($target)?->type->value ?? $observedType ?? AttributeType::String->value;
        return match ($type) {
            'boolean' => true, 'integer' => 1, 'float' => 1.0, 'array' => [], 'object' => [], default => 'example',
        };
    }

    /** @param array<string, mixed> $payload @return ?array{string, string} */
    private function insert(array &$payload, string $path, mixed $value): ?array
    {
        $segments = explode('.', $path);
        $cursor = &$payload;
        $built = [];
        foreach ($segments as $index => $segment) {
            $built[] = $segment;
            $current = implode('.', $built);
            $last = $index === count($segments) - 1;
            if ($last) {
                if (array_key_exists($segment, $cursor) && is_array($cursor[$segment])) return [$current, $path];
                $cursor[$segment] = $value;
                return null;
            }
            if (array_key_exists($segment, $cursor) && !is_array($cursor[$segment])) return [$current, $path];
            $cursor[$segment] ??= [];
            $cursor = &$cursor[$segment];
        }
        return null;
    }
}
