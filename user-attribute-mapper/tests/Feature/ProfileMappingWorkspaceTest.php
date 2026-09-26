<?php

use HarbourmasterSam\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use HarbourmasterSam\UserAttributeMapper\Data\UserAttributeDefinition;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use HarbourmasterSam\UserAttributeMapper\Services\ProfileMappingWorkspace;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeRegistry;
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

/** @return array{0: array<string, mixed>, 1: int} */
function profileRow(array $groups, string $target): array
{
    $index = collect($groups['Pelican'])->search(fn (array $row): bool => $row['key'] === $target);
    if ($index === false) {
        throw new LogicException("Missing profile row [{$target}].");
    }

    return [$groups['Pelican'][$index], $index];
}

/** @param array<string, array<int, array<string, mixed>>> $groups */
function profileUiGroups(array $groups): array
{
    return collect($groups)->map(fn (array $rows, string $label): array => [
        'key' => Illuminate\Support\Str::slug($label), 'label' => $label, 'rows' => $rows,
    ])->values()->all();
}

function profileUiRow(array $groups, string $target): array
{
    $pelican = collect($groups)->firstWhere('label', 'Pelican');
    $index = collect($pelican['rows'])->search(fn (array $row): bool => $row['key'] === $target);

    return [$pelican['rows'][$index], $index];
}

function profileWorkspaceGroups(array $groups): array
{
    return collect($groups)->mapWithKeys(fn (array $group): array => [$group['label'] => $group['rows']])->all();
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
            'pelican.is_managed_externally',
        ])
        ->and(collect($state['groups']['Example Plugin'])->pluck('key')->all())->toBe(['example-plugin.foo'])
        ->and($state['groups']['Pelican'][0]['mappings'][0]['source_value'])->toBe('')
        ->and($state['groups']['Pelican'][0]['mappings'][0]['source_type'])->toBe('claim')
        ->and($state['groups']['Pelican'][0]['nullable'])->toBeFalse()
        ->and($state['groups']['Pelican'][0]['clear_supported'])->toBeFalse()
        ->and(collect($state['groups']['Pelican'])->firstWhere('key', 'pelican.is_managed_externally'))->toMatchArray([
            'type' => 'boolean',
            'nullable' => false,
            'clear_supported' => false,
        ])
        ->and($state['groups']['Example Plugin'][0]['nullable'])->toBeTrue()
        ->and($state['groups']['Example Plugin'][0]['clear_supported'])->toBeTrue();
});

it('toggles only the staged enabled state and retains its source claim', function (): void {
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = [['key' => 'pelican', 'label' => 'Pelican', 'rows' => [[
        'mappings' => [[
            'source_value' => 'preferred_username',
            'enabled' => true,
            'priority' => 25,
            'description' => 'Login name',
            'missing_claim_behavior' => 'preserve',
        ]],
    ]]]];

    $page->toggleMappingEnabled(0, 0, 0);

    expect($page->groups[0]['rows'][0]['mappings'][0])->toMatchArray([
        'source_value' => 'preferred_username',
        'enabled' => false,
        'priority' => 25,
        'description' => 'Login name',
        'missing_claim_behavior' => 'preserve',
    ])->and(AttributeMapping::query()->count())->toBe(0);

    $page->toggleMappingEnabled(0, 0, 0);
    expect($page->groups[0]['rows'][0]['mappings'][0]['enabled'])->toBeTrue();
});

it('does not toggle an unmapped row', function (): void {
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = [['key' => 'pelican', 'label' => 'Pelican', 'rows' => [['mappings' => [[
        'source_value' => '', 'enabled' => true, 'priority' => 25,
        'description' => 'Unchanged', 'missing_claim_behavior' => 'clear',
    ]]]]]];

    $page->toggleMappingEnabled(0, 0, 0);

    expect($page->groups[0]['rows'][0]['mappings'][0])->toMatchArray([
        'source_value' => '', 'enabled' => true, 'priority' => 25,
        'description' => 'Unchanged', 'missing_claim_behavior' => 'clear',
    ]);
});

it('clears only the staged source value when its source type changes', function (): void {
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = [['key' => 'pelican', 'label' => 'Pelican', 'rows' => [['mappings' => [[
        'source_type' => 'claim', 'source_value' => 'preferred_username', 'enabled' => false,
        'priority' => 25, 'description' => 'Keep me', 'missing_claim_behavior' => 'clear',
    ]]]]]];

    $page->changeMappingSourceType(0, 0, 0, 'static');

    expect($page->groups[0]['rows'][0]['mappings'][0])->toMatchArray([
        'source_type' => 'static', 'source_value' => '', 'enabled' => false,
        'priority' => 25, 'description' => 'Keep me', 'missing_claim_behavior' => 'clear',
    ]);
});

