<?php

use Boy132\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use Boy132\UserAttributeMapper\Data\UserAttributeDefinition;
use Boy132\UserAttributeMapper\Enums\AttributeType;
use Boy132\UserAttributeMapper\Models\AttributeMapping;
use Boy132\UserAttributeMapper\Services\ProfileMappingWorkspace;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function profileWorkspace(): ProfileMappingWorkspace
{
    $registry = new UserAttributeRegistry();
    (new PelicanUserAttributeProvider())->register($registry);
    $registry->register(new UserAttributeDefinition(
        key: 'example-plugin.foo',
        owner: 'example-plugin',
        label: 'Foo',
        type: AttributeType::Integer,
        reader: fn () => null,
        writer: fn () => null,
        clearer: fn () => null,
        group: 'Example Plugin',
        nullable: true,
        writableFromIdentity: true,
    ));
    $registry->register(new UserAttributeDefinition(
        key: 'example-plugin.read-only',
        owner: 'example-plugin',
        label: 'Read only',
        type: AttributeType::String,
        reader: fn () => null,
        group: 'Example Plugin',
    ));

    return new ProfileMappingWorkspace($registry);
}

it('builds target-driven dynamic groups and excludes read-only attributes', function (): void {
    $state = profileWorkspace()->load('authentik');

    expect($state['groups'])->toHaveKeys(['Pelican', 'Example Plugin'])
        ->and(collect($state['groups']['Pelican'])->pluck('key')->all())->toBe([
            'pelican.username',
            'pelican.email',
            'pelican.external_id',
            'pelican.language',
            'pelican.timezone',
        ])
        ->and(collect($state['groups']['Example Plugin'])->pluck('key')->all())->toBe(['example-plugin.foo'])
        ->and($state['groups']['Pelican'][0]['mappings'][0]['source_claim'])->toBe('')
        ->and($state['groups']['Pelican'][0]['nullable'])->toBeFalse()
        ->and($state['groups']['Pelican'][0]['clear_supported'])->toBeFalse()
        ->and($state['groups']['Example Plugin'][0]['nullable'])->toBeTrue()
        ->and($state['groups']['Example Plugin'][0]['clear_supported'])->toBeTrue();
});

it('toggles only the staged enabled state and retains its source claim', function (): void {
    $page = new \Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = ['Pelican' => [[
        'mappings' => [[
            'source_claim' => 'preferred_username',
            'enabled' => true,
            'priority' => 25,
            'description' => 'Login name',
            'missing_claim_behavior' => 'preserve',
        ]],
    ]]];

    $page->toggleMappingEnabled('Pelican', 0, 0);

    expect($page->groups['Pelican'][0]['mappings'][0])->toMatchArray([
        'source_claim' => 'preferred_username',
        'enabled' => false,
        'priority' => 25,
        'description' => 'Login name',
        'missing_claim_behavior' => 'preserve',
    ])->and(AttributeMapping::query()->count())->toBe(0);

    $page->toggleMappingEnabled('Pelican', 0, 0);
    expect($page->groups['Pelican'][0]['mappings'][0]['enabled'])->toBeTrue();
});

it('does not toggle an unmapped row', function (): void {
    $page = new \Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = ['Pelican' => [['mappings' => [[
        'source_claim' => '', 'enabled' => true, 'priority' => 25,
        'description' => 'Unchanged', 'missing_claim_behavior' => 'clear',
    ]]]]];

    $page->toggleMappingEnabled('Pelican', 0, 0);

    expect($page->groups['Pelican'][0]['mappings'][0])->toMatchArray([
        'source_claim' => '', 'enabled' => true, 'priority' => 25,
        'description' => 'Unchanged', 'missing_claim_behavior' => 'clear',
    ]);
});

