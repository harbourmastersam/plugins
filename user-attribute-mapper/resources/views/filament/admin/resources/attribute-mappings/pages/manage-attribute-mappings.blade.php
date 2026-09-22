<x-filament-panels::page>
    <div
        x-data="{ dirty: false }"
        x-on:input="dirty = true"
        x-on:change="dirty = true"
        x-on:mapping-workspace-saved.window="dirty = false"
        x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
        class="space-y-6"
    >
        <x-filament::section>
            <div class="max-w-xl space-y-2">
                <label for="mapping-provider" class="text-sm font-medium text-gray-950 dark:text-white">Identity Provider</label>
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
                <p class="text-sm text-gray-500 dark:text-gray-400">Mappings are saved independently for each provider. Claim paths may use dot notation.</p>
            </div>
        </x-filament::section>

        <div class="hidden grid-cols-[minmax(0,1fr)_3rem_minmax(0,1fr)] gap-4 px-6 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:grid">
            <span>Identity Provider Profile</span>
            <span></span>
            <span>Pelican User Profile</span>
        </div>

        @forelse ($groups as $group => $rows)
            <x-filament::section :heading="$group">
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $rowIndex => $row)
                        <div class="py-5 first:pt-0 last:pb-0" wire:key="target-{{ $row['key'] }}">
                            @foreach ($row['mappings'] as $mappingIndex => $mapping)
                                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_3rem_minmax(0,1fr)] sm:items-center" wire:key="mapping-{{ $row['key'] }}-{{ $mapping['id'] ?? 'new-'.$mappingIndex }}">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400 sm:sr-only">Source claim</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input
                                                type="text"
                                                maxlength="512"
                                                placeholder="Enter claim path"
                                                wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.source_claim"
                                            />
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="flex justify-center text-gray-400" aria-label="Maps to">
                                        <x-filament::icon icon="tabler-arrow-right" class="hidden h-5 w-5 sm:block" />
                                        <x-filament::icon icon="tabler-arrow-down" class="h-5 w-5 sm:hidden" />
                                    </div>

                                    <div class="min-w-0">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-x-2">
                                                    <span class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</span>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['type'] }}</span>
                                                </div>
                                                <p class="truncate font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['key'] }}</p>
                                            </div>
                                            @if (count($row['mappings']) > 1 || filled($mapping['source_claim']))
                                                <x-filament::icon-button
                                                    icon="tabler-trash"
                                                    color="danger"
                                                    label="Remove mapping"
                                                    wire:click="removeMapping({{ \Illuminate\Support\Js::from($group) }}, {{ $rowIndex }}, {{ $mappingIndex }})"
                                                />
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <details class="ml-0 mt-3 sm:ml-[calc(50%+1.5rem)]">
                                    <summary class="cursor-pointer select-none text-sm font-medium text-primary-600 dark:text-primary-400">Advanced options</summary>
                                    <div class="mt-3 grid gap-4 rounded-xl bg-gray-50 p-4 dark:bg-white/5 sm:grid-cols-2">
                                        <label class="flex items-center gap-2 text-sm text-gray-950 dark:text-white">
                                            <input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5" wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.enabled">
                                            Enabled
                                        </label>
                                        <label class="space-y-1 text-sm text-gray-950 dark:text-white">
                                            <span>Missing claim behaviour</span>
                                            <x-filament::input.wrapper>
                                                <x-filament::input.select wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.missing_claim_behavior">
                                                    <option value="preserve">Preserve existing value</option>
                                                    <option value="clear">Clear when supported</option>
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </label>
                                        <label class="space-y-1 text-sm text-gray-950 dark:text-white">
                                            <span>Priority</span>
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="number" min="0" wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.priority" />
                                            </x-filament::input.wrapper>
                                        </label>
                                        <label class="space-y-1 text-sm text-gray-950 dark:text-white sm:col-span-2">
                                            <span>Description</span>
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="text" wire:model="groups.{{ $group }}.{{ $rowIndex }}.mappings.{{ $mappingIndex }}.description" />
                                            </x-filament::input.wrapper>
                                        </label>
                                    </div>
                                </details>

                                @if (!$loop->last)<div class="my-4 border-t border-dashed border-gray-200 dark:border-white/10"></div>@endif
                            @endforeach

                            <button type="button" class="mt-3 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400" wire:click="addMapping({{ \Illuminate\Support\Js::from($group) }}, {{ $rowIndex }})">
                                + Add another source claim
                            </button>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No identity-writable user attributes are currently registered.
                </div>
            </x-filament::section>
        @endforelse

        @if ($unavailable)
            <x-filament::section heading="Unavailable Targets" description="These historical mappings are retained because their target is no longer registered or writable. They can be edited or removed, but new unavailable mappings cannot be created.">
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($unavailable as $index => $mapping)
                        <div class="grid gap-3 py-4 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_3rem_minmax(0,1fr)] sm:items-center" wire:key="unavailable-{{ $mapping['id'] }}">
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" maxlength="512" wire:model="unavailable.{{ $index }}.source_claim" />
                            </x-filament::input.wrapper>
                            <div class="flex justify-center text-gray-400"><x-filament::icon icon="tabler-arrow-right" class="h-5 w-5" /></div>
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <span class="font-medium text-gray-950 dark:text-white">Target unavailable</span>
                                    <p class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $mapping['target_attribute'] }}</p>
                                </div>
                                <x-filament::icon-button icon="tabler-trash" color="danger" label="Remove historical mapping" wire:click="removeUnavailable({{ $index }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div class="flex justify-end">
            <x-filament::button icon="tabler-device-floppy" wire:click="save" wire:loading.attr="disabled">Save Mappings</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
