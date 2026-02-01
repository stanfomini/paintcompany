<div>
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                @if ($isEditing)
                    <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate>
                        Back
                    </flux:button>
                @else
                    <flux:button variant="ghost" :href="route('deals.show', $deal)" icon="arrow-left" wire:navigate>
                        Back to Deal
                    </flux:button>
                @endif
                <flux:heading size="xl">
                    @if ($isEditing)
                        Edit Estimate: {{ $estimate->estimate_number }}
                    @else
                        Create Estimate
                    @endif
                </flux:heading>
            </div>
        </div>

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        <form wire:submit.prevent="saveAsDraft">
            <div class="space-y-6">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Deal Information</flux:heading>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Deal</dt>
                            <dd class="mt-1">
                                <flux:text class="font-medium">{{ $deal->title }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customer</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $deal->customer->full_name }}</flux:text>
                            </dd>
                        </div>
                    </div>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Estimate Details</flux:heading>
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Title</flux:label>
                            <flux:input wire:model="title" placeholder="e.g., Interior Painting - Living Room" />
                            <flux:error name="title" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Description</flux:label>
                            <flux:textarea wire:model="description" rows="3" placeholder="Describe the scope of work..." />
                            <flux:error name="description" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Valid Until</flux:label>
                            <flux:input wire:model="validUntil" type="date" />
                            <flux:error name="validUntil" />
                        </flux:field>
                    </div>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">Line Items</flux:heading>
                        <flux:button wire:click="addItem" type="button" variant="ghost" size="sm" icon="plus">
                            Add Item
                        </flux:button>
                    </div>

                    <flux:error name="items" />

                    @if (count($items) > 0)
                        <div class="space-y-4">
                            @foreach ($items as $index => $item)
                                <div wire:key="item-{{ $index }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                                    <div class="flex items-start justify-between mb-4">
                                        <flux:text class="font-medium text-zinc-600 dark:text-zinc-400">Item {{ $index + 1 }}</flux:text>
                                        <div class="flex items-center gap-1">
                                            @if ($index > 0)
                                                <flux:button wire:click="moveItemUp({{ $index }})" type="button" variant="ghost" size="sm" icon="chevron-up" />
                                            @endif
                                            @if ($index < count($items) - 1)
                                                <flux:button wire:click="moveItemDown({{ $index }})" type="button" variant="ghost" size="sm" icon="chevron-down" />
                                            @endif
                                            <flux:button wire:click="removeItem({{ $index }})" type="button" variant="ghost" size="sm" icon="trash" />
                                        </div>
                                    </div>

                                    <div class="grid gap-4">
                                        <flux:field>
                                            <flux:label>Description</flux:label>
                                            <flux:input wire:model="items.{{ $index }}.description" placeholder="e.g., Wall Painting - Living Room" />
                                            <flux:error name="items.{{ $index }}.description" />
                                        </flux:field>

                                        <flux:field>
                                            <flux:label>Details</flux:label>
                                            <flux:textarea wire:model="items.{{ $index }}.details" rows="2" placeholder="Additional details..." />
                                            <flux:error name="items.{{ $index }}.details" />
                                        </flux:field>

                                        <div class="grid gap-4 sm:grid-cols-4">
                                            <flux:field>
                                                <flux:label>Quantity</flux:label>
                                                <flux:input wire:model.live="items.{{ $index }}.quantity" type="number" step="0.01" min="0.01" />
                                                <flux:error name="items.{{ $index }}.quantity" />
                                            </flux:field>

                                            <flux:field>
                                                <flux:label>Unit</flux:label>
                                                <flux:select wire:model="items.{{ $index }}.unit">
                                                    @foreach ($units as $unit)
                                                        <option value="{{ $unit }}">{{ $unit }}</option>
                                                    @endforeach
                                                </flux:select>
                                                <flux:error name="items.{{ $index }}.unit" />
                                            </flux:field>

                                            <flux:field>
                                                <flux:label>Unit Price</flux:label>
                                                <flux:input wire:model.live="items.{{ $index }}.unit_price" type="number" step="0.01" min="0" placeholder="0.00" />
                                                <flux:error name="items.{{ $index }}.unit_price" />
                                            </flux:field>

                                            <div>
                                                <flux:label>Line Total</flux:label>
                                                <div class="mt-2">
                                                    <flux:text class="font-medium">
                                                        ${{ number_format(($items[$index]['quantity'] ?? 0) * ($items[$index]['unit_price'] ?? 0), 2) }}
                                                    </flux:text>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                            <flux:text>No line items added yet.</flux:text>
                        </div>
                    @endif

                    @if (count($items) > 0)
                        <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700 flex justify-end">
                            <div class="text-right">
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Items Subtotal</flux:text>
                                <flux:text class="text-lg font-semibold">${{ number_format($this->itemsSubtotal, 2) }}</flux:text>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Areas Section --}}
                @if ($isEditing && $estimate)
                    {{-- New RoomConfigurator for editing existing estimates --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        @livewire('estimates.room-configurator', ['estimate' => $estimate], key('room-configurator-' . $estimate->id))
                    </div>
                @else
                    {{-- Original area management for new estimates --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <flux:heading size="sm">Areas</flux:heading>
                            <flux:button wire:click="openAreaModal" type="button" variant="ghost" size="sm" icon="plus">
                                Add Area
                            </flux:button>
                        </div>

                        @if (count($areas) > 0)
                            <div class="space-y-4">
                                @foreach ($areas as $areaIndex => $area)
                                    <div wire:key="area-{{ $area['id'] ?? $areaIndex }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                                        {{-- Area Header --}}
                                        <div class="bg-zinc-50 dark:bg-zinc-800 px-4 py-3 flex items-center justify-between">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <flux:text class="font-medium">{{ $area['name'] }}</flux:text>
                                                    <flux:badge size="sm" :variant="($area['scope_type'] ?? 'interior') === 'exterior' ? 'warning' : 'default'">
                                                        {{ ucfirst($area['scope_type'] ?? 'Interior') }}
                                                    </flux:badge>
                                                </div>
                                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                                    {{ number_format($area['length'], 1) }}' x {{ number_format($area['width'], 1) }}' x {{ number_format($area['height'], 1) }}'
                                                    @if (!empty($area['substrate_name']))
                                                        <span class="ml-2 text-amber-600 dark:text-amber-400">• {{ $area['substrate_name'] }}</span>
                                                    @endif
                                                </flux:text>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                @if ($area['id'])
                                                    <flux:button wire:click="openAreaModal({{ $area['id'] }})" type="button" variant="ghost" size="sm" icon="pencil" />
                                                    <flux:button wire:click="deleteArea({{ $area['id'] }})" type="button" variant="ghost" size="sm" icon="trash" wire:confirm="Are you sure you want to delete this area? All surfaces will also be deleted." />
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Area Details --}}
                                        <div class="px-4 py-3 bg-zinc-25 dark:bg-zinc-850">
                                            <div class="grid grid-cols-3 gap-4 text-sm mb-4">
                                                <div>
                                                    <flux:text class="text-zinc-500 dark:text-zinc-400">Wall Sqft</flux:text>
                                                    <flux:text class="font-medium">{{ number_format($area['wall_sqft'], 0) }} sqft</flux:text>
                                                </div>
                                                <div>
                                                    <flux:text class="text-zinc-500 dark:text-zinc-400">Ceiling Sqft</flux:text>
                                                    <flux:text class="font-medium">{{ number_format($area['ceiling_sqft'], 0) }} sqft</flux:text>
                                                </div>
                                                <div>
                                                    <flux:text class="text-zinc-500 dark:text-zinc-400">Floor Sqft</flux:text>
                                                    <flux:text class="font-medium">{{ number_format($area['floor_sqft'], 0) }} sqft</flux:text>
                                                </div>
                                            </div>

                                            {{-- Surfaces Table --}}
                                            @if (count($area['surfaces'] ?? []) > 0)
                                                <div class="overflow-x-auto">
                                                    <table class="w-full text-sm">
                                                        <thead>
                                                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                                                <th class="text-left py-2 font-medium text-zinc-500 dark:text-zinc-400">Surface</th>
                                                                <th class="text-left py-2 font-medium text-zinc-500 dark:text-zinc-400">Type</th>
                                                                <th class="text-right py-2 font-medium text-zinc-500 dark:text-zinc-400">Qty</th>
                                                                <th class="text-right py-2 font-medium text-zinc-500 dark:text-zinc-400">Coats</th>
                                                                <th class="text-right py-2 font-medium text-zinc-500 dark:text-zinc-400">Hours</th>
                                                                <th class="text-left py-2 font-medium text-zinc-500 dark:text-zinc-400">Paint</th>
                                                                <th class="text-right py-2 font-medium text-zinc-500 dark:text-zinc-400">Gal</th>
                                                                <th class="text-right py-2 font-medium text-zinc-500 dark:text-zinc-400">Labor</th>
                                                                <th class="text-right py-2 font-medium text-zinc-500 dark:text-zinc-400">Material</th>
                                                                <th class="text-right py-2 font-medium text-zinc-500 dark:text-zinc-400">Total</th>
                                                                <th class="text-center py-2 font-medium text-zinc-500 dark:text-zinc-400">Include</th>
                                                                <th class="py-2"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($area['surfaces'] as $surfaceIndex => $surface)
                                                                <tr wire:key="surface-{{ $surface['id'] ?? $surfaceIndex }}" class="border-b border-zinc-100 dark:border-zinc-800 {{ !($surface['is_included'] ?? true) ? 'opacity-50' : '' }}">
                                                                    <td class="py-2">{{ $surface['name'] }}</td>
                                                                    <td class="py-2 capitalize">{{ $surfaceTypes[$surface['surface_type']] ?? $surface['surface_type'] }}</td>
                                                                    <td class="py-2 text-right">{{ number_format($surface['quantity'], 0) }} {{ $surface['unit'] }}</td>
                                                                    <td class="py-2 text-right">{{ $surface['coats'] }}</td>
                                                                    <td class="py-2 text-right">{{ number_format($surface['labor_hours'] ?? 0, 1) }} hrs</td>
                                                                    <td class="py-2 text-xs">
                                                                        @if (!empty($surface['paint_brand']))
                                                                            <span class="text-zinc-600 dark:text-zinc-300">{{ ucwords(str_replace('_', ' ', $surface['paint_brand'])) }}</span>
                                                                            <span class="text-zinc-400">{{ $surface['application_method'] === 'spray' ? '(S)' : '(B/R)' }}</span>
                                                                        @else
                                                                            <span class="text-zinc-400">-</span>
                                                                        @endif
                                                                    </td>
                                                                    <td class="py-2 text-right">{{ number_format($surface['paint_gallons'] ?? 0, 0) }}</td>
                                                                    <td class="py-2 text-right">${{ number_format($surface['labor_cost'], 2) }}</td>
                                                                    <td class="py-2 text-right">${{ number_format($surface['material_cost'], 2) }}</td>
                                                                    <td class="py-2 text-right font-medium">${{ number_format($surface['total'], 2) }}</td>
                                                                    <td class="py-2 text-center">
                                                                        @if ($area['id'] && $surface['id'])
                                                                            <flux:switch
                                                                                wire:click="toggleSurfaceIncluded({{ $area['id'] }}, {{ $surface['id'] }})"
                                                                                :checked="$surface['is_included'] ?? true"
                                                                            />
                                                                        @endif
                                                                    </td>
                                                                    <td class="py-2">
                                                                        <div class="flex items-center gap-1 justify-end">
                                                                            @if ($area['id'] && $surface['id'])
                                                                                <flux:button wire:click="openSurfaceModal({{ $area['id'] }}, {{ $surface['id'] }})" type="button" variant="ghost" size="xs" icon="pencil" />
                                                                                <flux:button wire:click="deleteSurface({{ $area['id'] }}, {{ $surface['id'] }})" type="button" variant="ghost" size="xs" icon="trash" wire:confirm="Are you sure you want to delete this surface?" />
                                                                            @endif
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>

                                                    {{-- Area Summary --}}
                                                    @php
                                                        $includedSurfaces = collect($area['surfaces'])->where('is_included', true);
                                                        $areaTotalHours = $includedSurfaces->sum('labor_hours');
                                                        $areaTotalLabor = $includedSurfaces->sum('labor_cost');
                                                        $areaTotalMaterial = $includedSurfaces->sum('material_cost');
                                                        $areaTotal = $includedSurfaces->sum('total');
                                                    @endphp
                                                    <div class="flex flex-wrap justify-between items-center gap-4 text-sm mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 -mx-4 px-4 py-2 -mb-3">
                                                        <div class="flex flex-wrap gap-4">
                                                            <div>
                                                                <span class="text-zinc-500 dark:text-zinc-400">Hours:</span>
                                                                <span class="font-medium ml-1">{{ number_format($areaTotalHours, 1) }} hrs</span>
                                                            </div>
                                                            <div>
                                                                <span class="text-zinc-500 dark:text-zinc-400">Labor:</span>
                                                                <span class="font-medium ml-1">${{ number_format($areaTotalLabor, 2) }}</span>
                                                            </div>
                                                            <div>
                                                                <span class="text-zinc-500 dark:text-zinc-400">Material:</span>
                                                                <span class="font-medium ml-1">${{ number_format($areaTotalMaterial, 2) }}</span>
                                                            </div>
                                                        </div>
                                                        <div class="font-semibold">
                                                            Area Total: ${{ number_format($areaTotal, 2) }}
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="text-center py-4 text-zinc-500 dark:text-zinc-400">
                                                    <flux:text class="text-sm">No surfaces added to this area yet.</flux:text>
                                                </div>
                                            @endif

                                            {{-- Nested Areas (Closets, etc.) --}}
                                            @if (!empty($area['nested_areas']) && count($area['nested_areas']) > 0)
                                                <div class="mt-4 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-2">Nested Areas (Fixed Price)</flux:text>
                                                    <div class="space-y-2">
                                                        @foreach ($area['nested_areas'] as $nested)
                                                            <div wire:key="nested-{{ $nested['id'] ?? $loop->index }}" class="flex items-center justify-between bg-emerald-50 dark:bg-emerald-900/20 rounded-lg px-3 py-2">
                                                                <div class="flex items-center gap-2">
                                                                    <flux:icon name="home" class="size-4 text-emerald-600 dark:text-emerald-400" />
                                                                    <span class="text-sm font-medium">{{ $nested['name'] }}</span>
                                                                    @if (!empty($nested['template_name']))
                                                                        <flux:badge size="sm" variant="default">{{ $nested['template_name'] }}</flux:badge>
                                                                    @endif
                                                                </div>
                                                                <div class="flex items-center gap-3">
                                                                    <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                                                        ${{ number_format($nested['fixed_price_amount'] ?? 0, 2) }}
                                                                    </span>
                                                                    @if ($area['id'] && $nested['id'])
                                                                        <flux:button
                                                                            wire:click="deleteNestedArea({{ $area['id'] }}, {{ $nested['id'] }})"
                                                                            type="button"
                                                                            variant="ghost"
                                                                            size="xs"
                                                                            icon="trash"
                                                                            wire:confirm="Are you sure you want to remove this nested area?"
                                                                        />
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Add Surface Buttons --}}
                                            @if ($area['id'])
                                                <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                                    {{-- Quick Add Surfaces --}}
                                                    <div class="flex gap-2 flex-wrap mb-2">
                                                        <flux:button wire:click="quickAddSurface({{ $area['id'] }}, 'wall')" type="button" variant="ghost" size="sm" icon="rectangle-stack">
                                                            Walls
                                                        </flux:button>
                                                        <flux:button wire:click="quickAddSurface({{ $area['id'] }}, 'ceiling')" type="button" variant="ghost" size="sm" icon="square-2-stack">
                                                            Ceiling
                                                        </flux:button>
                                                        <flux:button wire:click="quickAddSurface({{ $area['id'] }}, 'trim')" type="button" variant="ghost" size="sm" icon="minus">
                                                            Trim
                                                        </flux:button>
                                                    </div>
                                                    <flux:separator class="my-2" />
                                                    <div class="flex gap-2 flex-wrap">
                                                        <flux:button wire:click="openSurfaceModal({{ $area['id'] }})" type="button" variant="ghost" size="sm" icon="plus">
                                                            Add Surface
                                                        </flux:button>
                                                        <flux:button wire:click="openNestedAreaModal({{ $area['id'] }})" type="button" variant="ghost" size="sm" icon="home">
                                                            Add Closet/Bath
                                                        </flux:button>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700 flex justify-end">
                                <div class="text-right">
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Areas Subtotal</flux:text>
                                    <flux:text class="text-lg font-semibold">${{ number_format($this->areasSubtotal, 2) }}</flux:text>
                                </div>
                            </div>
                        @else
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                <flux:text>No areas added yet. Add an area to calculate surfaces based on room dimensions.</flux:text>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="grid gap-6 md:grid-cols-2">
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-4">Pricing</flux:heading>
                        <div class="space-y-4">
                            <flux:field>
                                <flux:label>Tax Rate (%)</flux:label>
                                <flux:input wire:model.live="taxRate" type="number" step="0.01" min="0" max="100" placeholder="0.00" />
                                <flux:error name="taxRate" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Discount Amount ($)</flux:label>
                                <flux:input wire:model.live="discountAmount" type="number" step="0.01" min="0" placeholder="0.00" />
                                <flux:error name="discountAmount" />
                            </flux:field>
                        </div>
                    </div>

                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-4">Summary</flux:heading>
                        <dl class="space-y-3">
                            @if ($this->itemsSubtotal > 0)
                                <div class="flex justify-between">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Items Subtotal</dt>
                                    <dd class="font-medium">${{ number_format($this->itemsSubtotal, 2) }}</dd>
                                </div>
                            @endif
                            @if ($this->areasSubtotal > 0)
                                <div class="flex justify-between">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Areas Subtotal</dt>
                                    <dd class="font-medium">${{ number_format($this->areasSubtotal, 2) }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Subtotal</dt>
                                <dd class="font-medium">${{ number_format($this->subtotal, 2) }}</dd>
                            </div>
                            @if ($taxRate > 0)
                                <div class="flex justify-between">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Tax ({{ number_format($taxRate, 2) }}%)</dt>
                                    <dd class="font-medium">${{ number_format($this->taxAmount, 2) }}</dd>
                                </div>
                            @endif
                            @if ($discountAmount > 0)
                                <div class="flex justify-between">
                                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">Discount</dt>
                                    <dd class="font-medium text-red-600">-${{ number_format($discountAmount, 2) }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                <dt class="text-base font-medium">Total</dt>
                                <dd class="text-lg font-bold">${{ number_format($this->total, 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Notes & Terms</flux:heading>
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Notes</flux:label>
                            <flux:textarea wire:model="notes" rows="3" placeholder="Notes visible to customer..." />
                            <flux:error name="notes" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Terms & Conditions</flux:label>
                            <flux:textarea wire:model="terms" rows="3" placeholder="Payment terms, conditions, etc..." />
                            <flux:error name="terms" />
                        </flux:field>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    @if ($isEditing)
                        <flux:button variant="ghost" :href="route('estimates.show', $estimate)" wire:navigate>
                            Cancel
                        </flux:button>
                    @else
                        <flux:button variant="ghost" :href="route('deals.show', $deal)" wire:navigate>
                            Cancel
                        </flux:button>
                    @endif
                    <flux:button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAsDraft">Save as Draft</span>
                        <span wire:loading wire:target="saveAsDraft">Saving...</span>
                    </flux:button>
                    <flux:button wire:click="saveAndSend" type="button" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAndSend">Save & Mark as Sent</span>
                        <span wire:loading wire:target="saveAndSend">Saving...</span>
                    </flux:button>
                </div>
            </div>
        </form>
    </div>

    {{-- Area Modal --}}
    <flux:modal wire:model="showAreaModal" variant="flyout" class="max-w-lg">
        <form wire:submit="saveArea">
            <flux:heading size="lg" class="mb-6">
                {{ $editingAreaId ? 'Edit Area' : 'Add Area' }}
            </flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Area Name</flux:label>
                    <flux:input wire:model="areaName" placeholder="e.g., Living Room, Master Bedroom" />
                    <flux:error name="areaName" />
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Scope</flux:label>
                        <flux:select wire:model.live="areaScopeType">
                            <option value="interior">Interior</option>
                            <option value="exterior">Exterior</option>
                        </flux:select>
                        <flux:error name="areaScopeType" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Surface Condition</flux:label>
                        <flux:select wire:model="areaSubstrateId">
                            <option value="">Select condition...</option>
                            @foreach ($substrates as $substrate)
                                <option value="{{ $substrate->id }}">
                                    {{ $substrate->name }}
                                    @if ($substrate->prep_time_multiplier > 1.0)
                                        ({{ $substrate->prep_time_multiplier }}x prep)
                                    @endif
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="areaSubstrateId" />
                    </flux:field>
                </div>

                @if ($areaSubstrateId)
                    @php
                        $selectedSubstrate = $substrates->firstWhere('id', $areaSubstrateId);
                    @endphp
                    @if ($selectedSubstrate)
                        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-3 text-sm">
                            <div class="flex items-center gap-2 text-amber-800 dark:text-amber-200 mb-1">
                                <flux:icon name="exclamation-triangle" class="size-4" />
                                <span class="font-medium">{{ $selectedSubstrate->name }}</span>
                            </div>
                            <div class="text-amber-700 dark:text-amber-300 text-xs">
                                @if ($selectedSubstrate->prep_requirements !== 'None')
                                    <span>Prep: {{ $selectedSubstrate->prep_requirements }}</span>
                                @endif
                                @if ($selectedSubstrate->prep_time_multiplier > 1.0 || $selectedSubstrate->paint_time_multiplier > 1.0)
                                    <span class="ml-2">
                                        Multipliers: {{ $selectedSubstrate->prep_time_multiplier }}x prep, {{ $selectedSubstrate->paint_time_multiplier }}x paint
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Length (ft)</flux:label>
                        <flux:input wire:model.live="areaLength" type="number" step="0.1" min="0.1" placeholder="0.0" />
                        <flux:error name="areaLength" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Width (ft)</flux:label>
                        <flux:input wire:model.live="areaWidth" type="number" step="0.1" min="0.1" placeholder="0.0" />
                        <flux:error name="areaWidth" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Height (ft)</flux:label>
                        <flux:input wire:model.live="areaHeight" type="number" step="0.1" min="0.1" placeholder="0.0" />
                        <flux:error name="areaHeight" />
                    </flux:field>
                </div>

                {{-- Calculated Preview --}}
                @if ($calculatedAreaSqft['wall_sqft'] > 0)
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                        <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-2">Calculated Square Footage</flux:text>
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Wall Sqft</flux:text>
                                <flux:text class="font-semibold">{{ number_format($calculatedAreaSqft['wall_sqft'], 0) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Ceiling Sqft</flux:text>
                                <flux:text class="font-semibold">{{ number_format($calculatedAreaSqft['ceiling_sqft'], 0) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Floor Sqft</flux:text>
                                <flux:text class="font-semibold">{{ number_format($calculatedAreaSqft['floor_sqft'], 0) }}</flux:text>
                            </div>
                        </div>
                    </div>
                @endif

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="areaNotes" rows="2" placeholder="Additional notes about this area..." />
                    <flux:error name="areaNotes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAreaModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveArea">{{ $editingAreaId ? 'Update Area' : 'Add Area' }}</span>
                    <span wire:loading wire:target="saveArea">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Surface Modal --}}
    <flux:modal wire:model="showSurfaceModal" variant="flyout" class="max-w-lg">
        <form wire:submit="saveSurface">
            <flux:heading size="lg" class="mb-6">
                {{ $editingSurfaceId ? 'Edit Surface' : 'Add Surface' }}
            </flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Surface Name</flux:label>
                    <flux:input wire:model="surfaceName" placeholder="e.g., Walls, Ceiling, Trim" />
                    <flux:error name="surfaceName" />
                </flux:field>

                <flux:field>
                    <flux:label>Surface Type</flux:label>
                    <flux:select wire:model.live="surfaceType">
                        @foreach ($surfaceTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="surfaceType" />
                </flux:field>

                <flux:field>
                    <flux:label>Production Rate</flux:label>
                    {{-- Scope Type Filter --}}
                    <div class="flex gap-1 mb-2">
                        @foreach ($this->getScopeTypeOptions() as $value => $label)
                            <flux:button
                                wire:click="$set('ratesScopeFilter', '{{ $value }}')"
                                type="button"
                                size="xs"
                                :variant="$ratesScopeFilter === $value ? 'primary' : 'ghost'"
                            >
                                {{ $label }}
                            </flux:button>
                        @endforeach
                    </div>
                    <flux:select wire:model.live="surfaceRateId">
                        <option value="">Select a production rate...</option>
                        @foreach ($productionRateCategories as $category)
                            <optgroup label="{{ $category->name }} ({{ $category->type_label }})">
                                @foreach ($category->rates as $rate)
                                    <option value="{{ $rate->id }}">
                                        {{ $rate->name }} - {{ $rate->production_rate }} {{ $rate->unit_type_label }}/hr @ ${{ number_format($rate->hourly_labor_rate, 2) }}/hr
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </flux:select>
                    <flux:error name="surfaceRateId" />
                </flux:field>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Quantity</flux:label>
                        <flux:input wire:model.live="surfaceQuantity" type="number" step="0.01" min="0.01" placeholder="0.00" />
                        <flux:error name="surfaceQuantity" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Unit</flux:label>
                        <flux:input wire:model="surfaceUnit" disabled />
                    </flux:field>

                    <flux:field>
                        <flux:label>Coats</flux:label>
                        <flux:select wire:model.live="surfaceCoats">
                            @for ($i = 1; $i <= 5; $i++)
                                <option value="{{ $i }}">{{ $i }} coat{{ $i > 1 ? 's' : '' }}</option>
                            @endfor
                        </flux:select>
                        <flux:error name="surfaceCoats" />
                    </flux:field>
                </div>

                {{-- Paint Configuration --}}
                <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4 mt-4">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-3">Paint Configuration</flux:text>

                    <flux:field class="mb-4">
                        <flux:label>Application Method</flux:label>
                        <flux:select wire:model.live="surfaceApplicationMethod">
                            @php $multipliers = $this->getCoverageMultipliers(); @endphp
                            <option value="brush_roll">Brush/Roll ({{ $multipliers['brush_roll'] }}x coverage)</option>
                            <option value="spray">Spray ({{ $multipliers['spray'] }}x coverage)</option>
                        </flux:select>
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Paint Brand</flux:label>
                            <flux:select wire:model.live="surfacePaintBrand">
                                @foreach ($this->getPaintBrands() as $brand)
                                    <option value="{{ $brand }}">{{ ucwords(str_replace('_', ' ', $brand)) }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label>Paint Type</flux:label>
                            <flux:select wire:model.live="surfacePaintType">
                                @foreach ($this->getPaintTypesForBrand() as $type)
                                    <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                    </div>

                    @if ($selectedPaint = $this->getSelectedPaintDetails())
                        <div class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                            ${{ number_format($selectedPaint['price'], 2) }}/gallon | {{ $selectedPaint['coverage_sqft'] }} sqft/gallon base coverage
                        </div>
                    @endif
                </div>

                {{-- Calculated Preview (PBP Formula) --}}
                @if ($calculatedSurfaceCosts['with_margins'] > 0)
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                        <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-2">PBP Cost Breakdown</flux:text>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Labor Hours</flux:text>
                                <flux:text class="font-medium">{{ number_format($calculatedSurfaceCosts['labor_hours'], 2) }} hrs</flux:text>
                            </div>
                            <div class="flex justify-between">
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Labor Cost</flux:text>
                                <flux:text class="font-medium">${{ number_format($calculatedSurfaceCosts['labor_cost'], 2) }}</flux:text>
                            </div>
                            <div class="flex justify-between">
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Cleanup ({{ $calculatedSurfaceCosts['cleanup_hours'] }} hrs)</flux:text>
                                <flux:text class="font-medium">${{ number_format($calculatedSurfaceCosts['cleanup_cost'], 2) }}</flux:text>
                            </div>
                            <div class="flex justify-between">
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Sundries</flux:text>
                                <flux:text class="font-medium">${{ number_format($calculatedSurfaceCosts['sundries_cost'], 2) }}</flux:text>
                            </div>
                            <div class="flex justify-between">
                                <flux:text class="text-zinc-500 dark:text-zinc-400">Material Cost</flux:text>
                                <flux:text class="font-medium">${{ number_format($calculatedSurfaceCosts['material_cost'], 2) }}</flux:text>
                            </div>
                            <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:text class="font-medium">Base Total</flux:text>
                                <flux:text class="font-bold">${{ number_format($calculatedSurfaceCosts['base_total'], 2) }}</flux:text>
                            </div>
                            <div class="flex justify-between">
                                <flux:text class="font-medium text-emerald-600 dark:text-emerald-400">With Margins</flux:text>
                                <flux:text class="font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($calculatedSurfaceCosts['with_margins'], 2) }}</flux:text>
                            </div>
                        </div>

                        {{-- Paint Cost Breakdown --}}
                        @if ($calculatedSurfaceCosts['paint_gallons'] > 0)
                            <div class="mt-4 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-2">Paint Material</flux:text>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <flux:text class="text-zinc-500 dark:text-zinc-400">Gallons Needed</flux:text>
                                        <flux:text class="font-medium">{{ $calculatedSurfaceCosts['paint_gallons'] }} gal</flux:text>
                                    </div>
                                    <div class="flex justify-between">
                                        <flux:text class="text-zinc-500 dark:text-zinc-400">Paint Cost</flux:text>
                                        <flux:text class="font-medium">${{ number_format($calculatedSurfaceCosts['paint_cost'], 2) }}</flux:text>
                                    </div>
                                    <div class="flex justify-between">
                                        <flux:text class="text-zinc-500 dark:text-zinc-400">Recycle Fee</flux:text>
                                        <flux:text class="font-medium">${{ number_format($calculatedSurfaceCosts['paint_recycle_fee'], 2) }}</flux:text>
                                    </div>
                                    <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                        <flux:text class="font-medium">Paint Total</flux:text>
                                        <flux:text class="font-bold text-blue-600 dark:text-blue-400">${{ number_format($calculatedSurfaceCosts['paint_total'], 2) }}</flux:text>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeSurfaceModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveSurface">{{ $editingSurfaceId ? 'Update Surface' : 'Add Surface' }}</span>
                    <span wire:loading wire:target="saveSurface">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Nested Area Template Modal --}}
    <flux:modal wire:model="showNestedAreaModal" variant="flyout" class="max-w-md">
        <form wire:submit="addNestedAreaFromTemplate">
            <flux:heading size="lg" class="mb-6">
                Add Nested Area
            </flux:heading>

            <div class="space-y-4">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    Select a template to add a fixed-price nested area (closet, half bath, etc.) to this room.
                </flux:text>

                <flux:field>
                    <flux:label>Template</flux:label>
                    <flux:select wire:model="selectedTemplateId">
                        <option value="">Select a template...</option>
                        @php
                            $templateCategories = $nestedAreaTemplates->groupBy('category');
                        @endphp
                        @foreach ($templateCategories as $category => $templates)
                            <optgroup label="{{ ucfirst($category) }}s">
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}">
                                        {{ $template->name }} - ${{ number_format($template->fixed_price, 2) }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </flux:select>
                </flux:field>

                @if ($selectedTemplateId)
                    @php
                        $selectedTemplate = $nestedAreaTemplates->firstWhere('id', $selectedTemplateId);
                    @endphp
                    @if ($selectedTemplate)
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <flux:text class="font-medium">{{ $selectedTemplate->name }}</flux:text>
                                <flux:text class="text-lg font-bold text-emerald-600 dark:text-emerald-400">
                                    ${{ number_format($selectedTemplate->fixed_price, 2) }}
                                </flux:text>
                            </div>
                            @if ($selectedTemplate->description)
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mb-2">
                                    {{ $selectedTemplate->description }}
                                </flux:text>
                            @endif
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 space-y-1">
                                <div>
                                    <span class="font-medium">Includes:</span>
                                    {{ $selectedTemplate->included_items }}
                                </div>
                                <div>
                                    <span class="font-medium">Default Size:</span>
                                    {{ number_format($selectedTemplate->default_length, 1) }}' x {{ number_format($selectedTemplate->default_width, 1) }}' x {{ number_format($selectedTemplate->default_height, 1) }}'
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeNestedAreaModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" :disabled="!$selectedTemplateId">
                    <span wire:loading.remove wire:target="addNestedAreaFromTemplate">Add Nested Area</span>
                    <span wire:loading wire:target="addNestedAreaFromTemplate">Adding...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
