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

    public string $provider = '*';
    /** @var array<string, string> */
    public array $providerOptions = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $groups = [];
    /** @var array<int, array<string, mixed>> */
    public array $unavailable = [];

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
        $available = $providers->options();
        $historical = AttributeMapping::query()->distinct()->pluck('provider')->all();
        $this->providerOptions = ['*' => 'All OAuth Providers'] + $available;
        foreach ($historical as $provider) {
            if (!isset($this->providerOptions[$provider])) $this->providerOptions[$provider] = $provider.' (unavailable)';
        }
        $this->loadMappings($workspace);
    }

    public function changeProvider(string $provider, ProfileMappingWorkspace $workspace): void
    {
        if (!array_key_exists($provider, $this->providerOptions)) return;
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

    public function save(ProfileMappingWorkspace $workspace): void
    {
        $workspace->save($this->provider, $this->groups, $this->unavailable);
        $this->loadMappings($workspace);
        $this->dispatch('mapping-workspace-saved');
        Notification::make()->title('Mappings saved')->success()->send();
    }

    private function loadMappings(ProfileMappingWorkspace $workspace): void
    {
        $state = $workspace->load($this->provider);
        $this->groups = $state['groups'];
        $this->unavailable = $state['unavailable'];
    }
}
