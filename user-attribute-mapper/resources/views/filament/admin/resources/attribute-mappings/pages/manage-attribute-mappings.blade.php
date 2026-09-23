<x-filament-panels::page>
    <div
        x-data="{ dirty: false }"
        x-on:input="dirty = true"
        x-on:change="dirty = true"
        x-on:mapping-workspace-saved.window="dirty = false"
        x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
        class="space-y-8"
    >
        {{-- These cards deliberately share the same grid as the rows below. --}}
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_4rem_minmax(0,1fr)]">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Identity Provider Profile</p>
                <label for="mapping-provider" class="sr-only">Identity provider profile</label>
                <x-filament::input.wrapper class="mt-3">
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
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">OAuth / OIDC source profile</p>
            </div>

            <div class="flex items-center justify-center text-gray-400 dark:text-gray-500" aria-hidden="true">
                <x-filament::icon icon="tabler-arrow-right" class="hidden h-6 w-6 lg:block" />
                <x-filament::icon icon="tabler-arrow-down" class="h-6 w-6 lg:hidden" />
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Pelican User Profile</p>
                <p class="mt-3 text-lg font-semibold text-gray-950 dark:text-white">Pelican</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pelican and installed plugin attributes</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="hidden grid-cols-[minmax(0,1fr)_4rem_minmax(0,1fr)] gap-4 border-b border-gray-200 bg-gray-50/70 px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 lg:grid">
                <span>Source attribute</span>
                <span class="sr-only">Mapping direction</span>
                <span>Target attribute</span>
            </div>

            @forelse ($groups as $group => $rows)
                <section aria-labelledby="mapping-group-{{ \Illuminate\Support\Str::slug($group) }}" class="px-4 py-6 sm:px-6">
                    <div class="mb-2 flex items-center gap-4">
                        <h2 id="mapping-group-{{ \Illuminate\Support\Str::slug($group) }}" class="shrink-0 text-sm font-semibold text-gray-950 dark:text-white">{{ $group }}</h2>
                        <div class="h-px flex-1 bg-gray-200 dark:bg-white/10"></div>
                    </div>

                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($rows as $rowIndex => $row)
                            <div class="py-5" wire:key="target-{{ $row['key'] }}">
                                @foreach ($row['mappings'] as $mappingIndex => $mapping)
                                    @php
                                        $isMapped = filled($mapping['source_claim']);
                                        $modalId = 'mapping-settings-'.md5($group.'-'.$row['key'].'-'.$mappingIndex);
                                    @endphp
                                    <div
                                        class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_4rem_minmax(0,1fr)] lg:items-center {{ $mappingIndex > 0 ? 'mt-3' : '' }}"
                                        wire:key="mapping-{{ $row['key'] }}-{{ $mapping['id'] ?? 'new-'.$mappingIndex }}"
                                    >
                                        <div class="min-w-0">
                                            <label for="source-{{ $modalId }}" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                                                {{ $mappingIndex === 0 ? 'Source claim' : 'Fallback source claim' }}
                                            </label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    id="source-{{ $modalId }}"
                                                    type="text"
                                                    maxlength="512"
                                                    placeholder="Enter claim path…"
                                                    aria-describedby="relationship-{{ $modalId }}"
                                                    wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.source_claim"
                                                />
                                            </x-filament::input.wrapper>
                                        </div>

                                        <div class="flex items-center justify-center" aria-hidden="true">
                                            <div class="hidden w-full items-center lg:flex {{ $isMapped ? 'text-primary-500' : 'text-gray-300 dark:text-gray-600' }}">
                                                <span class="w-full border-t {{ $isMapped ? 'border-solid' : 'border-dashed' }} border-current"></span>
                                                <x-filament::icon icon="tabler-chevron-right" class="-ml-1 h-5 w-5 shrink-0" />
                                            </div>
                                            <x-filament::icon icon="tabler-arrow-down" class="h-5 w-5 text-gray-400 lg:hidden" />
                                        </div>

                                        <div class="flex min-w-0 items-center justify-between gap-3">
                                            <div class="min-w-0" @if (filled($row['description'] ?? null)) title="{{ $row['description'] }}" @endif>
                                                <div class="flex items-center gap-2">
                                                    <p class="font-semibold text-gray-950 dark:text-white">
                                                        {{ $mappingIndex === 0 ? $row['label'] : 'Fallback for '.$row['label'] }}
                                                    </p>
                                                    @if (filled($row['description'] ?? null))
                                                        <x-filament::icon icon="tabler-info-circle" class="h-4 w-4 text-gray-400" />
                                                    @endif
                                                </div>
                                                <p id="relationship-{{ $modalId }}" class="truncate text-xs text-gray-500 dark:text-gray-400">
                                                    <span class="font-mono">{{ $row['key'] }}</span> <span aria-hidden="true">·</span> {{ $row['type'] }}
                                                    @if ($mappingIndex > 0)<span class="ml-2">Priority {{ $mapping['priority'] }}</span>@endif
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
                                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Source: {{ $mapping['source_claim'] ?: 'Not mapped' }}</p>
                                            </div>
                                            <label class="flex items-center gap-3 text-sm font-medium text-gray-950 dark:text-white">
                                                <input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5" wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.enabled">
                                                Enabled
                                            </label>
                                            <label class="block space-y-1.5 text-sm font-medium text-gray-950 dark:text-white">
                                                <span>Missing claim behaviour</span>
                                                <x-filament::input.wrapper>
                                                    <x-filament::input.select wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.missing_claim_behavior">
                                                        <option value="preserve">Preserve existing value</option>
                                                        <option value="clear">Clear when supported</option>
                                                    </x-filament::input.select>
                                                </x-filament::input.wrapper>
                                            </label>
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
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    No identity-writable user attributes are currently registered.
                </div>
            @endforelse
        </div>

        @if ($unavailable)
            <section aria-labelledby="unavailable-targets" class="rounded-xl border border-warning-200 bg-warning-50/50 p-4 dark:border-warning-400/20 dark:bg-warning-400/5 sm:p-6">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="tabler-alert-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-warning-500" />
                    <div>
                        <h2 id="unavailable-targets" class="font-semibold text-gray-950 dark:text-white">Unavailable Targets</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Historical mappings whose target is no longer registered or writable.</p>
                    </div>
                </div>
                <div class="mt-4 divide-y divide-warning-200 dark:divide-warning-400/20">
                    @foreach ($unavailable as $index => $mapping)
                        <div class="grid gap-3 py-4 lg:grid-cols-[minmax(0,1fr)_4rem_minmax(0,1fr)] lg:items-center" wire:key="unavailable-{{ $mapping['id'] }}">
                            <x-filament::input.wrapper>
                                <x-filament::input aria-label="Source claim for unavailable target {{ $mapping['target_attribute'] }}" type="text" maxlength="512" wire:model="unavailable.{{ $index }}.source_claim" />
                            </x-filament::input.wrapper>
                            <div class="flex justify-center text-warning-500" aria-hidden="true">
                                <x-filament::icon icon="tabler-arrow-right" class="hidden h-5 w-5 lg:block" />
                                <x-filament::icon icon="tabler-arrow-down" class="h-5 w-5 lg:hidden" />
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-medium text-gray-950 dark:text-white">Target unavailable</p>
                                    <p class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $mapping['target_attribute'] }}</p>
                                </div>
                                <x-filament::icon-button icon="tabler-trash" color="danger" label="Remove historical mapping" x-on:click="dirty = true" wire:click="removeUnavailable({{ $index }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="sticky bottom-4 z-10 flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur dark:border-white/10 dark:bg-gray-900/95 sm:px-6">
            <p x-show="dirty" x-cloak class="flex items-center gap-2 text-sm font-medium text-warning-600 dark:text-warning-400">
                <span class="h-2 w-2 rounded-full bg-current"></span>
                Unsaved changes
            </p>
            <span x-show="! dirty" class="text-sm text-gray-500 dark:text-gray-400">Mappings are saved together for this provider.</span>
            <x-filament::button icon="tabler-device-floppy" wire:click="save" wire:loading.attr="disabled">Save Mappings</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
