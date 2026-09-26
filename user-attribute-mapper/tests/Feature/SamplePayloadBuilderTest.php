<?php

use HarbourmasterSam\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use HarbourmasterSam\UserAttributeMapper\Models\DiscoveredClaim;
use HarbourmasterSam\UserAttributeMapper\Services\SamplePayloadBuilder;
use HarbourmasterSam\UserAttributeMapper\Services\SensitiveClaimPolicy;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sampleBuilder(): SamplePayloadBuilder
{
    $registry = new UserAttributeRegistry();
    (new PelicanUserAttributeProvider())->register($registry);
    return new SamplePayloadBuilder($registry, new SensitiveClaimPolicy());
}

function sampleGroups(array $rows): array { return ['Pelican' => $rows]; }
function sampleRow(string $target, string $type, string $source): array
{
    return ['key' => $target, 'label' => $target, 'mappings' => [['source_type' => $type, 'source_value' => $source]]];
}

it('builds target-aware nested fake data from staged claim mappings and excludes static mappings', function (): void {
    $result = sampleBuilder()->build('authentik', sampleGroups([
        sampleRow('pelican.username', 'claim', 'preferred_username'),
        sampleRow('pelican.email', 'claim', 'email'),
        sampleRow('pelican.external_id', 'claim', 'sub'),
        sampleRow('pelican.language', 'claim', 'locale'),
        sampleRow('pelican.timezone', 'claim', 'zoneinfo'),
        sampleRow('pelican.is_managed_externally', 'static', 'true'),
        sampleRow('pelican.is_managed_externally', 'claim', 'flags.managed'),
    ]));

    expect($result['warning'])->toBeNull()->and($result['payload'])->toBe([
        'preferred_username' => 'sam', 'email' => 'sam@example.com', 'sub' => 'example-external-id',
        'locale' => 'en', 'zoneinfo' => 'UTC', 'flags' => ['managed' => true],
    ])->and($result['payload'])->not->toHaveKey('true');
});

it('merges shared parents, uses discovered numeric types, and excludes unmapped claims by default', function (): void {
    $now = now();
    foreach (['limits.cpu' => 'integer', 'limits.memory' => 'integer', 'unused' => 'string'] as $path => $type) {
        DiscoveredClaim::create(['provider' => 'p', 'claim_path' => $path, 'claim_type' => $type, 'first_seen_at' => $now, 'last_seen_at' => $now]);
    }
    $groups = sampleGroups([sampleRow('unknown.cpu', 'claim', 'limits.cpu'), sampleRow('unknown.memory', 'claim', 'limits.memory')]);
    expect(sampleBuilder()->build('p', $groups)['payload'])->toBe(['limits' => ['cpu' => 1, 'memory' => 1]])
        ->and(sampleBuilder()->build('p', $groups, true)['payload'])->toHaveKey('unused', 'example');
});

it('reports scalar and nested path collisions without silently overwriting', function (): void {
    $result = sampleBuilder()->build('p', sampleGroups([
        sampleRow('pelican.username', 'claim', 'foo'), sampleRow('pelican.email', 'claim', 'foo.bar'),
    ]));
    expect($result['warning'])->toContain('[foo]', '[foo.bar]');
});