it('loads, creates, updates, clears and isolates provider mappings', function (): void {
    $workspace = profileWorkspace();
    AttributeMapping::create([
        'provider' => 'authentik', 'source_claim' => 'old_name', 'target_attribute' => 'pelican.username',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    AttributeMapping::create([
        'provider' => 'other', 'source_claim' => 'other_email', 'target_attribute' => 'pelican.email',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);

    $state = $workspace->load('authentik');
    $username = collect($state['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.username');
    $email = collect($state['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.email');
    expect($state['groups']['Pelican'][$username]['mappings'][0]['source_claim'])->toBe('old_name');

    $state['groups']['Pelican'][$username]['mappings'][0] = array_merge(
        $state['groups']['Pelican'][$username]['mappings'][0],
        ['source_claim' => 'preferred_username_new', 'enabled' => false, 'missing_claim_behavior' => 'clear', 'priority' => 25, 'description' => 'Preferred login'],
    );
    $state['groups']['Pelican'][$email]['mappings'][0]['source_claim'] = 'email';
    $workspace->save('authentik', $state['groups'], $state['unavailable']);

    expect(AttributeMapping::where('provider', 'authentik')->count())->toBe(2)
        ->and(AttributeMapping::where('provider', 'other')->value('source_claim'))->toBe('other_email');
    $updated = AttributeMapping::where('provider', 'authentik')->where('target_attribute', 'pelican.username')->firstOrFail();
    expect($updated->source_claim)->toBe('preferred_username_new')
        ->and($updated->enabled)->toBeFalse()
        ->and($updated->missing_claim_behavior->value)->toBe('clear')
        ->and($updated->priority)->toBe(25)
        ->and($updated->description)->toBe('Preferred login');

    $state = $workspace->load('authentik');
    $email = collect($state['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.email');
    $state['groups']['Pelican'][$email]['mappings'][0]['source_claim'] = '';
    $workspace->save('authentik', $state['groups'], $state['unavailable']);
    expect(AttributeMapping::where('provider', 'authentik')->where('target_attribute', 'pelican.email')->exists())->toBeFalse();

});

it('rejects wildcard saves without deleting legacy wildcard records', function (): void {
    $workspace = profileWorkspace();
    $legacy = AttributeMapping::create([
        'provider' => '*', 'source_claim' => 'legacy_name', 'target_attribute' => 'pelican.username',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);

    $state = $workspace->load('*');

    expect(fn () => $workspace->save('*', $state['groups'], $state['unavailable']))
        ->toThrow(\InvalidArgumentException::class)
        ->and($legacy->fresh())->not->toBeNull();
});

it('selects only resolver options and defaults to the first enabled provider', function (): void {
    AttributeMapping::create([
        'provider' => 'removed', 'source_claim' => 'old', 'target_attribute' => 'pelican.username',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    $providers = Mockery::mock(\Boy132\UserAttributeMapper\OAuth\OAuthProviderResolver::class);
    $providers->shouldReceive('options')->once()->andReturn(['staff' => 'Staff', 'port' => 'Port']);
    $page = new \Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();

    $page->mount($providers, profileWorkspace());

    expect($page->providerOptions)->toBe(['staff' => 'Staff', 'port' => 'Port'])
        ->and($page->provider)->toBe('staff')
        ->and($page->providerOptions)->not->toHaveKey('*')
        ->and($page->providerOptions)->not->toHaveKey('removed');
});

it('keeps an empty workspace when no identity providers are enabled', function (): void {
    $providers = Mockery::mock(\Boy132\UserAttributeMapper\OAuth\OAuthProviderResolver::class);
    $providers->shouldReceive('options')->once()->andReturn([]);
    $page = new \Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();

    $page->mount($providers, profileWorkspace());

    expect($page->provider)->toBe('')->and($page->providerOptions)->toBe([])
        ->and($page->groups)->toBe([])->and($page->unavailable)->toBe([]);
});

it('switches between independent provider workspaces', function (): void {
    foreach ([['staff', 'staff_username'], ['port', 'port_username']] as [$provider, $claim]) {
        AttributeMapping::create([
            'provider' => $provider, 'source_claim' => $claim, 'target_attribute' => 'pelican.username',
            'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
        ]);
    }
    $providers = Mockery::mock(\Boy132\UserAttributeMapper\OAuth\OAuthProviderResolver::class);
    $providers->shouldReceive('options')->twice()->andReturn(['staff' => 'Staff', 'port' => 'Port']);
    $workspace = profileWorkspace();
    $page = new \Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->mount($providers, $workspace);

    $staffUsername = collect($page->groups['Pelican'])->firstWhere('key', 'pelican.username');
    expect($staffUsername['mappings'][0]['source_claim'])->toBe('staff_username');

    $page->changeProvider('port', $providers, $workspace);
    $portUsername = collect($page->groups['Pelican'])->firstWhere('key', 'pelican.username');
    expect($page->provider)->toBe('port')
        ->and($portUsername['mappings'][0]['source_claim'])->toBe('port_username')
        ->and(AttributeMapping::where('provider', 'staff')->value('source_claim'))->toBe('staff_username');
});

it('preserves multiple priority mappings and exposes removable unavailable targets', function (): void {
    $workspace = profileWorkspace();
    foreach ([['first_name', 10], ['fallback_name', 20]] as [$claim, $priority]) {
        AttributeMapping::create([
            'provider' => 'authentik', 'source_claim' => $claim, 'target_attribute' => 'pelican.username',
            'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => $priority,
        ]);
    }
    AttributeMapping::create([
        'provider' => 'authentik', 'source_claim' => 'limits.cpu', 'target_attribute' => 'removed-plugin.cpu',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);

    $state = $workspace->load('authentik');
    $username = collect($state['groups']['Pelican'])->firstWhere('key', 'pelican.username');
    expect($username['mappings'])->toHaveCount(2)
        ->and($state['unavailable'])->toHaveCount(1)
        ->and($state['unavailable'][0]['target_attribute'])->toBe('removed-plugin.cpu');

    $workspace->save('authentik', $state['groups'], []);
    expect(AttributeMapping::where('target_attribute', 'removed-plugin.cpu')->exists())->toBeFalse()
        ->and(AttributeMapping::where('target_attribute', 'pelican.username')->count())->toBe(2);
});
