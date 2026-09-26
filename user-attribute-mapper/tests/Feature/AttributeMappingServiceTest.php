<?php

use App\Models\User;
use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Data\UserAttributeDefinition;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use HarbourmasterSam\UserAttributeMapper\Services\AttributeMappingService;
use HarbourmasterSam\UserAttributeMapper\Services\AttributeValueConverter;
use HarbourmasterSam\UserAttributeMapper\Services\ClaimPathResolver;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies only mappings belonging to the authenticating provider', function (): void {
    foreach ([
        ['staff', 'staff_username'],
        ['port', 'port_username'],
        ['*', 'legacy_username'],
    ] as [$provider, $claim]) {
        AttributeMapping::create([
            'provider' => $provider, 'source_value' => $claim, 'target_attribute' => 'pelican.username',
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

it('routes static scalar text through target conversion', function (string $source, AttributeType $type, mixed $expected): void {
    AttributeMapping::create([
        'provider' => 'authentik', 'source_type' => MappingSourceType::Static,
        'source_value' => $source, 'target_attribute' => 'example.value',
        'enabled' => true, 'missing_claim_behavior' => 'clear', 'priority' => 100,
    ]);
    $written = null;
    $definition = new UserAttributeDefinition(
        key: 'example.value', owner: 'example', label: 'Value', type: $type,
        reader: fn () => null,
        writer: function (User $user, mixed $value) use (&$written): void { $written = $value; },
        writableFromIdentity: true,
    );
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->with('example.value')->andReturn($definition);
    $attributes = new UserAttributeService($registry, new AttributeValueConverter());

    (new AttributeMappingService($registry, $attributes, new ClaimPathResolver()))->apply(new User(), 'authentik', []);

    expect($written)->toBe($expected);
})->with([
    'string' => ['hello', AttributeType::String, 'hello'],
    'boolean true' => ['true', AttributeType::Boolean, true],
    'boolean false' => ['false', AttributeType::Boolean, false],
    'integer zero' => ['0', AttributeType::Integer, 0],
]);

it('isolates an invalid static conversion without coercing it', function (): void {
    AttributeMapping::create([
        'provider' => 'authentik', 'source_type' => 'static', 'source_value' => 'hello',
        'target_attribute' => 'example.value', 'enabled' => true,
        'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    $written = false;
    $definition = new UserAttributeDefinition(
        key: 'example.value', owner: 'example', label: 'Value', type: AttributeType::Integer,
        reader: fn () => null,
        writer: function () use (&$written): void { $written = true; },
        writableFromIdentity: true,
    );
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->with('example.value')->andReturn($definition);

    (new AttributeMappingService(
        $registry,
        new UserAttributeService($registry, new AttributeValueConverter()),
        new ClaimPathResolver(),
    ))->apply(new User(), 'authentik', []);

    expect($written)->toBeFalse();
});

it('distinguishes a claim named true from a static true value', function (): void {
    foreach ([['claim', 'claim-target'], ['static', 'static-target']] as [$type, $target]) {
        AttributeMapping::create([
            'provider' => 'authentik', 'source_type' => $type, 'source_value' => 'true',
            'target_attribute' => "example.$target", 'enabled' => true,
            'missing_claim_behavior' => 'preserve', 'priority' => 100,
        ]);
    }
    $values = [];
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->andReturnUsing(function (string $key) use (&$values): UserAttributeDefinition {
        return new UserAttributeDefinition(
            key: $key, owner: 'example', label: 'Value', type: AttributeType::String,
            reader: fn () => null,
            writer: function (User $user, string $value) use (&$values, $key): void { $values[$key] = $value; },
            writableFromIdentity: true,
        );
    });
    (new AttributeMappingService($registry, new UserAttributeService($registry, new AttributeValueConverter()), new ClaimPathResolver()))
        ->apply(new User(), 'authentik', ['true' => 'claim-value']);

    expect($values)->toBe(['example.claim-target' => 'claim-value', 'example.static-target' => 'true']);
});

it('keeps static mappings scoped to their provider', function (): void {
    AttributeMapping::create([
        'provider' => 'staff', 'source_type' => 'static', 'source_value' => 'true',
        'target_attribute' => 'example.flag', 'enabled' => true,
        'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $attributes = Mockery::mock(UserAttributeService::class);
    $registry->shouldReceive('get')->once()->andReturn(new UserAttributeDefinition(
        key: 'example.flag', owner: 'example', label: 'Flag', type: AttributeType::Boolean,
        reader: fn () => null, writer: fn () => null, writableFromIdentity: true,
    ));
    $attributes->shouldReceive('setFromIdentity')->once()->with(Mockery::type(User::class), 'example.flag', 'true');
    $service = new AttributeMappingService($registry, $attributes, new ClaimPathResolver());

    $service->apply(new User(), 'port', []);
    $service->apply(new User(), 'staff', []);
});