it('gives staged mappings and transformations stable non-persisted UI identities', function (): void {
    $workspace = profileWorkspace();
    $empty = $workspace->emptyMappingState();
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = [['key' => 'pelican', 'label' => 'Pelican', 'rows' => [['mappings' => [$empty]]]]];

    $page->addTransformation(0, 0, 0);
    $firstMappingKey = $page->groups[0]['rows'][0]['mappings'][0]['_ui_key'];
    $firstTransformKey = $page->groups[0]['rows'][0]['mappings'][0]['transforms'][0]['_ui_key'];
    $page->addMapping(0, 0, $workspace);
    $page->removeMapping(0, 0, 0);

    expect($firstMappingKey)->toBeString()->not->toBe('')
        ->and($firstTransformKey)->toBeString()->not->toBe('')
        ->and($page->groups[0]['rows'][0]['mappings'][0]['_ui_key'])->not->toBe($firstMappingKey);
});

it('edits existing component state and persists every mapping editor field', function (): void {
    $workspace = profileWorkspace();
    AttributeMapping::create([
        'provider' => 'authentik', 'source_type' => 'claim', 'source_value' => 'preferred_username',
        'target_attribute' => 'pelican.username', 'enabled' => true, 'missing_claim_behavior' => 'preserve',
        'priority' => 10, 'description' => 'Old', 'transforms' => [],
    ]);
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->provider = 'authentik';
    $page->groups = profileUiGroups($workspace->load('authentik')['groups']);
    [, $username] = profileUiRow($page->groups, 'pelican.username');
    $page->groups[0]['rows'][$username]['mappings'][0]['source_value'] = 'username';
    $page->groups[0]['rows'][$username]['mappings'][0]['enabled'] = false;
    $page->groups[0]['rows'][$username]['mappings'][0]['priority'] = 30;
    $page->groups[0]['rows'][$username]['mappings'][0]['description'] = 'New';
    $page->addTransformation(0, $username, 0);
    $page->addTransformation(0, $username, 0);
    $page->groups[0]['rows'][$username]['mappings'][0]['transforms'][1]['type'] = 'lowercase';

    $providers = Mockery::mock(\HarbourmasterSam\UserAttributeMapper\OAuth\OAuthProviderResolver::class);
    $providers->shouldReceive('options')->once()->andReturn(['authentik' => 'Authentik']);
    $page->save($providers, $workspace);
    [$row] = profileUiRow($page->groups, 'pelican.username');
    expect($row['mappings'][0])->toMatchArray([
        'source_type' => 'claim', 'source_value' => 'username', 'enabled' => false,
        'priority' => 30, 'description' => 'New',
    ])->and(array_column($row['mappings'][0]['transforms'], 'type'))->toBe(['trim', 'lowercase']);

    $page->groups[0]['rows'][$username]['mappings'][0]['transforms'][1]['type'] = 'uppercase';
    $workspace->save('authentik', profileWorkspaceGroups($page->groups), []);
    [$row] = profileRow($workspace->load('authentik')['groups'], 'pelican.username');
    expect(array_column($row['mappings'][0]['transforms'], 'type'))->toBe(['trim', 'uppercase'])
        ->and($row['mappings'][0]['transforms'][0])->toHaveKey('_ui_key');
});

it('edits an existing boolean static value from true to false and reloads it', function (): void {
    $workspace = profileWorkspace();
    AttributeMapping::create([
        'provider' => 'authentik', 'source_type' => 'static', 'source_value' => 'true',
        'target_attribute' => 'pelican.is_managed_externally', 'enabled' => true,
        'missing_claim_behavior' => 'preserve', 'priority' => 10,
    ]);
    $state = $workspace->load('authentik');
    [, $managed] = profileRow($state['groups'], 'pelican.is_managed_externally');
    $state['groups']['Pelican'][$managed]['mappings'][0]['source_value'] = 'false';
    $workspace->save('authentik', $state['groups'], []);

    expect(AttributeMapping::where('target_attribute', 'pelican.is_managed_externally')->value('source_value'))->toBe('false');
    [$row] = profileRow($workspace->load('authentik')['groups'], 'pelican.is_managed_externally');
    expect($row['mappings'][0]['source_value'])->toBe('false');
});

