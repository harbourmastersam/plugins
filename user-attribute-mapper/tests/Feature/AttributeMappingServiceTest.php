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
use Illuminate\Support\Facades\Log;

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

it('logs only warnings in errors mode', function (): void {
    config()->set('user-attribute-mapper.logging_mode', 'errors');
    AttributeMapping::create([
        'provider' => 'staff', 'source_value' => 'name', 'target_attribute' => 'missing.target',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->once()->andReturnNull();
    Log::spy();

    (new AttributeMappingService($registry, Mockery::mock(UserAttributeService::class), new ClaimPathResolver()))
        ->apply(new User(), 'staff', ['name' => 'not-logged']);

    Log::shouldNotHaveReceived('info');
    Log::shouldHaveReceived('warning')->once()->with('Identity attribute mapping unavailable.', Mockery::on(
        fn (array $context): bool => $context['result'] === 'unavailable',
    ));
});

it('logs one correctly counted summary in normal mode', function (): void {
    config()->set('user-attribute-mapper.logging_mode', 'normal');
    foreach (['first', 'second'] as $source) {
        AttributeMapping::create([
            'provider' => 'staff', 'source_value' => $source, 'target_attribute' => "example.$source",
            'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
        ]);
    }
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->twice()->andReturnUsing(fn (string $key) => new UserAttributeDefinition(
        key: $key, owner: 'example', label: 'Value', type: AttributeType::String,
        reader: fn () => null, writer: fn () => null, writableFromIdentity: true,
    ));
    $attributes = Mockery::mock(UserAttributeService::class);
    $attributes->shouldReceive('setFromIdentity')->twice();
    Log::spy();

    (new AttributeMappingService($registry, $attributes, new ClaimPathResolver()))
        ->apply(new User(), 'staff', ['first' => 'one', 'second' => 'two']);

    Log::shouldHaveReceived('info')->once()->with('User attribute mapping completed.', Mockery::on(
        fn (array $context): bool => $context['processed'] === 2
            && $context['updated'] === 2 && $context['cleared'] === 0
            && $context['missing'] === 0 && $context['unavailable'] === 0 && $context['invalid'] === 0,
    ));
    Log::shouldNotHaveReceived('info', ['Identity attribute mapping completed.', Mockery::any()]);
});

it('logs safe individual results and a summary in verbose mode', function (): void {
    config()->set('user-attribute-mapper.logging_mode', 'verbose');
    AttributeMapping::create([
        'provider' => 'staff', 'source_type' => 'claim', 'source_value' => 'preferred_username',
        'target_attribute' => 'example.name', 'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    AttributeMapping::create([
        'provider' => 'staff', 'source_type' => 'static', 'source_value' => 'super-secret-example-value',
        'target_attribute' => 'example.static', 'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->twice()->andReturnUsing(fn (string $key) => new UserAttributeDefinition(
        key: $key, owner: 'example', label: 'Value', type: AttributeType::String,
        reader: fn () => null, writer: fn () => null, writableFromIdentity: true,
    ));
    $attributes = Mockery::mock(UserAttributeService::class);
    $attributes->shouldReceive('setFromIdentity')->twice();
    Log::spy();

    (new AttributeMappingService($registry, $attributes, new ClaimPathResolver()))
        ->apply(new User(), 'staff', ['preferred_username' => 'private-claim-value']);

    Log::shouldHaveReceived('info')->times(3);
    Log::shouldHaveReceived('info')->once()->with('Identity attribute mapping completed.', Mockery::on(
        fn (array $context): bool => ($context['source_path'] ?? null) === 'preferred_username'
            && !str_contains(json_encode($context), 'private-claim-value'),
    ));
    Log::shouldHaveReceived('info')->once()->with('Identity attribute mapping completed.', Mockery::on(
        fn (array $context): bool => $context['source_type'] === 'static'
            && !array_key_exists('source_path', $context)
            && !str_contains(json_encode($context), 'super-secret-example-value'),
    ));
    Log::shouldHaveReceived('info')->once()->with('User attribute mapping completed.', Mockery::on(
        fn (array $context): bool => $context['processed'] === 2 && $context['updated'] === 2
            && !str_contains(json_encode($context), 'super-secret-example-value'),
    ));
});

it('does not log empty mapping runs in normal or verbose mode', function (string $mode): void {
    config()->set('user-attribute-mapper.logging_mode', $mode);
    Log::spy();

    (new AttributeMappingService(Mockery::mock(UserAttributeRegistryContract::class), Mockery::mock(UserAttributeService::class), new ClaimPathResolver()))
        ->apply(new User(), 'staff', []);

    Log::shouldNotHaveReceived('info');
})->with(['normal', 'verbose']);
