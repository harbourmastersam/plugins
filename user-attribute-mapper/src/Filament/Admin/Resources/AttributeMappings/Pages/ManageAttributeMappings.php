<?php

namespace Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages;

use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Boy132\UserAttributeMapper\Models\AttributeMapping;
use Boy132\UserAttributeMapper\OAuth\OAuthProviderResolver;
use Boy132\UserAttributeMapper\Services\ProfileMappingWorkspace;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ManageAttributeMappings extends Page
{
    protected static string $resource = AttributeMappingResource::class;
    protected string $view = 'user-attribute-mapper::filament.admin.resources.attribute-mappings.pages.manage-attribute-mappings';

    public string $provider = '';
    /** @var array<string, string> */
    public array $providerOptions = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $groups = [];
    /** @var array<int, array<string, mixed>> */
    public array $unavailable = [];
    /** @var array<int, array{id: int, source_claim: string, target_attribute: string}> */
    public array $legacyMappings = [];

    public function getTitle(): string
    {
        return 'User Profile Mappings';
    }

    public function getSubheading(): ?string
    {
        return 'Map identity provider claims to Pelican and plugin-owned user attributes.';
    }

    public function mount(OAuthProviderResolver $providers, ProfileMappingWorkspace $workspace): void
    {
        $this->providerOptions = $providers->options();
        $this->provider = (string) (array_key_first($this->providerOptions) ?? '');
        $this->loadLegacyMappings();

        if ($this->provider !== '') {
            $this->loadMappings($workspace);
        }
    }

    public function changeProvider(string $provider, OAuthProviderResolver $providers, ProfileMappingWorkspace $workspace): void
    {
        $available = $providers->options();
        if (!array_key_exists($provider, $available)) return;

        $this->providerOptions = $available;
        $this->provider = $provider;
        $this->loadMappings($workspace);
        $this->dispatch('mapping-workspace-saved');
    }

    public function addMapping(string $group, int $row, ProfileMappingWorkspace $workspace): void
    {
        $this->groups[$group][$row]['mappings'][] = $workspace->emptyMappingState();
    }

    public function removeMapping(string $group, int $row, int $mapping): void
    {
        unset($this->groups[$group][$row]['mappings'][$mapping]);
        $this->groups[$group][$row]['mappings'] = array_values($this->groups[$group][$row]['mappings']);
        if ($this->groups[$group][$row]['mappings'] === []) {
            $this->groups[$group][$row]['mappings'][] = app(ProfileMappingWorkspace::class)->emptyMappingState();
        }
    }

    /** Toggle a configured mapping in the staged workspace without persisting it. */
    public function toggleMappingEnabled(string $group, int $row, int $mapping): void
    {
        if (!isset($this->groups[$group][$row]['mappings'][$mapping])) return;

        $state = &$this->groups[$group][$row]['mappings'][$mapping];
        if (blank($state['source_claim'] ?? null)) return;

        $state['enabled'] = !(bool) ($state['enabled'] ?? true);
    }

    public function removeUnavailable(int $index): void
    {
        unset($this->unavailable[$index]);
        $this->unavailable = array_values($this->unavailable);
    }

    public function save(OAuthProviderResolver $providers, ProfileMappingWorkspace $workspace): void
    {
        $available = $providers->options();
        if ($this->provider === '' || !array_key_exists($this->provider, $available)) {
            Notification::make()->title('Select an enabled identity provider')->danger()->send();

            return;
        }

        $this->providerOptions = $available;
        $workspace->save($this->provider, $this->groups, $this->unavailable);
        $this->loadMappings($workspace);
        $this->dispatch('mapping-workspace-saved');
        Notification::make()->title('Mappings saved')->success()->send();
    }

    public function removeLegacyMapping(int $id): void
    {
        AttributeMapping::query()->where('provider', '*')->whereKey($id)->delete();
        $this->loadLegacyMappings();
        Notification::make()->title('Legacy global mapping removed')->success()->send();
    }

    private function loadMappings(ProfileMappingWorkspace $workspace): void
    {
        $state = $workspace->load($this->provider);
        $this->groups = $state['groups'];
        $this->unavailable = $state['unavailable'];
    }

    private function loadLegacyMappings(): void
    {
        $this->legacyMappings = AttributeMapping::query()->where('provider', '*')->orderBy('id')
            ->get(['id', 'source_claim', 'target_attribute'])
            ->map(fn (AttributeMapping $mapping): array => [
                'id' => $mapping->id,
                'source_claim' => $mapping->source_claim,
                'target_attribute' => $mapping->target_attribute,
            ])->all();
    }
}
