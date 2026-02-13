{{-- Substrates Tab Content --}}
<div>
    {{-- Scope Sub-tabs --}}
    <div class="flex gap-2 mb-4">
        <flux:button wire:click="$set('substrateScopeFilter', 'all')" :variant="$substrateScopeFilter === 'all' ? 'primary' : 'ghost'" size="sm">
            {{ __('All') }} ({{ \App\Models\Substrate::count() }})
        </flux:button>
        <flux:button wire:click="$set('substrateScopeFilter', 'interior')" :variant="$substrateScopeFilter === 'interior' ? 'primary' : 'ghost'" size="sm">
            {{ __('Interior') }} ({{ \App\Models\Substrate::where('scope_type', 'interior')->count() }})
        </flux:button>
        <flux:button wire:click="$set('substrateScopeFilter', 'exterior')" :variant="$substrateScopeFilter === 'exterior' ? 'primary' : 'ghost'" size="sm">
            {{ __('Exterior') }} ({{ \App\Models\Substrate::where('scope_type', 'exterior')->count() }})
        </flux:button>
        <flux:button wire:click="$set('substrateScopeFilter', 'both')" :variant="$substrateScopeFilter === 'both' ? 'primary' : 'ghost'" size="sm">
            {{ __('Both') }} ({{ \App\Models\Substrate::where('scope_type', 'both')->count() }})
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <div class="flex-1 min-w-[200px]">
            <flux:input wire:model.live.debounce.300ms="substrateSearch" :placeholder="__('Search substrates...')" icon="magnifying-glass" />
        </div>
        <flux:select wire:model.live="substrateCategoryFilter" class="w-40">
            <option value="all">{{ __('All Categories') }}</option>
            <option value="standard">{{ __('Standard') }}</option>
            <option value="wood">{{ __('Wood') }}</option>
            <option value="masonry">{{ __('Masonry') }}</option>
            <option value="metal">{{ __('Metal') }}</option>
        </flux:select>
    </div>

    {{-- Table --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</th>
                    <th class="text-left px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Code') }}</th>
                    <th class="text-left px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Scope') }}</th>
                    <th class="text-left px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</th>
                    <th class="text-center px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Prep') }}</th>
                    <th class="text-center px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Multipliers') }}</th>
                    <th class="text-right px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Prod Rate') }}</th>
                    <th class="text-center px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Active') }}</th>
                    <th class="text-right px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->substrates as $substrate)
                    <tr wire:key="substrate-{{ $substrate->id }}" class="{{ !$substrate->is_active ? 'opacity-50' : '' }}">
                        <td class="px-4 py-3 font-medium">{{ __($substrate->name) }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $substrate->code }}</td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :variant="$substrate->scope_type === 'exterior' ? 'warning' : 'default'">
                                {{ ucfirst($substrate->scope_type) }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 capitalize">{{ $substrate->category }}</td>
                        <td class="px-4 py-3 text-center text-xs">
                            @if ($substrate->prep_requirements !== 'None')
                                <span class="text-amber-600 dark:text-amber-400">{{ $substrate->prep_requirements }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs">
                            <span class="text-zinc-600 dark:text-zinc-300">
                                {{ $substrate->prep_time_multiplier }}x prep,
                                {{ $substrate->paint_time_multiplier }}x paint
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($substrate->default_production_rate)
                                <span class="font-medium">{{ $substrate->default_production_rate }}</span>
                                <span class="text-xs text-zinc-500">sqft/hr</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:switch wire:click="toggleSubstrateActive({{ $substrate->id }})" :checked="$substrate->is_active" />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button wire:click="openSubstrateModal({{ $substrate->id }})" variant="ghost" size="sm" icon="pencil" />
                                <flux:button wire:click="deleteSubstrate({{ $substrate->id }})" variant="ghost" size="sm" icon="trash" :wire:confirm="__('Are you sure you want to delete this substrate?')" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('No substrates found. Add substrates to define surface conditions that affect prep and paint time.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->substrates->links() }}
    </div>
</div>

{{-- Substrate Modal --}}
<flux:modal wire:model="showSubstrateModal" variant="flyout" class="max-w-lg">
    <form wire:submit="saveSubstrate">
        <flux:heading size="lg" class="mb-6">
            {{ $editingSubstrate ? __('Edit Substrate') : __('Add Substrate') }}
        </flux:heading>

        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="substrateName" :placeholder="__('e.g., Previously Painted - Good')" />
                    <flux:error name="substrateName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Code') }}</flux:label>
                    <flux:input wire:model="substrateCode" :placeholder="__('e.g., PREV-GOOD')" class="uppercase" />
                    <flux:error name="substrateCode" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="substrateDescription" rows="2" :placeholder="__('Description of this surface condition...')" />
                <flux:error name="substrateDescription" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Scope') }}</flux:label>
                    <flux:select wire:model="substrateScopeType">
                        <option value="interior">{{ __('Interior') }}</option>
                        <option value="exterior">{{ __('Exterior') }}</option>
                        <option value="both">{{ __('Both') }}</option>
                    </flux:select>
                    <flux:error name="substrateScopeType" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:select wire:model="substrateCategory">
                        <option value="standard">{{ __('Standard (Drywall/Plaster)') }}</option>
                        <option value="wood">{{ __('Wood') }}</option>
                        <option value="masonry">{{ __('Masonry (Brick/Stucco)') }}</option>
                        <option value="metal">{{ __('Metal') }}</option>
                    </flux:select>
                    <flux:error name="substrateCategory" />
                </flux:field>
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __('Prep Requirements') }}</flux:text>
                <div class="grid grid-cols-2 gap-3">
                    <flux:checkbox wire:model="substrateRequiresWash" :label="__('Requires Wash')" />
                    <flux:checkbox wire:model="substrateRequiresScrape" :label="__('Requires Scrape')" />
                    <flux:checkbox wire:model="substrateRequiresSand" :label="__('Requires Sand')" />
                    <flux:checkbox wire:model="substrateRequiresPrime" :label="__('Requires Prime')" />
                </div>
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __('Time Multipliers') }}</flux:text>
                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Prep Time') }}</flux:label>
                        <flux:input wire:model="substratePrepTimeMultiplier" type="number" step="0.05" min="0.1" max="10" />
                        <flux:error name="substratePrepTimeMultiplier" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Paint Time') }}</flux:label>
                        <flux:input wire:model="substratePaintTimeMultiplier" type="number" step="0.05" min="0.1" max="10" />
                        <flux:error name="substratePaintTimeMultiplier" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Material') }}</flux:label>
                        <flux:input wire:model="substrateMaterialMultiplier" type="number" step="0.05" min="0.1" max="10" />
                        <flux:error name="substrateMaterialMultiplier" />
                    </flux:field>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Default Prod. Rate (sqft/hr)') }}</flux:label>
                    <flux:input wire:model="substrateDefaultProductionRate" type="number" min="1" :placeholder="__('Optional')" />
                    <flux:error name="substrateDefaultProductionRate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Sort Order') }}</flux:label>
                    <flux:input wire:model="substrateSortOrder" type="number" min="0" />
                    <flux:error name="substrateSortOrder" />
                </flux:field>
            </div>

            <flux:checkbox wire:model="substrateIsActive" :label="__('Active')" />
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="closeSubstrateModal" type="button">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="saveSubstrate">{{ $editingSubstrate ? __('Update') : __('Create') }}</span>
                <span wire:loading wire:target="saveSubstrate">{{ __('Saving...') }}</span>
            </flux:button>
        </div>
    </form>
</flux:modal>