it('keeps fallback identities aligned after editing and removing an earlier mapping', function (): void {
    $workspace = profileWorkspace();
    foreach ([['A', 10], ['B', 20], ['C', 30]] as [$source, $priority]) {
        AttributeMapping::create([
            'provider' => 'authentik', 'source_type' => 'claim', 'source_value' => $source,
            'target_attribute' => 'pelican.username', 'enabled' => true,
            'missing_claim_behavior' => 'preserve', 'priority' => $priority,
        ]);
    }
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = profileUiGroups($workspace->load('authentik')['groups']);
    [, $username] = profileUiRow($page->groups, 'pelican.username');
    $cId = $page->groups[0]['rows'][$username]['mappings'][2]['id'];
    $cKey = $page->groups[0]['rows'][$username]['mappings'][2]['_ui_key'];
    $page->removeMapping(0, $username, 1);
    $page->groups[0]['rows'][$username]['mappings'][1]['source_value'] = 'upn';
    $workspace->save('authentik', profileWorkspaceGroups($page->groups), []);

    $saved = AttributeMapping::where('target_attribute', 'pelican.username')->orderBy('priority')->get();
    expect($saved->pluck('source_value')->all())->toBe(['A', 'upn'])
        ->and($saved->pluck('id')->all())->toBe([$saved[0]->id, $cId]);
    [$row] = profileRow($workspace->load('authentik')['groups'], 'pelican.username');
    expect($row['mappings'][1]['_ui_key'])->toBe($cKey);
});

it('preserves staged editor values when validation fails', function (): void {
    $workspace = profileWorkspace();
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = profileUiGroups($workspace->load('authentik')['groups']);
    [, $managed] = profileUiRow($page->groups, 'pelican.is_managed_externally');
    $page->changeMappingSourceType(0, $managed, 0, 'static');
    $page->groups[0]['rows'][$managed]['mappings'][0]['source_value'] = 'not-a-boolean';
    $before = $page->groups;

    expect(fn () => $workspace->save('authentik', profileWorkspaceGroups($page->groups), []))->toThrow(InvalidArgumentException::class)
        ->and($page->groups)->toBe($before)
        ->and(AttributeMapping::query()->count())->toBe(0);
});

it('keeps source type and empty value state synchronized in both directions', function (): void {
    $workspace = profileWorkspace();
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->groups = profileUiGroups($workspace->load('authentik')['groups']);
    [, $managed] = profileUiRow($page->groups, 'pelican.is_managed_externally');
    $page->groups[0]['rows'][$managed]['mappings'][0]['source_value'] = 'managed_claim';

    $page->changeMappingSourceType(0, $managed, 0, 'static');
    expect($page->groups[0]['rows'][$managed]['mappings'][0])->toMatchArray([
        'source_type' => 'static', 'source_value' => '',
    ]);

    $page->groups[0]['rows'][$managed]['mappings'][0]['source_value'] = 'false';
    $page->changeMappingSourceType(0, $managed, 0, 'claim');
    expect($page->groups[0]['rows'][$managed]['mappings'][0])->toMatchArray([
        'source_type' => 'claim', 'source_value' => '',
    ]);
});

