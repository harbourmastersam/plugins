<x-filament-panels::page>
    <style>
        .uam-workspace { --uam-border: rgb(209 213 219); --uam-muted: rgb(107 114 128); --uam-panel: rgb(255 255 255); --uam-subtle: rgb(249 250 251); --uam-target: rgb(246 247 249); --uam-text: rgb(17 24 39); --uam-green: rgb(101 163 13); }
        .dark .uam-workspace { --uam-border: rgb(255 255 255 / .12); --uam-muted: rgb(156 163 175); --uam-panel: rgb(24 24 27); --uam-subtle: rgb(255 255 255 / .035); --uam-target: rgb(255 255 255 / .055); --uam-text: rgb(255 255 255); --uam-green: rgb(132 204 22); }
        .uam-panel { overflow: hidden; border: 1px solid var(--uam-border); border-radius: .6rem; background: var(--uam-panel); box-shadow: 0 1px 2px rgb(0 0 0 / .04); }
        .uam-grid { display: grid; grid-template-columns: minmax(0, 1fr) 9rem minmax(0, 1fr); }
        .uam-profile { display: flex; min-width: 0; align-items: center; gap: .85rem; padding: 1rem 1.5rem; }
        .uam-profile-target { grid-column: 3; background: var(--uam-target); }
        .uam-mark { display: grid; width: 5.5rem; height: 3rem; flex: 0 0 auto; place-items: center; border: 1px solid var(--uam-border); border-radius: .3rem; background: var(--uam-panel); color: var(--uam-text); font-size: 1rem; font-weight: 700; }
        .uam-profile-copy { min-width: 0; color: var(--uam-text); }
        .uam-profile-copy strong, .uam-profile-copy span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .uam-profile-copy strong { font-size: .9rem; }
        .uam-profile-copy span { margin-top: .2rem; color: var(--uam-muted); font-size: .8rem; }
        .uam-provider-select { width: min(22rem, 100%); margin-top: .55rem; }
        .uam-group { border-top: 1px solid var(--uam-border); }
        .uam-group-title { padding: .55rem 1.5rem; border-bottom: 1px solid var(--uam-border); background: var(--uam-subtle); color: var(--uam-muted); font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .uam-row { min-height: 4.25rem; align-items: center; }
        .uam-row + .uam-row { border-top: 1px solid var(--uam-border); }
        .uam-source { min-width: 0; padding: .6rem 1.5rem; }
        .uam-source-controls { display: grid; grid-template-columns: 7rem minmax(0, 1fr); gap: .5rem; }
        .uam-connector { display: flex; width: 100%; align-items: center; align-self: stretch; color: var(--uam-muted); }
        .uam-connector::before, .uam-connector::after { min-width: .65rem; flex: 1 1 auto; border-top: 2px solid currentColor; content: ''; }
        .uam-state { display: inline-flex; min-width: 6.9rem; align-items: center; justify-content: center; gap: .3rem; border: 1px solid var(--uam-border); border-radius: 9999px; padding: .38rem .65rem; background: var(--uam-panel); color: var(--uam-muted); font-size: .75rem; font-weight: 700; line-height: 1; white-space: nowrap; }
        button.uam-state { cursor: pointer; }
        button.uam-state:hover { border-color: currentColor; filter: brightness(.96); }
        button.uam-state:focus-visible { outline: 2px solid rgb(var(--primary-500)); outline-offset: 2px; }
        .uam-state-mapped { border-color: color-mix(in srgb, var(--uam-green) 45%, transparent); background: color-mix(in srgb, var(--uam-green) 10%, var(--uam-panel)); color: var(--uam-green); }
        .uam-state-unmapped { opacity: .8; }
        .uam-target { display: flex; min-width: 0; height: 100%; align-items: center; justify-content: space-between; gap: .75rem; padding: .6rem 1.5rem; background: var(--uam-target); }
        .uam-target-name { color: var(--uam-text); font-size: .875rem; font-weight: 600; }
        .uam-target-meta { display: flex; margin-top: .2rem; gap: .35rem; color: var(--uam-muted); font-size: .7rem; }
        @media (max-width: 1023px) {
            .uam-grid { grid-template-columns: minmax(0, 1fr); }
            .uam-profile-target { grid-column: 1; border-top: 1px solid var(--uam-border); }
            .uam-connector { min-height: 4.25rem; flex-direction: column; justify-content: center; }
            .uam-connector::before, .uam-connector::after { min-width: 0; min-height: .65rem; flex: 1 1 auto; border-top: 0; border-left: 2px solid currentColor; }
            .uam-state .uam-direction { transform: rotate(90deg); }
            .uam-target { min-height: 4.25rem; }
            .uam-source-controls { grid-template-columns: 1fr; }
        }
    </style>

    <div
        x-data="{ dirty: false }"
        x-on:input="dirty = true"
        x-on:change="dirty = true"
        x-on:mapping-workspace-saved.window="dirty = false"
        x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
        class="uam-workspace"
    >
        @if ($provider === '')
            <div class="uam-panel px-6 py-12 text-center">
                <x-filament::icon icon="tabler-plug-connected-x" class="mx-auto h-10 w-10 text-gray-400" />
                <h2 class="mt-4 font-semibold text-gray-950 dark:text-white">No enabled identity providers are available.</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Configure an OAuth/OIDC provider before creating user profile mappings.</p>
            </div>
        @else
        <div class="uam-panel">
            <div class="uam-grid">
                <div class="uam-profile">
                    <div class="uam-mark">OAuth</div>
                    <div class="uam-profile-copy flex-1">
                        <strong>Identity Provider User Profile</strong>
                        <span>OAuth / OIDC</span>
                        <label for="mapping-provider" class="sr-only">Identity provider profile</label>
                        <div class="uam-provider-select">
                            <x-filament::input.wrapper>
                                <x-filament::input.select
                                    id="mapping-provider"
                                    wire:change="changeProvider($event.target.value)"
                                    wire:confirm="Changing provider will discard unsaved changes. Continue?"
                                >
                                    @foreach ($providerOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($provider === $value)>{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                </div>
                <div class="uam-profile uam-profile-target">
                    <div class="uam-mark" aria-hidden="true">
                        <x-filament::icon icon="tabler-user-circle" class="h-5 w-5 text-gray-500" />
                    </div>
                    <div class="uam-profile-copy">
                        <strong>Pelican User Profile</strong>
                        <span>Pelican and plugin attributes</span>
                    </div>
                </div>
            </div>

            @forelse ($groups as $group => $rows)
                <section aria-labelledby="mapping-group-{{ \Illuminate\Support\Str::slug($group) }}" class="uam-group">
                    <h2 id="mapping-group-{{ \Illuminate\Support\Str::slug($group) }}" class="uam-group-title">{{ $group }}</h2>

                    @foreach ($rows as $rowIndex => $row)
                        @foreach ($row['mappings'] as $mappingIndex => $mapping)
                            @php
                                $isStatic = ($mapping['source_type'] ?? 'claim') === 'static';
                                $isMapped = array_key_exists('source_value', $mapping) && strlen((string) $mapping['source_value']) > 0;
                                $isEnabled = $isMapped && ($mapping['enabled'] ?? true);
                                $modalId = 'mapping-settings-'.md5($group.'-'.$row['key'].'-'.$mappingIndex);
                            @endphp
                            <div class="uam-grid uam-row" wire:key="mapping-{{ $row['key'] }}-{{ $mapping['id'] ?? 'new-'.$mappingIndex }}">
                                <div class="uam-source">
                                    <label for="source-{{ $modalId }}" class="sr-only">{{ $isStatic ? 'Static value' : 'Identity provider claim' }} for {{ $row['label'] }}</label>
                                    <div class="uam-source-controls">
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select
                                            aria-label="Source type for {{ $row['label'] }}"
                                            wire:change="changeMappingSourceType({{ \Illuminate\Support\Js::from($group) }}, {{ $rowIndex }}, {{ $mappingIndex }}, $event.target.value)"
                                        >
                                            <option value="claim" @selected(!$isStatic)>Claim</option>
                                            <option value="static" @selected($isStatic)>Static</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    <x-filament::input.wrapper>
                                        <x-filament::input
                                            id="source-{{ $modalId }}"
                                            type="text"
                                            maxlength="512"
                                            placeholder="{{ $isStatic ? 'Enter a static value…' : 'Choose an attribute or enter a claim path…' }}"
                                            aria-describedby="relationship-{{ $modalId }}"
                                            wire:model.live.debounce.300ms="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.source_value"
                                        />
                                    </x-filament::input.wrapper>
                                    </div>
                                </div>

                                <div class="uam-connector">
                                    @if ($isMapped)
                                        <button
                                            type="button"
                                            class="uam-state {{ $isEnabled ? 'uam-state-mapped' : '' }}"
                                            aria-label="{{ $isEnabled ? 'Disable' : 'Enable' }} mapping to {{ $row['label'] }}"
                                            title="Click to {{ $isEnabled ? 'disable' : 'enable' }} this mapping"
                                            x-on:click="dirty = true"
                                            wire:click="toggleMappingEnabled({{ \Illuminate\Support\Js::from($group) }}, {{ $rowIndex }}, {{ $mappingIndex }})"
                                        >
                                            @if ($isEnabled)<span aria-hidden="true">✓</span>@endif
                                            <span>{{ $isEnabled ? 'Mapped' : 'Disabled' }}</span>
                                            <span class="uam-direction" aria-hidden="true">→</span>
                                        </button>
                                    @else
                                        <span class="uam-state uam-state-unmapped" aria-label="Unmapped from {{ $row['label'] }}">
                                            <span>Unmapped</span><span class="uam-direction" aria-hidden="true">→</span>
                                        </span>
                                    @endif
                                </div>

                                <div class="uam-target">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="uam-target-name">{{ $mappingIndex === 0 ? $row['label'] : 'Fallback for '.$row['label'] }}</p>
                                            <x-filament::dropdown placement="bottom-start">
                                                <x-slot name="trigger">
                                                    <x-filament::icon-button icon="tabler-info-circle" size="sm" color="gray" label="Information about {{ $row['label'] }}" />
                                                </x-slot>
                                                <div class="w-72 space-y-3 p-4 text-sm text-gray-700 dark:text-gray-200">
                                                    <div><p class="font-semibold text-gray-950 dark:text-white">{{ $row['label'] }}</p><p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $row['description'] ?: 'No description is available.' }}</p></div>
                                                    <dl class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1.5 text-xs">
                                                        <dt class="font-medium">Attribute</dt><dd class="break-all font-mono">{{ $row['key'] }}</dd>
                                                        <dt class="font-medium">Type</dt><dd>{{ $row['type'] }}</dd>
                                                        <dt class="font-medium">Nullable</dt><dd>{{ $row['nullable'] ? 'Yes' : 'No' }}</dd>
                                                        <dt class="font-medium">Clear supported</dt><dd>{{ $row['clear_supported'] ? 'Yes' : 'No' }}</dd>
                                                        <dt class="font-medium">Owner</dt><dd>{{ $row['owner'] }}</dd>
                                                        <dt class="font-medium">Group</dt><dd>{{ $row['group'] }}</dd>
                                                    </dl>
                                                </div>
                                            </x-filament::dropdown>
                                        </div>
                                        <p id="relationship-{{ $modalId }}" class="uam-target-meta">
                                            <span class="truncate font-mono">{{ $row['key'] }}</span>
                                            <span aria-hidden="true">·</span><span>{{ $row['type'] }}</span>
                                            @if ($mappingIndex > 0)<span>Priority {{ $mapping['priority'] }}</span>@endif
                                        </p>
                                    </div>

                                    <x-filament::dropdown placement="bottom-end">
                                        <x-slot name="trigger">
                                            <x-filament::icon-button icon="tabler-dots-vertical" label="Actions for {{ $row['label'] }}{{ $mappingIndex > 0 ? ' fallback' : '' }}" />
                                        </x-slot>
                                        <x-filament::dropdown.list>
                                            <x-filament::dropdown.list.item icon="tabler-adjustments" x-on:click="$dispatch('open-modal', { id: '{{ $modalId }}' })">
                                                Mapping settings
                                            </x-filament::dropdown.list.item>
                                            <x-filament::dropdown.list.item icon="tabler-git-branch" x-on:click="dirty = true" wire:click="addMapping({{ \Illuminate\Support\Js::from($group) }}, {{ $rowIndex }})">
                                                Add fallback mapping
                                            </x-filament::dropdown.list.item>
                                            @if ($isMapped || count($row['mappings']) > 1)
                                                <x-filament::dropdown.list.item icon="tabler-trash" color="danger" x-on:click="dirty = true" wire:click="removeMapping({{ \Illuminate\Support\Js::from($group) }}, {{ $rowIndex }}, {{ $mappingIndex }})">
                                                    Remove mapping
                                                </x-filament::dropdown.list.item>
                                            @endif
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                </div>
                            </div>

                            <x-filament::modal :id="$modalId" width="lg">
                                <x-slot name="heading">Mapping settings</x-slot>
                                <x-slot name="description">Settings are staged until you save all mappings.</x-slot>
                                <div class="space-y-5">
                                    <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/5">
                                        <p class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</p>
                                        <p class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['key'] }}</p>
                                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Source: {{ $isStatic ? 'Static value' : ($mapping['source_value'] ?: 'Not mapped') }}</p>
                                    </div>
                                    @if ($isStatic)
                                        <p class="text-sm text-gray-500 dark:text-gray-400">Static values are always present, so missing-claim behaviour does not apply.</p>
                                    @else
                                    <label class="block space-y-1.5 text-sm font-medium text-gray-950 dark:text-white">
                                        <span>Missing claim behaviour</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.missing_claim_behavior">
                                                <option value="preserve">Preserve existing value</option>
                                                <option value="clear">Clear when supported</option>
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                            Preserve keeps the current Pelican value when the claim is absent. Clear removes it only when the target supports clearing.
                                            @unless ($row['clear_supported']) This target does not support clearing, so its existing value would be preserved. @endunless
                                        </span>
                                    </label>
                                    @endif
                                    <label class="block space-y-1.5 text-sm font-medium text-gray-950 dark:text-white">
                                        <span>Priority</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="number" min="0" wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.priority" />
                                        </x-filament::input.wrapper>
                                    </label>
                                    <label class="block space-y-1.5 text-sm font-medium text-gray-950 dark:text-white">
                                        <span>Description</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.description" />
                                        </x-filament::input.wrapper>
                                    </label>
                                </div>
                                <x-slot name="footerActions">
                                    <x-filament::button x-on:click="$dispatch('close-modal', { id: '{{ $modalId }}' })">Done</x-filament::button>
                                </x-slot>
                            </x-filament::modal>
                        @endforeach
                    @endforeach
                </section>
            @empty
                <div class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    No identity-writable user attributes are currently registered.
                </div>
            @endforelse
        </div>
        @endif

        @if ($legacyMappings)
            <section aria-labelledby="legacy-global-mappings" class="mt-6 rounded-xl border border-warning-200 bg-warning-50/50 p-4 dark:border-warning-400/20 dark:bg-warning-400/5 sm:p-6">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="tabler-alert-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-warning-500" />
                    <div>
                        <h2 id="legacy-global-mappings" class="font-semibold text-gray-950 dark:text-white">Legacy Global Mappings</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Global “*” mappings are no longer supported or applied because mappings are scoped to individual identity providers. Remove them here when they are no longer needed.</p>
                    </div>
                </div>
                <div class="mt-4 divide-y divide-warning-200 dark:divide-warning-400/20">
                    @foreach ($legacyMappings as $mapping)
                        <div class="flex items-center justify-between gap-4 py-3" wire:key="legacy-mapping-{{ $mapping['id'] }}">
                            <p class="min-w-0 text-sm text-gray-700 dark:text-gray-200"><span class="font-medium">{{ $mapping['source_type'] === 'static' ? 'Static' : 'Claim' }}:</span> <span class="font-mono">{{ $mapping['source_type'] === 'static' ? '(value hidden)' : $mapping['source_value'] }}</span> <span aria-hidden="true">→</span> <span class="font-mono">{{ $mapping['target_attribute'] }}</span></p>
                            <x-filament::icon-button icon="tabler-trash" color="danger" label="Remove legacy global mapping" wire:confirm="Remove this legacy global mapping?" wire:click="removeLegacyMapping({{ $mapping['id'] }})" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($unavailable)
            <section aria-labelledby="unavailable-targets" class="mt-6 rounded-xl border border-warning-200 bg-warning-50/50 p-4 dark:border-warning-400/20 dark:bg-warning-400/5 sm:p-6">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="tabler-alert-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-warning-500" />
                    <div>
                        <h2 id="unavailable-targets" class="font-semibold text-gray-950 dark:text-white">Unavailable Targets</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Historical mappings whose target is no longer registered or writable.</p>
                    </div>
                </div>
                <div class="mt-4 divide-y divide-warning-200 dark:divide-warning-400/20">
                    @foreach ($unavailable as $index => $mapping)
                        <div class="uam-grid items-center gap-y-3 py-4" wire:key="unavailable-{{ $mapping['id'] }}">
                            <x-filament::input.wrapper>
                                <x-filament::input aria-label="Source value for unavailable target {{ $mapping['target_attribute'] }}" type="text" maxlength="512" wire:model="unavailable.{{ $index }}.source_value" />
                            </x-filament::input.wrapper>
                            <div class="flex justify-center text-warning-500" aria-hidden="true"><x-filament::icon icon="tabler-chevron-right" class="h-5 w-5" /></div>
                            <div class="flex items-center justify-between gap-3 px-4">
                                <div><p class="font-medium text-gray-950 dark:text-white">Target unavailable</p><p class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $mapping['target_attribute'] }}</p></div>
                                <x-filament::icon-button icon="tabler-trash" color="danger" label="Remove historical mapping" x-on:click="dirty = true" wire:click="removeUnavailable({{ $index }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($provider !== '')
        <div class="sticky bottom-4 z-10 mt-6 flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur dark:border-white/10 dark:bg-gray-900/95 sm:px-6">
            <p x-show="dirty" x-cloak class="flex items-center gap-2 text-sm font-medium text-warning-600 dark:text-warning-400"><span class="h-2 w-2 rounded-full bg-current"></span>Unsaved changes</p>
            <span x-show="! dirty" class="text-sm text-gray-500 dark:text-gray-400">Mappings are saved together for this provider.</span>
            <x-filament::button icon="tabler-device-floppy" wire:click="save" wire:loading.attr="disabled">Save Mappings</x-filament::button>
        </div>
        @endif
    </div>
</x-filament-panels::page>
