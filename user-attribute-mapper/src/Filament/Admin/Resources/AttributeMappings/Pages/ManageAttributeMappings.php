<?php

namespace HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages;

use HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use HarbourmasterSam\UserAttributeMapper\OAuth\OAuthProviderResolver;
use HarbourmasterSam\UserAttributeMapper\Services\ProfileMappingWorkspace;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use HarbourmasterSam\UserAttributeMapper\Services\MappingPreviewService;
use JsonException;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMappingAudit;
use HarbourmasterSam\UserAttributeMapper\Services\MappingAuditService;

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
    /** @var array<int, array{id: int, source_type: string, source_value: string, target_attribute: string}> */
    public array $legacyMappings = [];
    public string $sampleClaims = "{\n    \"preferred_username\": \"sam\",\n    \"email\": \"sam@example.com\"\n}";
    /** @var list<array<string, mixed>> */
    public array $previewResults = [];
    public ?string $previewError = null;
    /** @var list<array<string, mixed>> */
    public array $mappingHistory = [];

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
        $this->loadHistory();

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
        $priorities = array_column($this->groups[$group][$row]['mappings'], 'priority');
        $state = $workspace->emptyMappingState();
        $state['priority'] = ($priorities === [] ? 0 : max(array_map('intval', $priorities))) + 10;
        $this->groups[$group][$row]['mappings'][] = $state;
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
        if (!array_key_exists('source_value', $state) || strlen((string) $state['source_value']) === 0) return;

        $state['enabled'] = !(bool) ($state['enabled'] ?? true);
    }

    /** Change a staged source kind, clearing its value to avoid silently reinterpreting it. */
    public function changeMappingSourceType(string $group, int $row, int $mapping, string $sourceType): void
    {
        $type = MappingSourceType::tryFrom($sourceType);
        if ($type === null || !isset($this->groups[$group][$row]['mappings'][$mapping])) return;

        $state = &$this->groups[$group][$row]['mappings'][$mapping];
        if (($state['source_type'] ?? MappingSourceType::Claim->value) === $type->value) return;

        $state['source_type'] = $type->value;
        $state['source_value'] = '';
    }

    public function addTransformation(string $group, int $row, int $mapping): void
    {
        $this->groups[$group][$row]['mappings'][$mapping]['transforms'][] = ['type' => 'trim'];
    }

    public function removeTransformation(string $group, int $row, int $mapping, int $transform): void
    {
        unset($this->groups[$group][$row]['mappings'][$mapping]['transforms'][$transform]);
        $this->groups[$group][$row]['mappings'][$mapping]['transforms'] = array_values($this->groups[$group][$row]['mappings'][$mapping]['transforms']);
    }

    public function moveTransformation(string $group, int $row, int $mapping, int $transform, int $direction): void
    {
        $items = &$this->groups[$group][$row]['mappings'][$mapping]['transforms'];
        $destination = $transform + $direction;
        if (!isset($items[$transform], $items[$destination])) return;
        [$items[$transform], $items[$destination]] = [$items[$destination], $items[$transform]];
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
        try {
            $workspace->save($this->provider, $this->groups, $this->unavailable);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()->title('Mappings were not saved')->body($exception->getMessage())->danger()->send();
            return;
        }
        $this->loadMappings($workspace);
        $this->loadHistory();
        $this->dispatch('mapping-workspace-saved');
        Notification::make()->title('Mappings saved')->success()->send();
    }

    public function testMappings(MappingPreviewService $preview): void
    {
        $this->previewError = null;
        $this->previewResults = [];
        try {
            $claims = json_decode($this->sampleClaims, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($claims) || array_is_list($claims)) throw new JsonException('Sample claims must be a JSON object.');
            $this->previewResults = $preview->preview($this->groups, $claims);
        } catch (JsonException $exception) {
            $this->previewError = 'Invalid sample JSON: '.$exception->getMessage();
        }
    }

    public function removeLegacyMapping(int $id, MappingAuditService $audit): void
    {
        $mapping = AttributeMapping::query()->where('provider', '*')->whereKey($id)->first();
        if ($mapping) {
            $audit->record('*', [['operation' => 'removed', 'mapping_id' => $mapping->id, 'target_attribute' => $mapping->target_attribute, 'source_type' => $mapping->source_type->value] ], auth()->id(), 'legacy_mapping_removed');
            $mapping->delete();
        }
        $this->loadLegacyMappings();
        $this->loadHistory();
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
            ->get(['id', 'source_type', 'source_value', 'target_attribute'])
            ->map(fn (AttributeMapping $mapping): array => [
                'id' => $mapping->id,
                'source_type' => $mapping->source_type->value,
                'source_value' => $mapping->source_value,
                'target_attribute' => $mapping->target_attribute,
            ])->all();
    }

    private function loadHistory(): void
    {
        $this->mappingHistory = AttributeMappingAudit::query()->latest('id')->limit(25)->get()->map(fn (AttributeMappingAudit $audit) => [
            'created_at' => $audit->created_at?->toDateTimeString(), 'actor_id' => $audit->actor_id,
            'provider' => $audit->provider, 'action' => $audit->action,
            'targets' => collect($audit->changes)->pluck('target_attribute')->filter()->unique()->values()->all(),
        ])->all();
    }
}
