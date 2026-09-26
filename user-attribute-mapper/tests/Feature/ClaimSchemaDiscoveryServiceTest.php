<?php

use HarbourmasterSam\UserAttributeMapper\Models\DiscoveredClaim;
use HarbourmasterSam\UserAttributeMapper\Services\ClaimSchemaDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('discovers provider scoped schema metadata without claim values', function (): void {
    config()->set('user-attribute-mapper.claim_discovery', true);
    $service = app(ClaimSchemaDiscoveryService::class);
    $service->discover('authentik', [
        'name' => 'private name', 'count' => 7, 'ratio' => 1.5, 'active' => true,
        'limits' => ['cpu' => 800], 'groups' => ['private-group', 'other'],
        'access_token' => 'very-secret', 'nested' => ['password' => 'also-secret'],
    ]);

    expect(DiscoveredClaim::where('provider', 'authentik')->pluck('claim_type', 'claim_path')->all())->toMatchArray([
        'name' => 'string', 'count' => 'integer', 'ratio' => 'float', 'active' => 'boolean',
        'limits' => 'object', 'limits.cpu' => 'integer', 'groups' => 'array', 'nested' => 'object',
    ])->and(DiscoveredClaim::where('claim_path', 'like', 'groups.%')->exists())->toBeFalse()
      ->and(DiscoveredClaim::whereIn('claim_path', ['access_token', 'nested.password'])->exists())->toBeFalse()
      ->and(Schema::getColumnListing('user_attribute_discovered_claims'))->not->toContain('claim_value')
      ->and(json_encode(DiscoveredClaim::all()->toArray()))->not->toContain('private name', 'very-secret', '800');
});

it('retains timestamps and concrete types while incrementing observations independently', function (): void {
    config()->set('user-attribute-mapper.claim_discovery', true);
    $service = app(ClaimSchemaDiscoveryService::class);
    $service->discover('one', ['subject' => 'abc']);
    $first = DiscoveredClaim::where('provider', 'one')->firstOrFail();
    $service->discover('one', ['subject' => null]);
    $service->discover('two', ['subject' => 42]);

    $updated = $first->fresh();
    expect($updated->claim_type)->toBe('string')->and($updated->observation_count)->toBe(2)
        ->and($updated->first_seen_at->equalTo($first->first_seen_at))->toBeTrue()
        ->and($updated->last_seen_at->greaterThanOrEqualTo($first->last_seen_at))->toBeTrue()
        ->and(DiscoveredClaim::where('provider', 'two')->value('claim_type'))->toBe('integer');
});
