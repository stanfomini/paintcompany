<div>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg">
        <div class="flex items-center justify-between p-4 border-b border-zinc-200 dark:border-zinc-700">
            <flux:heading size="sm">Options</flux:heading>
            <flux:button wire:click="openModal" type="button" variant="ghost" size="sm" icon="plus">
                Add Option
            </flux:button>
        </div>

        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($options as $option)
                <div wire:key="option-{{ $option['id'] }}" class="p-4">
                    <div class="flex items-start gap-4">
                        <div class="pt-1">
                            <flux:checkbox
                                wire:click="toggleSelected({{ $option['id'] }})"
                                :checked="$option['is_selected']"
                            />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <flux:text class="font-medium {{ $option['is_selected'] ? '' : 'text-zinc-500 dark:text-zinc-400' }}">
                                    {{ $option['name'] }}
                                </flux:text>

                                @if ($option['is_recommended'])
                                    <flux:badge color="green" size="sm">Recommended</flux:badge>
                                @endif

                                <flux:badge color="zinc" size="sm">
                                    {{ $this->getPricingTypeLabel($option['pricing_type']) }}
                                </flux:badge>
                            </div>

                            @if ($option['description'])
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-1">
                                    {{ $option['description'] }}
                                </flux:text>
                            @endif

                            @if ($option['surface_name'] && $option['area_name'])
                                <flux:text size="xs" class="text-zinc-400 dark:text-zinc-500">
                                    Based on: {{ $option['area_name'] }} - {{ $option['surface_name'] }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="text-right">
                            <flux:text class="font-semibold {{ $option['is_selected'] ? 'text-green-600 dark:text-green-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                ${{ number_format($option['calculated_price'], 2) }}
                            </flux:text>
                        </div>

                        <div class="flex items-center gap-1">
                            <flux:button
                                wire:click="openModal({{ $option['id'] }})"
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="pencil"
                            />
                            <flux:button
                                wire:click="delete({{ $option['id'] }})"
                                wire:confirm="Are you sure you want to delete this option?"
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                            />
                        </div>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center">
                    <flux:icon name="list-bullet" class="w-12 h-12 mx-auto text-zinc-400 dark:text-zinc-500 mb-3" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">No options added yet</flux:text>
                    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500 mt-1">
                        Options allow customers to add additional services to their estimate.
                    </flux:text>
                </div>
            @endforelse
        </div>

        @if (count($options) > 0)
            <div class="flex justify-between items-center p-4 bg-zinc-50 dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700 rounded-b-lg">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ collect($options)->where('is_selected', true)->count() }} of {{ count($options) }} options selected
                </flux:text>
                <div class="text-right">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Selected Options Total</flux:text>
                    <flux:text class="text-lg font-bold">${{ number_format($this->optionsTotal, 2) }}</flux:text>
                </div>
            </div>
        @endif
    </div>

    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingOptionId ? 'Edit Option' : 'Add Option' }}
            </flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input
                        wire:model="optionName"
                        placeholder="e.g., Premium Paint Upgrade"
                    />
                    <flux:error name="optionName" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea
                        wire:model="optionDescription"
                        rows="2"
                        placeholder="Describe what this option includes..."
                    />
                    <flux:error name="optionDescription" />
                </flux:field>

                <flux:field>
                    <flux:label>Pricing Type</flux:label>
                    <flux:select wire:model.live="optionPricingType">
                        <option value="fixed">Fixed Price</option>
                        <option value="per_sqft">Per Square Foot</option>
                        <option value="per_surface">Per Surface (Multiplier)</option>
                    </flux:select>
                    <flux:error name="optionPricingType" />
                </flux:field>

                @if ($optionPricingType === 'fixed')
                    <flux:field>
                        <flux:label>Fixed Price ($)</flux:label>
                        <flux:input
                            wire:model="optionFixedPrice"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                        />
                        <flux:error name="optionFixedPrice" />
                    </flux:field>
                @else
                    <flux:field>
                        <flux:label>Surface</flux:label>
                        <flux:select wire:model="optionSurfaceId">
                            <option value="">Select a surface...</option>
                            @foreach ($this->surfaces as $surface)
                                <option value="{{ $surface['id'] }}">
                                    {{ $surface['name'] }} ({{ number_format($surface['quantity'], 0) }} sqft)
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="optionSurfaceId" />
                        @if ($this->surfaces->isEmpty())
                            <flux:text size="sm" class="text-amber-600 dark:text-amber-400 mt-1">
                                No surfaces available. Add areas with surfaces first.
                            </flux:text>
                        @endif
                    </flux:field>

                    <flux:field>
                        <flux:label>
                            @if ($optionPricingType === 'per_sqft')
                                Price Per Square Foot ($)
                            @else
                                Multiplier
                            @endif
                        </flux:label>
                        <flux:input
                            wire:model="optionMultiplier"
                            type="number"
                            step="0.001"
                            min="0"
                            placeholder="{{ $optionPricingType === 'per_sqft' ? '0.50' : '1.5' }}"
                        />
                        <flux:error name="optionMultiplier" />
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-1">
                            @if ($optionPricingType === 'per_sqft')
                                Price = Square footage x Price per sqft
                            @else
                                Price = Surface base price x Multiplier
                            @endif
                        </flux:text>
                    </flux:field>
                @endif

                <div class="flex items-center gap-2">
                    <flux:switch wire:model="optionIsRecommended" />
                    <flux:label>Mark as Recommended</flux:label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closeModal" type="button" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button wire:click="save" type="button" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">
                        {{ $editingOptionId ? 'Update Option' : 'Add Option' }}
                    </span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
