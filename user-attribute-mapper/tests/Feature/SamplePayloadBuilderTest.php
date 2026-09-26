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
    expect($result['warning'])->toBe('Cannot generate sample payload because configured claim path [foo] is scalar, but [foo.bar] requires [foo] to be an object.');
});

it('treats discovered parents as structural containers regardless of their observed type', function (string $parentType): void {
    $now = now();
    foreach (['limits' => $parentType, 'limits.cpu' => 'integer', 'limits.memory' => 'integer'] as $path => $type) {
        DiscoveredClaim::create(['provider' => 'p', 'claim_path' => $path, 'claim_type' => $type, 'first_seen_at' => $now, 'last_seen_at' => $now]);
    }

    $result = sampleBuilder()->build('p', [], true);

    expect($result['warning'])->toBeNull()
        ->and($result['payload'])->toBe(['limits' => ['cpu' => 1, 'memory' => 1]])
        ->and($result['payload']['limits']['cpu'])->toBeInt();
})->with(['object parent' => 'object', 'mixed parent' => 'mixed', 'null parent' => 'null']);

it('includes discovered leaves while skipping a discovered parent of configured children', function (): void {
    $now = now();
    foreach (['pelican_limits' => 'mixed', 'pelican_limits.cpu' => 'integer', 'pelican_limits.memory' => 'integer', 'pelican_limits.disk' => 'integer', 'pelican_limits.server_limit' => 'integer', 'language' => 'string'] as $path => $type) {
        DiscoveredClaim::create(['provider' => 'p', 'claim_path' => $path, 'claim_type' => $type, 'first_seen_at' => $now, 'last_seen_at' => $now]);
    }
    $groups = sampleGroups([
        sampleRow('user-creatable-servers.cpu', 'claim', 'pelican_limits.cpu'),
        sampleRow('user-creatable-servers.memory', 'claim', 'pelican_limits.memory'),
        sampleRow('user-creatable-servers.disk', 'claim', 'pelican_limits.disk'),
        sampleRow('user-creatable-servers.server_limit', 'claim', 'pelican_limits.server_limit'),
    ]);

    $result = sampleBuilder()->build('p', $groups, true);

    expect($result['warning'])->toBeNull()->and($result['payload'])->toBe([
        'pelican_limits' => ['cpu' => 1, 'memory' => 1, 'disk' => 1, 'server_limit' => 1],
        'language' => 'example',
    ]);
});
