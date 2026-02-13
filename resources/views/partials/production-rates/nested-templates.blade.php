{{-- Nested Area Templates Tab Content --}}
<div>
    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <div class="flex-1 min-w-[200px]">
            <flux:input wire:model.live.debounce.300ms="templateSearch" :placeholder="__('Search templates...')" icon="magnifying-glass" />
        </div>
        <flux:select wire:model.live="templateCategoryFilter" class="w-40">
            <option value="all">{{ __('All Categories') }}</option>
            <option value="closet">{{ __('Closets') }}</option>
            <option value="bathroom">{{ __('Bathrooms') }}</option>
            <option value="utility">{{ __('Utility Rooms') }}</option>
            <option value="other">{{ __('Other') }}</option>
        </flux:select>
    </div>

    {{-- Cards Grid --}}
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($this->nestedTemplates as $template)
            <div wire:key="template-{{ $template->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden {{ !$template->is_active ? 'opacity-50' : '' }}">
                <div class="bg-zinc-50 dark:bg-zinc-800 px-4 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <flux:text class="font-semibold">{{ __($template->name) }}</flux:text>
                        <flux:badge size="sm" variant="default">{{ ucfirst($template->category) }}</flux:badge>
                    </div>
                    <flux:text class="text-lg font-bold text-emerald-600 dark:text-emerald-400">
                        {{ $template->formatted_price }}
                    </flux:text>
                </div>
                <div class="p-4 space-y-3">
                    @if ($template->description)
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $template->description }}
                        </flux:text>
                    @endif

                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Default Size:') }}</span>
                            <div class="font-medium">
                                {{ number_format($template->default_length, 1) }}' x {{ number_format($template->default_width, 1) }}' x {{ number_format($template->default_height, 1) }}'
                            </div>
                        </div>
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Includes:') }}</span>
                            <div class="font-medium">{{ $template->included_items }}</div>
                        </div>
                    </div>

                    @if ($template->estimated_hours || $template->material_cost)
                        <div class="grid grid-cols-2 gap-2 text-sm pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <div>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Labor Hours:') }}</span>
                                <div class="font-medium">{{ $template->estimated_hours ? number_format($template->estimated_hours, 2) . ' hrs' : '—' }}</div>
                            </div>
                            <div>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Material Cost:') }}</span>
                                <div class="font-medium">{{ $template->material_cost ? '$' . number_format($template->material_cost, 2) : '—' }}</div>
                            </div>
                        </div>
                    @endif

                    @if ($template->substrate || $template->defaultPaintProduct)
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 pt-2 border-t border-zinc-100 dark:border-zinc-800 space-y-1">
                            @if ($template->substrate)
                                <div class="flex items-center gap-1">
                                    <span class="font-medium">{{ __('Substrate:') }}</span>
                                    {{ __($template->substrate->name) }}
                                </div>
                            @endif
                            @if ($template->defaultPaintProduct)
                                <div class="flex items-center gap-1">
                                    <span class="font-medium">{{ __('Paint:') }}</span>
                                    {{ $template->defaultPaintProduct->full_name }}
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="flex items-center justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center gap-2">
                            <flux:text class="text-xs text-zinc-400 font-mono">{{ $template->code }}</flux:text>
                            @if ($template->include_in_package_pricing)
                                <flux:badge size="sm" variant="success">{{ __('Package') }}</flux:badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:switch wire:click="toggleTemplateActive({{ $template->id }})" :checked="$template->is_active" />
                            <flux:button wire:click="duplicateTemplate({{ $template->id }})" variant="ghost" size="xs" icon="document-duplicate" />
                            <flux:button wire:click="openTemplateModal({{ $template->id }})" variant="ghost" size="xs" icon="pencil" />
                            <flux:button wire:click="deleteTemplate({{ $template->id }})" variant="ghost" size="xs" icon="trash" :wire:confirm="__('Are you sure?')" />
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full text-center py-12 text-zinc-500 dark:text-zinc-400">
                {{ __('No templates found. Add fixed-price templates for closets, pantries, doors, and other add-ons.') }}
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $this->nestedTemplates->links() }}
    </div>
</div>