it('rejects invalid static source text before persistence', function (): void {
    $workspace = profileWorkspace();
    $state = $workspace->load('authentik');
    $managed = collect($state['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.is_managed_externally');
    $state['groups']['Pelican'][$managed]['mappings'][0]['source_type'] = 'static';
    $state['groups']['Pelican'][$managed]['mappings'][0]['source_value'] = ' true ';

    expect(fn () => $workspace->save('authentik', $state['groups'], []))->toThrow(InvalidArgumentException::class)
        ->and(AttributeMapping::query()->count())->toBe(0);
});

it('loads, creates, updates, clears and isolates provider mappings', function (): void {
    $workspace = profileWorkspace();
    AttributeMapping::create([
        'provider' => 'authentik', 'source_value' => 'old_name', 'target_attribute' => 'pelican.username',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    AttributeMapping::create([
        'provider' => 'other', 'source_value' => 'other_email', 'target_attribute' => 'pelican.email',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);

    $state = $workspace->load('authentik');
    $username = collect($state['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.username');
    $email = collect($state['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.email');
    expect($state['groups']['Pelican'][$username]['mappings'][0]['source_value'])->toBe('old_name');

    $state['groups']['Pelican'][$username]['mappings'][0] = array_merge(
        $state['groups']['Pelican'][$username]['mappings'][0],
        ['source_value' => 'preferred_username_new', 'enabled' => false, 'missing_claim_behavior' => 'clear', 'priority' => 25, 'description' => 'Preferred login'],
    );
    $state['groups']['Pelican'][$email]['mappings'][0]['source_value'] = 'email';
    $workspace->save('authentik', $state['groups'], $state['unavailable']);

    expect(AttributeMapping::where('provider', 'authentik')->count())->toBe(2)
        ->and(AttributeMapping::where('provider', 'other')->value('source_value'))->toBe('other_email');
    $updated = AttributeMapping::where('provider', 'authentik')->where('target_attribute', 'pelican.username')->firstOrFail();
    expect($updated->source_value)->toBe('preferred_username_new')
        ->and($updated->enabled)->toBeFalse()
        ->and($updated->missing_claim_behavior->value)->toBe('clear')
        ->and($updated->priority)->toBe(25)
        ->and($updated->description)->toBe('Preferred login');

    $state = $workspace->load('authentik');
    $email = collect($state['groups']['Pelican'])->search(fn (array $row) => $row['key'] === 'pelican.email');
    $state['groups']['Pelican'][$email]['mappings'][0]['source_value'] = '';
    $workspace->save('authentik', $state['groups'], $state['unavailable']);
    expect(AttributeMapping::where('provider', 'authentik')->where('target_attribute', 'pelican.email')->exists())->toBeFalse();

});

it('rejects wildcard saves without deleting legacy wildcard records', function (): void {
    $workspace = profileWorkspace();
    $legacy = AttributeMapping::create([
        'provider' => '*', 'source_value' => 'legacy_name', 'target_attribute' => 'pelican.username',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);

    $state = $workspace->load('*');

    expect(fn () => $workspace->save('*', $state['groups'], $state['unavailable']))
        ->toThrow(\InvalidArgumentException::class)
        ->and($legacy->fresh())->not->toBeNull();
});

it('selects only resolver options and defaults to the first enabled provider', function (): void {
    AttributeMapping::create([
        'provider' => 'removed', 'source_value' => 'old', 'target_attribute' => 'pelican.username',
        'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
    ]);
    $providers = Mockery::mock(\HarbourmasterSam\UserAttributeMapper\OAuth\OAuthProviderResolver::class);
    $providers->shouldReceive('options')->once()->andReturn(['staff' => 'Staff', 'port' => 'Port']);
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();

    $page->mount($providers, profileWorkspace());

    expect($page->providerOptions)->toBe(['staff' => 'Staff', 'port' => 'Port'])
        ->and($page->provider)->toBe('staff')
        ->and($page->providerOptions)->not->toHaveKey('*')
        ->and($page->providerOptions)->not->toHaveKey('removed');
});

it('keeps an empty workspace when no identity providers are enabled', function (): void {
    $providers = Mockery::mock(\HarbourmasterSam\UserAttributeMapper\OAuth\OAuthProviderResolver::class);
    $providers->shouldReceive('options')->once()->andReturn([]);
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();

    $page->mount($providers, profileWorkspace());

    expect($page->provider)->toBe('')->and($page->providerOptions)->toBe([])
        ->and($page->groups)->toBe([])->and($page->unavailable)->toBe([]);
});

it('switches between independent provider workspaces', function (): void {
    foreach ([['staff', 'staff_username'], ['port', 'port_username']] as [$provider, $claim]) {
        AttributeMapping::create([
            'provider' => $provider, 'source_value' => $claim, 'target_attribute' => 'pelican.username',
            'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => 100,
        ]);
    }
    $providers = Mockery::mock(\HarbourmasterSam\UserAttributeMapper\OAuth\OAuthProviderResolver::class);
    $providers->shouldReceive('options')->twice()->andReturn(['staff' => 'Staff', 'port' => 'Port']);
    $workspace = profileWorkspace();
    $page = new \HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings();
    $page->mount($providers, $workspace);

    $staffUsername = collect(collect($page->groups)->firstWhere('label', 'Pelican')['rows'])->firstWhere('key', 'pelican.username');
    expect($staffUsername['mappings'][0]['source_value'])->toBe('staff_username');

    $page->changeProvider('port', $providers, $workspace);
    $portUsername = collect(collect($page->groups)->firstWhere('label', 'Pelican')['rows'])->firstWhere('key', 'pelican.username');
    expect($page->provider)->toBe('port')
        ->and($portUsername['mappings'][0]['source_value'])->toBe('port_username')
        ->and(AttributeMapping::where('provider', 'staff')->value('source_value'))->toBe('staff_username');
});

it('preserves multiple priority mappings and exposes removable unavailable targets', function (): void {
    $workspace = profileWorkspace();
    foreach ([['first_name', 10], ['fallback_name', 20]] as [$claim, $priority]) {
        AttributeMapping::create([
            'provider' => 'authentik', 'source_value' => $claim, 'target_attribute' => 'pelican.username',
            'enabled' => true, 'missing_claim_behavior' => 'preserve', 'priority' => $priority,
        ]);
    }
    AttributeMapping::create([
        'provider' => 'authentik', 'source_value' => 'limits.cpu', 'target_attribute' => 'removed-plugin.cpu',
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
