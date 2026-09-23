<?php

use App\Models\User;
use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Data\UserAttributeDefinition;
use Boy132\UserAttributeMapper\Enums\AttributeType;
use Boy132\UserAttributeMapper\Models\AttributeMapping;
use Boy132\UserAttributeMapper\Services\AttributeMappingService;
use Boy132\UserAttributeMapper\Services\ClaimPathResolver;
use Boy132\UserAttributeMapper\Services\UserAttributeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies only mappings belonging to the authenticating provider', function (): void {
    foreach ([
        ['staff', 'staff_username'],
        ['port', 'port_username'],
        ['*', 'legacy_username'],
    ] as [$provider, $claim]) {
        AttributeMapping::create([
            'provider' => $provider, 'source_claim' => $claim, 'target_attribute' => 'pelican.username',
            'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
        ]);
    }

    $definition = new UserAttributeDefinition(
        key: 'pelican.username', owner: 'pelican', label: 'Username', type: AttributeType::String,
        reader: fn () => null, writer: fn () => null, writableFromIdentity: true,
    );
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->once()->with('pelican.username')->andReturn($definition);
    $attributes = Mockery::mock(UserAttributeService::class);
    $attributes->shouldReceive('setFromIdentity')->once()->withArgs(
        fn (User $user, string $target, mixed $value): bool => $target === 'pelican.username' && $value === 'staff-value',
    );

    (new AttributeMappingService($registry, $attributes, new ClaimPathResolver()))->apply(
        new User(),
        'staff',
        ['staff_username' => 'staff-value', 'port_username' => 'port-value', 'legacy_username' => 'legacy-value'],
    );
});
