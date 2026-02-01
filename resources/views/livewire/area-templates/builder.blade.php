<div>
    {{-- Header --}}
    <div class="max-w-7xl mx-auto mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" wire:click="cancel" icon="arrow-left">
                    Back
                </flux:button>
                <flux:heading size="xl">
                    @if ($isEditing)
                        Edit Area Template: {{ $template->name }}
                    @else
                        Create Area Template
                    @endif
                </flux:heading>
            </div>
        </div>
    </div>

    @if (session('error'))
        <div class="max-w-7xl mx-auto mb-4">
            <flux:callout variant="danger">
                {{ session('error') }}
            </flux:callout>
        </div>
    @endif

    <div class="max-w-7xl mx-auto">
        <div class="lg:grid lg:grid-cols-12 lg:gap-6">
            {{-- Main Form Column --}}
            <div class="lg:col-span-8 space-y-6">
                {{-- Basic Info Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="sm">Basic Information</flux:heading>
                    </div>
                    <div class="p-4 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Template Name</flux:label>
                                <flux:input wire:model.live.debounce.300ms="name" placeholder="e.g., Living Room" />
                                <flux:error name="name" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Icon</flux:label>
                                <flux:select wire:model="icon">
                                    @foreach ($availableIcons as $iconKey => $iconLabel)
                                        <option value="{{ $iconKey }}">{{ $iconLabel }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="icon" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Description (Optional)</flux:label>
                            <flux:textarea wire:model.blur="description" rows="2" placeholder="Brief description of this template..." />
                            <flux:error name="description" />
                        </flux:field>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <flux:field>
                                <flux:label>Category</flux:label>
                                <flux:select wire:model="templateCategoryId">
                                    @foreach ($this->availableCategories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="templateCategoryId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Scope Type</flux:label>
                                <flux:select wire:model.live="scopeType">
                                    @foreach ($scopeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="scopeType" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Visibility</flux:label>
                                <flux:select wire:model="visibility">
                                    @foreach ($visibilityOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="visibility" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Status</flux:label>
                                <div class="flex items-center gap-2 pt-2">
                                    <flux:switch wire:model="isActive" />
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ $isActive ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                            </flux:field>
                        </div>
                    </div>
                </div>

                {{-- Dimensions Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="sm">
                            @if ($scopeType === 'exterior')
                                Surface Dimensions
                            @else
                                Room Dimensions
                            @endif
                        </flux:heading>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            @if ($scopeType === 'exterior')
                                Enter width and height for the surface being painted (length optional for multi-wall areas)
                            @else
                                Enter room dimensions to calculate wall sqft, ceiling sqft, and perimeter
                            @endif
                        </flux:text>
                    </div>
                    <div class="p-4">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>
                                    Length (ft)
                                    @if ($scopeType === 'exterior')
                                        <span class="text-zinc-400 text-xs font-normal">(optional)</span>
                                    @endif
                                </flux:label>
                                <flux:input
                                    wire:model.live.debounce.200ms="roomLength"
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    max="200"
                                    placeholder="{{ $scopeType === 'exterior' ? 'Leave empty for single side' : '12' }}"
                                />
                                <flux:error name="roomLength" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Width (ft)</flux:label>
                                <flux:input wire:model.live.debounce.200ms="roomWidth" type="number" step="0.5" min="1" max="200" placeholder="12" />
                                <flux:error name="roomWidth" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Height (ft)</flux:label>
                                <flux:input wire:model.live.debounce.200ms="roomHeight" type="number" step="0.5" min="1" max="50" placeholder="9" />
                                <flux:error name="roomHeight" />
                            </flux:field>
                        </div>

                        {{-- Calculated Values --}}
                        <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">Calculated Quantities</div>
                            @if ($scopeType === 'exterior' && (!$roomLength || $roomLength == 0))
                                {{-- Exterior single side mode --}}
                                <div class="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">Surface Sqft:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->wallSqft, 0) }}</span>
                                        <div class="text-xs text-zinc-500">W x H (single side)</div>
                                    </div>
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">Linear Feet:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->perimeterLnft, 0) }} lnft</span>
                                        <div class="text-xs text-zinc-500">Width only</div>
                                    </div>
                                </div>
                            @else
                                {{-- Interior or exterior with length --}}
                                <div class="grid grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">Wall Sqft:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->wallSqft, 0) }}</span>
                                        <div class="text-xs text-zinc-500">2 x (L + W) x H</div>
                                    </div>
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">Ceiling Sqft:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->ceilingSqft, 0) }}</span>
                                        <div class="text-xs text-zinc-500">L x W</div>
                                    </div>
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">Perimeter:</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->perimeterLnft, 0) }} lnft</span>
                                        <div class="text-xs text-zinc-500">2 x (L + W)</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Surfaces Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <div>
                            <flux:heading size="sm">Surfaces</flux:heading>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                Define surfaces and select production rate types from the spreadsheet
                            </flux:text>
                        </div>
                        <flux:button wire:click="addSurface" size="sm" icon="plus">
                            Add Surface
                        </flux:button>
                    </div>
                    <div class="p-4">
                        @if (count($surfaces) === 0)
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                <p>No surfaces defined. Add at least one surface.</p>
                            </div>
                        @else
                            <div class="space-y-4">
                                @foreach ($surfaces as $index => $surface)
                                    <div wire:key="surface-{{ $index }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                                        {{-- Surface Header --}}
                                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <div class="flex flex-col gap-0.5">
                                                    <button
                                                        wire:click="moveSurfaceUp({{ $index }})"
                                                        type="button"
                                                        class="p-0.5 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 {{ $index === 0 ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                        {{ $index === 0 ? 'disabled' : '' }}
                                                    >
                                                        <flux:icon name="chevron-up" class="w-3 h-3" />
                                                    </button>
                                                    <button
                                                        wire:click="moveSurfaceDown({{ $index }})"
                                                        type="button"
                                                        class="p-0.5 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 {{ $index === count($surfaces) - 1 ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                        {{ $index === count($surfaces) - 1 ? 'disabled' : '' }}
                                                    >
                                                        <flux:icon name="chevron-down" class="w-3 h-3" />
                                                    </button>
                                                </div>
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                                    {{ $surface['name'] ?: 'Surface '.($index + 1) }}
                                                </span>
                                                @if ($surface['is_required'])
                                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">Required</span>
                                                @endif
                                                @if ($surface['production_rate_id'])
                                                    @php $selectedRate = \App\Models\ProductionRate::find($surface['production_rate_id']); @endphp
                                                    @if ($selectedRate)
                                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300">
                                                            {{ number_format($selectedRate->production_rate, 0) }} {{ $selectedRate->rate_type_label }}
                                                        </span>
                                                    @endif
                                                @endif
                                            </div>
                                            <flux:button
                                                wire:click="removeSurface({{ $index }})"
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                class="text-red-500 hover:text-red-700 dark:text-red-400"
                                            />
                                        </div>

                                        {{-- Surface Details --}}
                                        <div class="p-4 space-y-4">
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <flux:field>
                                                    <flux:label>Surface Name</flux:label>
                                                    <flux:input wire:model.live.debounce.300ms="surfaces.{{ $index }}.name" placeholder="e.g., Walls" />
                                                    @error("surfaces.{$index}.name") <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                                </flux:field>

                                                <flux:field>
                                                    <flux:label>Surface Category</flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.surface_category">
                                                        @foreach ($this->surfaceCategories as $catKey => $catLabel)
                                                            <option value="{{ $catKey }}">{{ $catLabel }}</option>
                                                        @endforeach
                                                    </flux:select>
                                                </flux:field>
                                            </div>

                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <flux:field>
                                                    <flux:label>
                                                        Production Rate Type
                                                        <span class="text-xs text-zinc-500">(from spreadsheet)</span>
                                                    </flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.production_rate_id">
                                                        <option value="">-- Select Type --</option>
                                                        @foreach ($this->getProductionRatesForCategory($surface['surface_category']) as $rate)
                                                            <option value="{{ $rate->id }}">
                                                                {{ $rate->name }} ({{ number_format($rate->production_rate, 0) }} {{ $rate->rate_type_label }})
                                                            </option>
                                                        @endforeach
                                                    </flux:select>
                                                    @if ($this->getProductionRatesForCategory($surface['surface_category'])->isEmpty())
                                                        <p class="text-xs text-amber-600 dark:text-amber-400">No rates found for this category. Run the SpreadsheetProductionRateSeeder.</p>
                                                    @endif
                                                </flux:field>

                                                <flux:field>
                                                    <flux:label>Quantity Source</flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.quantity_source">
                                                        @foreach ($quantitySources as $srcKey => $srcLabel)
                                                            <option value="{{ $srcKey }}">{{ $srcLabel }}</option>
                                                        @endforeach
                                                    </flux:select>
                                                </flux:field>
                                            </div>

                                            @if (in_array($surface['quantity_source'], ['door_count', 'window_count', 'custom']))
                                                <flux:field>
                                                    <flux:label>
                                                        @if ($surface['quantity_source'] === 'door_count')
                                                            Number of Doors
                                                        @elseif ($surface['quantity_source'] === 'window_count')
                                                            Number of Windows
                                                        @else
                                                            Custom Quantity
                                                        @endif
                                                    </flux:label>
                                                    <flux:input wire:model.live.debounce.200ms="surfaces.{{ $index }}.quantity_override" type="number" step="1" min="0" placeholder="1" />
                                                </flux:field>
                                            @endif

                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <flux:field>
                                                    <flux:label>Default Coats</flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.default_coats">
                                                        @for ($i = 1; $i <= 5; $i++)
                                                            <option value="{{ $i }}">{{ $i }} coat{{ $i > 1 ? 's' : '' }}</option>
                                                        @endfor
                                                    </flux:select>
                                                </flux:field>

                                                <flux:field>
                                                    <flux:label>Fixed Price Override (Optional)</flux:label>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-zinc-500">$</span>
                                                        <flux:input wire:model.live.debounce.300ms="surfaces.{{ $index }}.fixed_price" type="number" step="0.01" min="0" placeholder="Leave empty to calculate" />
                                                    </div>
                                                </flux:field>
                                            </div>

                                            <div class="flex items-center gap-6 pt-2">
                                                <label class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="surfaces.{{ $index }}.is_required" />
                                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Required (cannot be disabled)</span>
                                                </label>
                                                <label class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="surfaces.{{ $index }}.is_default_enabled" />
                                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Enabled by default</span>
                                                </label>
                                            </div>

                                            {{-- Quantity Preview --}}
                                            <div class="mt-2 p-2 bg-zinc-100 dark:bg-zinc-800 rounded text-sm">
                                                <span class="text-zinc-600 dark:text-zinc-400">Calculated quantity:</span>
                                                <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">
                                                    {{ number_format($this->getSurfaceQuantity($surface), 1) }}
                                                    @switch($surface['quantity_source'])
                                                        @case('wall_sqft')
                                                        @case('ceiling_sqft')
                                                            sqft
                                                            @break
                                                        @case('perimeter_lnft')
                                                            lnft
                                                            @break
                                                        @default
                                                            units
                                                    @endswitch
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('surfaces') <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Nested Options Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <div>
                            <flux:heading size="sm">Nested Options (Optional)</flux:heading>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                Add fixed-price items like closets or half baths
                            </flux:text>
                        </div>
                        <flux:button wire:click="addNestedOption" size="sm" icon="plus">
                            Add Option
                        </flux:button>
                    </div>
                    <div class="p-4">
                        @if (count($nestedOptions) === 0)
                            <div class="text-center py-6 text-zinc-500 dark:text-zinc-400">
                                <p class="text-sm">No nested options. Click "Add Option" to include fixed-price items.</p>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($nestedOptions as $index => $option)
                                    <div wire:key="nested-{{ $index }}" class="flex items-start gap-3 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                                        <div class="flex-1 grid gap-3 sm:grid-cols-4">
                                            <flux:field class="sm:col-span-2">
                                                <flux:label class="text-xs">Nested Template</flux:label>
                                                <flux:select wire:model.live="nestedOptions.{{ $index }}.nested_area_template_id" size="sm">
                                                    <option value="">-- Select Template --</option>
                                                    @foreach ($this->availableNestedTemplates as $nestedTemplate)
                                                        <option value="{{ $nestedTemplate->id }}">
                                                            {{ $nestedTemplate->name }} (${{ number_format($nestedTemplate->fixed_price, 2) }})
                                                        </option>
                                                    @endforeach
                                                </flux:select>
                                            </flux:field>

                                            <flux:field>
                                                <flux:label class="text-xs">Name Override</flux:label>
                                                <flux:input wire:model.blur="nestedOptions.{{ $index }}.name" placeholder="Optional" size="sm" />
                                            </flux:field>

                                            <flux:field>
                                                <flux:label class="text-xs">Max Quantity</flux:label>
                                                <flux:input wire:model.blur="nestedOptions.{{ $index }}.max_quantity" type="number" min="1" placeholder="Unlimited" size="sm" />
                                            </flux:field>
                                        </div>

                                        <div class="flex items-center gap-2 pt-5">
                                            <label class="flex items-center gap-1.5">
                                                <flux:checkbox wire:model.live="nestedOptions.{{ $index }}.is_default_included" />
                                                <span class="text-xs text-zinc-600 dark:text-zinc-400">Default</span>
                                            </label>
                                            <flux:button
                                                wire:click="removeNestedOption({{ $index }})"
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                class="text-red-500 hover:text-red-700 dark:text-red-400"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Defaults Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="sm">Default Settings</flux:heading>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            Default substrate, paint, and coats for this template
                        </flux:text>
                    </div>
                    <div class="p-4">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>Default Substrate</flux:label>
                                <flux:select wire:model="defaultSubstrateId">
                                    <option value="">-- None --</option>
                                    @foreach ($this->availableSubstrates as $substrate)
                                        <option value="{{ $substrate->id }}">{{ $substrate->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="defaultSubstrateId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Default Paint Product</flux:label>
                                <flux:select wire:model="defaultPaintProductId">
                                    <option value="">-- None --</option>
                                    @foreach ($this->availablePaintProducts as $product)
                                        <option value="{{ $product->id }}">
                                            {{ $product->brand?->name }} {{ $product->name }}
                                        </option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="defaultPaintProductId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Default Coats</flux:label>
                                <flux:select wire:model.live="defaultCoats">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <option value="{{ $i }}">{{ $i }} coat{{ $i > 1 ? 's' : '' }}</option>
                                    @endfor
                                </flux:select>
                                <flux:error name="defaultCoats" />
                            </flux:field>
                        </div>
                    </div>
                </div>

                {{-- Edit Mode Info --}}
                @if ($isEditing && $template)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:heading size="sm">Template Information</flux:heading>
                        </div>
                        <div class="p-4">
                            <dl class="grid gap-4 sm:grid-cols-3 text-sm">
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">Created By</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $template->createdBy?->name ?? 'System' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">Created At</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $template->created_at->format('M j, Y g:i A') }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">Last Updated</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $template->updated_at->format('M j, Y g:i A') }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Preview Sidebar --}}
            <div class="lg:col-span-4 mt-6 lg:mt-0">
                <div class="sticky top-6 space-y-4">
                    {{-- Live Preview Card --}}
                    <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg bg-gradient-to-br from-blue-50 to-white dark:from-blue-950/30 dark:to-zinc-900">
                        <div class="p-4 border-b border-blue-200 dark:border-blue-800">
                            <flux:heading size="sm" class="text-blue-800 dark:text-blue-200">Live Calculator</flux:heading>
                            <flux:text size="sm" class="text-blue-600 dark:text-blue-400">
                                Real-time pricing preview
                            </flux:text>
                        </div>

                        <div class="p-4 space-y-4">
                            {{-- Preview Controls --}}
                            <flux:field>
                                <flux:label class="text-xs">Preview Coats</flux:label>
                                <flux:select wire:model.live="previewCoats" size="sm">
                                    @for ($i = 1; $i <= 4; $i++)
                                        <option value="{{ $i }}">{{ $i }} coat{{ $i > 1 ? 's' : '' }}</option>
                                    @endfor
                                </flux:select>
                            </flux:field>

                            {{-- Preview Results --}}
                            @if ($this->previewCalculation)
                                @php $preview = $this->previewCalculation; @endphp

                                {{-- Dimensions Summary --}}
                                <div class="space-y-2">
                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400">Room Dimensions</div>
                                    <div class="grid grid-cols-3 gap-2 text-xs">
                                        <div class="text-center p-2 bg-blue-100 dark:bg-blue-900/30 rounded">
                                            <div class="font-bold text-lg text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['wall_sqft'] }}</div>
                                            <div class="text-blue-600 dark:text-blue-400">Wall sqft</div>
                                        </div>
                                        <div class="text-center p-2 bg-blue-100 dark:bg-blue-900/30 rounded">
                                            <div class="font-bold text-lg text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['ceiling_sqft'] }}</div>
                                            <div class="text-blue-600 dark:text-blue-400">Ceiling sqft</div>
                                        </div>
                                        <div class="text-center p-2 bg-blue-100 dark:bg-blue-900/30 rounded">
                                            <div class="font-bold text-lg text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['perimeter_lnft'] }}</div>
                                            <div class="text-blue-600 dark:text-blue-400">Perimeter lnft</div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Surfaces Breakdown --}}
                                @if (count($preview['surfaces']) > 0)
                                    <div class="space-y-2">
                                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400">Surfaces</div>
                                        @foreach ($preview['surfaces'] as $surface)
                                            <div class="p-2 bg-white/50 dark:bg-zinc-800/50 rounded text-xs border border-blue-100 dark:border-blue-800">
                                                <div class="flex justify-between items-center">
                                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $surface['name'] }}</span>
                                                    <span class="text-zinc-500 dark:text-zinc-400">
                                                        {{ number_format($surface['quantity'], 0) }} {{ $surface['unit'] }}
                                                    </span>
                                                </div>
                                                <div class="flex justify-between items-center mt-1 text-zinc-500 dark:text-zinc-400">
                                                    <span>{{ $surface['rate_name'] }}</span>
                                                    <span class="font-medium">{{ number_format($surface['hours'], 2) }} hrs</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Nested Breakdown --}}
                                @if (count($preview['nested']) > 0)
                                    <div class="space-y-2">
                                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400">Nested Items</div>
                                        @foreach ($preview['nested'] as $nested)
                                            <div class="flex justify-between items-center text-xs py-1 border-b border-blue-100 dark:border-blue-900">
                                                <span class="text-zinc-700 dark:text-zinc-300">{{ $nested['name'] }}</span>
                                                <span class="text-zinc-500 dark:text-zinc-400">${{ number_format($nested['price'], 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Hours Summary --}}
                                <div class="space-y-2 pt-2 border-t border-blue-200 dark:border-blue-800">
                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400">Hours</div>
                                    <div class="grid grid-cols-3 gap-2 text-xs text-center">
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">Labor</span>
                                            <div class="font-semibold">{{ number_format($preview['totals']['labor_hours'], 1) }}</div>
                                        </div>
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">Cleanup</span>
                                            <div class="font-semibold">{{ number_format($preview['totals']['cleanup_hours'], 1) }}</div>
                                        </div>
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">Total</span>
                                            <div class="font-semibold text-blue-600 dark:text-blue-400">{{ number_format($preview['totals']['total_hours'], 1) }}</div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Cost Breakdown --}}
                                <div class="space-y-2 pt-2 border-t border-blue-200 dark:border-blue-800">
                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400">Cost Breakdown</div>
                                    <div class="space-y-1 text-xs">
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">Labor ({{ $preview['totals']['labor_hours'] }} hrs x ${{ number_format($preview['totals']['hourly_rate'], 2) }}/hr):</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['labor_cost'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">Cleanup:</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['cleanup_cost'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">Sundries:</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['sundries_cost'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">Materials:</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['material_cost'], 2) }}</span>
                                        </div>
                                        @if ($preview['totals']['nested_cost'] > 0)
                                            <div class="flex justify-between">
                                                <span class="text-zinc-600 dark:text-zinc-400">Nested Items:</span>
                                                <span class="font-medium">${{ number_format($preview['totals']['nested_cost'], 2) }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Totals --}}
                                <div class="space-y-2 pt-2 border-t border-blue-200 dark:border-blue-800">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">Base Total:</span>
                                        <span class="font-medium">${{ number_format($preview['totals']['subtotal'], 2) }}</span>
                                    </div>
                                    <div class="flex justify-between pt-2 border-t border-blue-200 dark:border-blue-800">
                                        <span class="text-lg font-bold text-blue-800 dark:text-blue-200">With Margins:</span>
                                        <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                            ${{ number_format($preview['totals']['with_margins'], 2) }}
                                        </span>
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                    <flux:icon name="calculator" class="w-8 h-8 mx-auto mb-2 text-zinc-300 dark:text-zinc-600" />
                                    <p class="text-sm">Add surfaces to see pricing preview</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="space-y-2">
                        <flux:button wire:click="save" variant="primary" wire:loading.attr="disabled" class="w-full">
                            <span wire:loading.remove wire:target="save">
                                {{ $isEditing ? 'Update Template' : 'Create Template' }}
                            </span>
                            <span wire:loading wire:target="save">Saving...</span>
                        </flux:button>
                        <flux:button wire:click="cancel" variant="ghost" class="w-full">
                            Cancel
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