{{-- Template Modal --}}
<flux:modal wire:model="showTemplateModal" variant="flyout" class="max-w-lg">
    <form wire:submit="saveTemplate">
        <flux:heading size="lg" class="mb-6">
            {{ $editingTemplate ? __('Edit Template') : __('Add Template') }}
        </flux:heading>

        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="templateName" :placeholder="__('e.g., Small Closet')" />
                    <flux:error name="templateName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Code') }}</flux:label>
                    <flux:input wire:model="templateCode" :placeholder="__('e.g., CLOSET-SM')" class="uppercase" />
                    <flux:error name="templateCode" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="templateDescription" rows="2" :placeholder="__('Description of this template...')" />
                <flux:error name="templateDescription" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:select wire:model="templateCategory">
                        <option value="closet">{{ __('Closet') }}</option>
                        <option value="bathroom">{{ __('Bathroom') }}</option>
                        <option value="utility">{{ __('Utility Room') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </flux:select>
                    <flux:error name="templateCategory" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Scope') }}</flux:label>
                    <flux:select wire:model.live="templateScopeType">
                        <option value="interior">{{ __('Interior') }}</option>
                        <option value="exterior">{{ __('Exterior') }}</option>
                    </flux:select>
                    <flux:error name="templateScopeType" />
                </flux:field>
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __('Pricing & Cost Breakdown') }}</flux:text>
                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    {{ __('Fixed price is what the customer pays. Labor hours and material cost track what goes to painters vs company profit.') }}
                </flux:text>
                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Fixed Price ($)') }}</flux:label>
                        <flux:input wire:model="templateFixedPrice" type="number" step="0.01" min="0" />
                        <flux:error name="templateFixedPrice" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Est. Hours') }}</flux:label>
                        <flux:input wire:model="templateEstimatedHours" type="number" step="0.25" min="0" :placeholder="__('e.g., 1.5')" />
                        <flux:error name="templateEstimatedHours" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Material Cost ($)') }}</flux:label>
                        <flux:input wire:model="templateMaterialCost" type="number" step="0.01" min="0" :placeholder="__('e.g., 15.00')" />
                        <flux:error name="templateMaterialCost" />
                    </flux:field>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Sort Order') }}</flux:label>
                    <flux:input wire:model="templateSortOrder" type="number" min="0" />
                    <flux:error name="templateSortOrder" />
                </flux:field>
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __('PBP Configuration') }}</flux:text>
                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    {{ __('These settings control automatic labor and material calculations when this template is used.') }}
                </flux:text>
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Substrate (Surface Condition)') }}</flux:label>
                        <flux:select wire:model="templateSubstrateId">
                            <option value="">{{ __('-- Auto-select --') }}</option>
                            @foreach ($this->availableSubstrates as $substrate)
                                <option value="{{ $substrate->id }}">
                                    {{ __($substrate->name) }} ({{ $substrate->prep_time_multiplier }}x prep, {{ $substrate->paint_time_multiplier }}x paint)
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="templateSubstrateId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Default Paint Product') }}</flux:label>
                        <flux:select wire:model="templateDefaultPaintProductId">
                            <option value="">{{ __('-- None --') }}</option>
                            @foreach ($this->availablePaintProducts as $product)
                                <option value="{{ $product->id }}">
                                    {{ $product->full_name }} ({{ ucfirst($product->sheen) }})
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="templateDefaultPaintProductId" />
                    </flux:field>
                </div>
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __('Default Dimensions (ft)') }}</flux:text>
                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Length') }}</flux:label>
                        <flux:input wire:model="templateDefaultLength" type="number" step="0.5" min="0.5" />
                        <flux:error name="templateDefaultLength" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Width') }}</flux:label>
                        <flux:input wire:model="templateDefaultWidth" type="number" step="0.5" min="0.5" />
                        <flux:error name="templateDefaultWidth" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Height') }}</flux:label>
                        <flux:input wire:model="templateDefaultHeight" type="number" step="0.5" min="1" />
                        <flux:error name="templateDefaultHeight" />
                    </flux:field>
                </div>
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __("What's Included") }}</flux:text>
                <div class="grid grid-cols-2 gap-3">
                    <flux:checkbox wire:model="templateIncludesWalls" :label="__('Walls')" />
                    <flux:checkbox wire:model="templateIncludesCeiling" :label="__('Ceiling')" />
                    <flux:checkbox wire:model="templateIncludesTrim" :label="__('Trim')" />
                    <flux:checkbox wire:model="templateIncludesDoor" :label="__('Door')" />
                </div>
            </div>

            <div class="space-y-2">
                <flux:checkbox wire:model="templateIsActive" :label="__('Active')" />
                <flux:checkbox wire:model="templateIncludeInPackagePricing" :label="__('Include in Package Pricing')" />
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="closeTemplateModal" type="button">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="saveTemplate">{{ $editingTemplate ? __('Update') : __('Create') }}</span>
                <span wire:loading wire:target="saveTemplate">{{ __('Saving...') }}</span>
            </flux:button>
        </div>
    </form>
</flux:modal>
