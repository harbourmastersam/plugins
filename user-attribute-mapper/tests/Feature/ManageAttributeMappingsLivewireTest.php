<?php

use HarbourmasterSam\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use HarbourmasterSam\UserAttributeMapper\Data\UserAttributeDefinition;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use HarbourmasterSam\UserAttributeMapper\OAuth\OAuthProviderResolver;
use HarbourmasterSam\UserAttributeMapper\Services\ProfileMappingWorkspace;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function mappingEditor(array $extraDefinitions = [])
{
    $registry = new UserAttributeRegistry();
    (new PelicanUserAttributeProvider())->register($registry);
    foreach ($extraDefinitions as $definition) $registry->register($definition);

    app()->instance(ProfileMappingWorkspace::class, new ProfileMappingWorkspace($registry));
    $providers = Mockery::mock(OAuthProviderResolver::class);
    $providers->shouldReceive('options')->andReturn(['authentik' => 'Authentik']);
    app()->instance(OAuthProviderResolver::class, $providers);

    return Livewire::test(ManageAttributeMappings::class);
}

/** @return array{0: int, 1: int} */
function mappingEditorIndexes(array $groups, string $target): array
{
    foreach ($groups as $groupIndex => $group) {
        foreach ($group['rows'] as $rowIndex => $row) {
            if ($row['key'] === $target) return [$groupIndex, $rowIndex];
        }
    }

    throw new LogicException("Missing editor row [{$target}].");
}

it('hydrates edits to an existing rendered claim input and persists them', function (): void {
    AttributeMapping::create([
        'provider' => 'authentik', 'source_type' => 'claim', 'source_value' => 'preferred_username',
        'target_attribute' => 'pelican.username', 'enabled' => true,
        'missing_claim_behavior' => 'preserve', 'priority' => 10,
    ]);

    $editor = mappingEditor();
    [$group, $row] = mappingEditorIndexes($editor->get('groups'), 'pelican.username');
    $path = "groups.{$group}.rows.{$row}.mappings.0.source_value";

    $editor->assertSee('preferred_username')
        ->assertSeeHtml('class="fi-input')
        ->assertSeeHtml('wire:model.blur="'.$path.'"')
        ->assertSet($path, 'preferred_username')
        ->set($path, 'username')
        ->assertSet($path, 'username')
        ->call('save')
        ->assertHasNoErrors();

    expect(AttributeMapping::where('target_attribute', 'pelican.username')->value('source_value'))->toBe('username');
    mappingEditor()->assertSet($path, 'username')->assertSee('username');
});

it('hydrates a blank rendered claim input and creates its mapping', function (): void {
    $editor = mappingEditor();
    [$group, $row] = mappingEditorIndexes($editor->get('groups'), 'pelican.email');
    $path = "groups.{$group}.rows.{$row}.mappings.0.source_value";

    $editor->assertSeeHtml('class="fi-input')
        ->assertSeeHtml('wire:model.blur="'.$path.'"')
        ->assertSet($path, '')
        ->set($path, 'email')
        ->assertSet($path, 'email')
        ->call('save');

    expect(AttributeMapping::where('target_attribute', 'pelican.email')->value('source_value'))->toBe('email');
});

it('uses the rendered source type action and clears only genuine type changes', function (): void {
    AttributeMapping::create([
        'provider' => 'authentik', 'source_type' => 'claim', 'source_value' => 'preferred_username',
        'target_attribute' => 'pelican.is_managed_externally', 'enabled' => true,
        'missing_claim_behavior' => 'preserve', 'priority' => 10,
    ]);
    $editor = mappingEditor();
    [$group, $row] = mappingEditorIndexes($editor->get('groups'), 'pelican.is_managed_externally');
    $base = "groups.{$group}.rows.{$row}.mappings.0";

    $editor->call('changeMappingSourceType', $group, $row, 0, 'claim')
        ->assertSet("{$base}.source_value", 'preferred_username')
        ->call('changeMappingSourceType', $group, $row, 0, 'static')
        ->assertSet("{$base}.source_type", 'static')
        ->assertSet("{$base}.source_value", '')
        ->set("{$base}.source_value", 'true')->call('save');

    $editor = mappingEditor();
    $editor->assertSet("{$base}.source_type", 'static')->assertSet("{$base}.source_value", 'true')
        ->call('changeMappingSourceType', $group, $row, 0, 'claim')
        ->assertSet("{$base}.source_value", '')
        ->set("{$base}.source_value", 'pelican_managed')->call('save');

    mappingEditor()->assertSet("{$base}.source_type", 'claim')->assertSet("{$base}.source_value", 'pelican_managed');
});

it('keeps display labels with spaces and punctuation out of binding paths', function (): void {
    $definitions = collect(['Example Plugin With Spaces', 'Example.Plugin / Test'])->map(
        fn (string $group, int $index) => new UserAttributeDefinition(
            key: "example.field-{$index}", owner: 'example', label: "Field {$index}",
            type: AttributeType::String, reader: fn () => null, writer: fn () => null,
            group: $group, writableFromIdentity: true,
        )
    )->all();
    $editor = mappingEditor($definitions);

    foreach ($definitions as $index => $definition) {
        [$group, $row] = mappingEditorIndexes($editor->get('groups'), $definition->key);
        $path = "groups.{$group}.rows.{$row}.mappings.0.source_value";
        $editor->assertSee($definition->group)->assertSeeHtml('wire:model.blur="'.$path.'"')->set($path, "claim_{$index}");
    }
    $editor->call('save');

    expect(AttributeMapping::where('provider', 'authentik')->where('target_attribute', 'like', 'example.%')
        ->orderBy('target_attribute')->pluck('source_value')->all())->toBe(['claim_0', 'claim_1']);
});

it('preserves fallback identities after a rendered component action reindexes them', function (): void {
    foreach ([['A', 10], ['B', 20], ['C', 30]] as [$source, $priority]) {
        AttributeMapping::create([
            'provider' => 'authentik', 'source_type' => 'claim', 'source_value' => $source,
            'target_attribute' => 'pelican.username', 'enabled' => true,
            'missing_claim_behavior' => 'preserve', 'priority' => $priority,
        ]);
    }
    $editor = mappingEditor();
    [$group, $row] = mappingEditorIndexes($editor->get('groups'), 'pelican.username');
    $cId = $editor->get("groups.{$group}.rows.{$row}.mappings.2.id");

    $editor->call('removeMapping', $group, $row, 1)
        ->set("groups.{$group}.rows.{$row}.mappings.1.source_value", 'upn')
        ->call('save');

    $saved = AttributeMapping::where('target_attribute', 'pelican.username')->orderBy('priority')->get();
    expect($saved->pluck('source_value')->all())->toBe(['A', 'upn'])
        ->and($saved->last()->id)->toBe($cId);
});
