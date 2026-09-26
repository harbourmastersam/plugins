<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Enums\DiscoveredClaimType;
use HarbourmasterSam\UserAttributeMapper\Models\DiscoveredClaim;
use Illuminate\Support\Facades\DB;

class ClaimSchemaDiscoveryService
{
    public function __construct(private readonly SensitiveClaimPolicy $sensitive) {}

    /** @param array<string, mixed> $claims @return array<string, string> */
    public function flatten(array $claims): array
    {
        $paths = [];
        $this->walk($this->sensitive->filter($claims), '', $paths);
        return $paths;
    }

    /** @param array<string, mixed> $claims */
    public function discover(string $provider, array $claims): void
    {
        if ($provider === '' || !config('user-attribute-mapper.claim_discovery', true)) return;
        $now = now();
        DB::transaction(function () use ($provider, $claims, $now): void {
            foreach ($this->flatten($claims) as $path => $type) {
                $existing = DiscoveredClaim::query()->where('provider', $provider)->where('claim_path', $path)->lockForUpdate()->first();
                if (!$existing) {
                    DiscoveredClaim::query()->create(['provider' => $provider, 'claim_path' => $path, 'claim_type' => $type, 'first_seen_at' => $now, 'last_seen_at' => $now, 'observation_count' => 1]);
                    continue;
                }
                $existing->claim_type = $this->mergeTypes($existing->claim_type, $type);
                $existing->last_seen_at = $now;
                $existing->observation_count++;
                $existing->save();
            }
        });
    }

    private function mergeTypes(string $old, string $new): string
    {
        if ($new === 'null') return $old;
        if ($old === 'null') return $new;
        return $old === $new ? $old : 'mixed';
    }

    /** @param array<string, mixed> $values @param array<string, string> $paths */
    private function walk(array $values, string $prefix, array &$paths): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if ($this->sensitive->isSensitivePath($path)) continue;
            $type = $this->typeOf($value);
            $paths[$path] = $type->value;
            if ($type === DiscoveredClaimType::Object) $this->walk($value, $path, $paths);
        }
    }

    private function typeOf(mixed $value): DiscoveredClaimType
    {
        return match (true) {
            $value === null => DiscoveredClaimType::Null,
            is_string($value) => DiscoveredClaimType::String,
            is_int($value) => DiscoveredClaimType::Integer,
            is_float($value) => DiscoveredClaimType::Float,
            is_bool($value) => DiscoveredClaimType::Boolean,
            is_array($value) && array_is_list($value) => DiscoveredClaimType::Array,
            is_array($value) => DiscoveredClaimType::Object,
            default => DiscoveredClaimType::Mixed,
        };
    }
}
