<div>
    {{-- Header with Total --}}
    <div class="flex items-center justify-between mb-4">
        <div>
            <flux:heading size="lg">Estimate Areas</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                Click to add rooms from templates or create custom areas
            </flux:text>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">Areas Total</div>
                <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">
                    ${{ number_format($this->areasTotal, 2) }}
                </div>
            </div>
            <flux:button wire:click="openQuickAddDrawer" icon="plus">
                Add Room
            </flux:button>
        </div>
    </div>

    {{-- Quick Add Template Buttons --}}
    @if ($this->availableTemplates->isNotEmpty())
        <div class="mb-6">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Quick Add from Templates</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->availableTemplates->flatten() as $template)
                    <flux:button
                        wire:key="quick-tpl-{{ $template->id }}"
                        wire:click="addFromTemplate({{ $template->id }})"
                        wire:loading.attr="disabled"
                        size="sm"
                        variant="ghost"
                        class="border border-zinc-200 dark:border-zinc-700"
                    >
                        <flux:icon name="{{ $template->icon ?? 'squares-2x2' }}" class="w-4 h-4" />
                        {{ $template->name }}
                    </flux:button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Areas List --}}
    <div class="space-y-4">
        @forelse ($this->areas as $area)
            <div wire:key="area-{{ $area->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden">
                {{-- Area Header --}}
                <div
                    class="p-4 bg-zinc-50 dark:bg-zinc-800/50 cursor-pointer flex items-center justify-between"
                    wire:click="toggleAreaExpansion({{ $area->id }})"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon
                            name="chevron-down"
                            class="w-5 h-5 text-zinc-400 transition-transform {{ $this->isAreaExpanded($area->id) ? '' : '-rotate-90' }}"
                        />
                        <div>
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $area->name }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ number_format($area->length) }}' x {{ number_format($area->width) }}' x {{ number_format($area->height) }}'
                                &bull;
                                {{ number_format($area->wall_sqft) }} sqft walls
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($this->calculateAreaTotal($area), 2) }}
                            </div>
                        </div>
                        <flux:button
                            wire:click.stop="deleteArea({{ $area->id }})"
                            wire:confirm="Are you sure you want to delete this area?"
                            variant="ghost"
                            size="sm"
                            class="text-red-500 hover:text-red-700"
                        >
                            <flux:icon name="trash" class="w-4 h-4" />
                        </flux:button>
                    </div>
                </div>

                {{-- Area Expanded Content --}}
                @if ($this->isAreaExpanded($area->id))
                    <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                        {{-- Dimensions --}}
                        <div class="mb-4">
                            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Dimensions</div>
                            <div class="grid grid-cols-3 gap-3">
                                <flux:field>
                                    <flux:label class="text-xs">Length (ft)</flux:label>
                                    <flux:input
                                        wire:change="updateAreaDimension({{ $area->id }}, 'length', $event.target.value)"
                                        value="{{ $area->length }}"
                                        type="number"
                                        step="0.5"
                                        min="1"
                                        size="sm"
                                    />
                                </flux:field>
                                <flux:field>
                                    <flux:label class="text-xs">Width (ft)</flux:label>
                                    <flux:input
                                        wire:change="updateAreaDimension({{ $area->id }}, 'width', $event.target.value)"
                                        value="{{ $area->width }}"
                                        type="number"
                                        step="0.5"
                                        min="1"
                                        size="sm"
                                    />
                                </flux:field>
                                <flux:field>
                                    <flux:label class="text-xs">Height (ft)</flux:label>
                                    <flux:input
                                        wire:change="updateAreaDimension({{ $area->id }}, 'height', $event.target.value)"
                                        value="{{ $area->height }}"
                                        type="number"
                                        step="0.5"
                                        min="1"
                                        size="sm"
                                    />
                                </flux:field>
                            </div>
                            <div class="mt-2 p-2 bg-blue-50 dark:bg-blue-900/20 rounded text-xs">
                                <span class="text-blue-700 dark:text-blue-400">
                                    Wall: {{ number_format($area->wall_sqft, 0) }} sqft |
                                    Ceiling: {{ number_format($area->ceiling_sqft, 0) }} sqft |
                                    Perimeter: {{ number_format($area->perimeter_lnft, 0) }} lnft
                                </span>
                            </div>
                        </div>

                        {{-- Surfaces --}}
                        <div class="mb-4">
                            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Surfaces</div>
                            <div class="space-y-2">
                                @foreach ($area->surfaces as $surface)
                                    <div
                                        wire:key="surface-{{ $surface->id }}"
                                        class="flex items-center gap-3 p-3 rounded-lg {{ $surface->is_included ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700' }}"
                                    >
                                        <flux:checkbox
                                            wire:click="toggleSurface({{ $area->id }}, {{ $surface->id }})"
                                            :checked="$surface->is_included"
                                        />
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $surface->name }}</span>
                                                <span class="text-xs text-zinc-500">
                                                    {{ number_format($surface->quantity, 0) }} {{ $surface->unit }}
                                                </span>
                                            </div>
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                                @if ($surface->productionRate)
                                                    {{ $surface->productionRate->name }}
                                                    ({{ number_format($surface->productionRate->production_rate, 0) }} {{ $surface->productionRate->rate_type_label }})
                                                @else
                                                    <span class="text-amber-600">No rate selected</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            @php
                                                $ratesByCategory = $this->getProductionRatesForScope($area->scope_type);
                                                $surfaceCategory = $surface->productionRate?->surface_category ?? 'walls';
                                                $availableRates = $ratesByCategory[$surfaceCategory] ?? collect();
                                            @endphp
                                            <select
                                                wire:change="updateSurfaceRate({{ $area->id }}, {{ $surface->id }}, $event.target.value)"
                                                class="w-48 text-sm rounded-md border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                            >
                                                <option value="">-- Select Rate --</option>
                                                @foreach ($availableRates as $rate)
                                                    <option wire:key="rate-{{ $surface->id }}-{{ $rate->id }}" value="{{ $rate->id }}" {{ $surface->production_rate_id == $rate->id ? 'selected' : '' }}>
                                                        {{ $rate->name }} ({{ number_format($rate->production_rate, 0) }})
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="text-right w-20">
                                                <span class="font-semibold {{ $surface->is_included ? 'text-green-700 dark:text-green-400' : 'text-zinc-400' }}">
                                                    ${{ number_format($surface->total, 2) }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Nested Items in this Area --}}
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Nested Items</div>
                                <flux:button
                                    wire:click="openNestedItemModal({{ $area->id }})"
                                    size="xs"
                                    variant="ghost"
                                >
                                    <flux:icon name="plus" class="w-3 h-3" />
                                    Add Closet/Bath
                                </flux:button>
                            </div>
                            @if ($area->childAreas->count() > 0)
                                <div class="space-y-2">
                                    @foreach ($area->childAreas as $child)
                                        <div class="flex items-center justify-between p-2 bg-purple-50 dark:bg-purple-900/20 rounded border border-purple-200 dark:border-purple-800">
                                            <div class="flex items-center gap-2">
                                                <flux:icon name="squares-plus" class="w-4 h-4 text-purple-500" />
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $child->name }}</span>
                                                @if ($child->nestedAreaTemplate)
                                                    <span class="text-xs text-purple-600 dark:text-purple-400">
                                                        ({{ $child->nestedAreaTemplate->included_items }})
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-semibold text-purple-700 dark:text-purple-400">
                                                    ${{ number_format($child->fixed_price_amount, 2) }}
                                                </span>
                                                <flux:button
                                                    wire:click="removeNestedItem({{ $area->id }}, {{ $child->id }})"
                                                    variant="ghost"
                                                    size="xs"
                                                    class="text-red-500"
                                                >
                                                    <flux:icon name="x-mark" class="w-3 h-3" />
                                                </flux:button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-xs text-zinc-400 dark:text-zinc-500 italic">
                                    No nested items. Click "Add Closet/Bath" to include fixed-price items.
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center py-12 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-lg">
                <flux:icon name="squares-2x2" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">No areas added yet</flux:heading>
                <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500 mb-4">
                    Click a template button above or add a custom room
                </flux:text>
                <flux:button wire:click="openQuickAddDrawer" icon="plus">
                    Add First Room
                </flux:button>
            </div>
        @endforelse
    </div>

    {{-- Standalone Nested Items Section --}}
    @if ($this->standaloneNestedAreas->count() > 0)
        <div class="mt-6">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Standalone Items</div>
            <div class="space-y-2">
                @foreach ($this->standaloneNestedAreas as $nested)
                    <div class="flex items-center justify-between p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-200 dark:border-purple-800">
                        <div class="flex items-center gap-3">
                            <flux:icon name="squares-plus" class="w-5 h-5 text-purple-500" />
                            <div>
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $nested->name }}</span>
                                @if ($nested->nestedAreaTemplate)
                                    <div class="text-xs text-purple-600 dark:text-purple-400">
                                        {{ $nested->nestedAreaTemplate->included_items }}
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-lg font-bold text-purple-700 dark:text-purple-400">
                                ${{ number_format($nested->fixed_price_amount, 2) }}
                            </span>
                            <flux:button
                                wire:click="removeStandaloneNested({{ $nested->id }})"
                                variant="ghost"
                                size="sm"
                                class="text-red-500"
                            >
                                <flux:icon name="trash" class="w-4 h-4" />
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Add Standalone Nested Button --}}
    <div class="mt-4 flex justify-center">
        <flux:button wire:click="openNestedItemModal" size="sm" variant="ghost">
            <flux:icon name="plus" class="w-4 h-4" />
            Add Standalone Item (Laundry, Pantry, etc.)
        </flux:button>
    </div>

    {{-- Quick Add Drawer --}}
    @if ($showQuickAddDrawer)
        <flux:modal wire:model="showQuickAddDrawer" class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add Custom Room</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        Enter room details or select a template to start from
                    </flux:text>
                </div>

                <flux:field>
                    <flux:label>Room Name</flux:label>
                    <flux:input wire:model="newRoomName" placeholder="e.g., Guest Bedroom" />
                    @error('newRoomName') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Scope</flux:label>
                    <flux:select wire:model="newRoomScope">
                        <option value="interior">Interior</option>
                        <option value="exterior">Exterior</option>
                    </flux:select>
                </flux:field>

                <div class="grid grid-cols-3 gap-3">
                    <flux:field>
                        <flux:label>Length (ft)</flux:label>
                        <flux:input wire:model="newLength" type="number" step="0.5" min="1" placeholder="12" />
                        @error('newLength') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:label>Width (ft)</flux:label>
                        <flux:input wire:model="newWidth" type="number" step="0.5" min="1" placeholder="12" />
                        @error('newWidth') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:label>Height (ft)</flux:label>
                        <flux:input wire:model="newHeight" type="number" step="0.5" min="1" placeholder="9" />
                        @error('newHeight') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                </div>

                {{-- Calculated Preview --}}
                @if ($newLength > 0 && $newWidth > 0 && $newHeight > 0)
                    <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-sm">
                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-1">Calculated</div>
                        <div class="grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <span class="text-zinc-600 dark:text-zinc-400">Walls:</span>
                                <span class="font-semibold">{{ number_format(2 * ($newLength + $newWidth) * $newHeight, 0) }} sqft</span>
                            </div>
                            <div>
                                <span class="text-zinc-600 dark:text-zinc-400">Ceiling:</span>
                                <span class="font-semibold">{{ number_format($newLength * $newWidth, 0) }} sqft</span>
                            </div>
                            <div>
                                <span class="text-zinc-600 dark:text-zinc-400">Perimeter:</span>
                                <span class="font-semibold">{{ number_format(2 * ($newLength + $newWidth), 0) }} lnft</span>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Or Start from Template --}}
                @if ($this->availableTemplates->isNotEmpty())
                    <div>
                        <div class="relative my-4">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="bg-white dark:bg-zinc-900 px-2 text-zinc-500">or start from template</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($this->availableTemplates->flatten()->take(6) as $template)
                                <flux:button
                                    wire:key="drawer-tpl-{{ $template->id }}"
                                    wire:click="addFromTemplate({{ $template->id }})"
                                    size="sm"
                                    variant="ghost"
                                    class="justify-start"
                                >
                                    <flux:icon name="{{ $template->icon ?? 'squares-2x2' }}" class="w-4 h-4" />
                                    {{ $template->name }}
                                </flux:button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="closeQuickAddDrawer" variant="ghost" class="flex-1">
                        Cancel
                    </flux:button>
                    <flux:button wire:click="addCustomRoom" variant="primary" class="flex-1">
                        Add Room
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Nested Item Modal --}}
    @if ($showNestedItemModal)
        <flux:modal wire:model="showNestedItemModal" class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add Nested Item</flux:heading>
                    @if ($this->nestedTemplatesByCategory->isNotEmpty())
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            Select a fixed-price item like a closet or half bath
                        </flux:text>
                    @endif
                </div>

                <flux:field>
                    <flux:label>Select Item</flux:label>
                    <select
                        wire:model="selectedNestedTemplateId"
                        class="w-full rounded-md border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                    >
                        <option value="">-- Choose an item --</option>
                        @foreach ($this->nestedTemplatesByCategory as $category => $templates)
                            <optgroup wire:key="optgroup-{{ $category }}" label="{{ ucfirst($category) }}">
                                @foreach ($templates as $template)
                                    <option wire:key="nested-opt-{{ $template->id }}" value="{{ $template->id }}">
                                        {{ $template->name }} - ${{ number_format($template->fixed_price, 2) }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </flux:field>

                @if ($selectedNestedTemplateId)
                    @php $selectedTemplate = $this->nestedTemplates->find($selectedNestedTemplateId); @endphp
                    @if ($selectedTemplate)
                        <div class="p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                            <div class="flex justify-between items-center mb-2">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $selectedTemplate->name }}</span>
                                <span class="text-lg font-bold text-purple-600 dark:text-purple-400">
                                    ${{ number_format($selectedTemplate->fixed_price, 2) }}
                                </span>
                            </div>
                            @if ($selectedTemplate->description)
                                <div class="text-xs text-zinc-600 dark:text-zinc-400 mb-2">
                                    {{ $selectedTemplate->description }}
                                </div>
                            @endif
                            <div class="text-xs text-purple-600 dark:text-purple-400">
                                Includes: {{ $selectedTemplate->included_items }}
                            </div>
                        </div>
                    @endif
                @endif

                @if (! $targetAreaIdForNested)
                    <div class="p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg text-sm text-amber-700 dark:text-amber-300">
                        <flux:icon name="information-circle" class="w-4 h-4 inline mr-1" />
                        This item will be added as a standalone line item, not nested within a room.
                    </div>
                @endif

                <div class="flex gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="closeNestedItemModal" variant="ghost" class="flex-1">
                        Cancel
                    </flux:button>
                    <flux:button
                        wire:click="addNestedItem"
                        variant="primary"
                        class="flex-1"
                        :disabled="!$selectedNestedTemplateId"
                    >
                        Add Item
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
