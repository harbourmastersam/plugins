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

it('loads, creates, updates, clears and isolates provider mappings including wildcard mappings', function (): void {
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

    $wildcard = $workspace->load('*');
    $timezone = collect($wildcard['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.timezone');
    $wildcard['groups']['Pelican'][$timezone]['mappings'][0]['source_claim'] = 'zoneinfo';
    $workspace->save('*', $wildcard['groups'], $wildcard['unavailable']);
    expect(AttributeMapping::where('provider', '*')->where('source_claim', 'zoneinfo')->exists())->toBeTrue();
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
