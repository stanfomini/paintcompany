<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Pricing Configuration</flux:heading>
            @if (in_array($activeTab, ['interior', 'exterior', 'specialty']))
                <flux:button wire:click="openCategoryModal" icon="plus">
                    Add Category
                </flux:button>
            @elseif ($activeTab === 'substrates')
                <flux:button wire:click="openSubstrateModal" icon="plus" variant="primary">
                    Add Substrate
                </flux:button>
            @elseif ($activeTab === 'paints')
                <div class="flex gap-2">
                    <flux:button wire:click="openBrandModal" icon="building-storefront" variant="ghost">
                        Add Brand
                    </flux:button>
                    <flux:button wire:click="openProductModal" icon="plus" variant="primary">
                        Add Product
                    </flux:button>
                </div>
            @elseif ($activeTab === 'templates')
                <flux:button wire:click="openTemplateModal" icon="plus" variant="primary">
                    Add Template
                </flux:button>
            @endif
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">{{ session('success') }}</flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">{{ session('error') }}</flux:callout>
        @endif

        <!-- Tab Navigation -->
        <div class="border-b border-zinc-200 dark:border-zinc-700 mb-6">
            <nav class="-mb-px flex space-x-8 overflow-x-auto">
                @foreach ([
                    'interior' => 'Interior Rates',
                    'exterior' => 'Exterior Rates',
                    'specialty' => 'Specialty',
                    'substrates' => 'Substrates',
                    'paints' => 'Paint Catalog',
                    'templates' => 'Closets/Baths',
                ] as $tab => $label)
                    <button
                        wire:click="switchTab('{{ $tab }}')"
                        wire:key="tab-{{ $tab }}"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors {{ $activeTab === $tab ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:text-zinc-400 dark:hover:text-zinc-300' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
                {{-- Area Templates link (separate page) --}}
                <a
                    href="{{ route('area-templates.index') }}"
                    wire:navigate
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:text-zinc-400 dark:hover:text-zinc-300"
                >
                    Area Templates
                </a>
            </nav>
        </div>

        {{-- Production Rates Content (Interior/Exterior/Specialty) --}}
        @if (in_array($activeTab, ['interior', 'exterior', 'specialty']))
        @if ($this->categories->isEmpty())
            <!-- Empty State -->
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="calculator" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">No production rates found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    Get started by seeding default rates or adding a category.
                </flux:text>
                <div class="mt-6 flex justify-center gap-3">
                    <flux:button wire:click="seedDefaults" variant="primary" icon="sparkles">
                        Seed Default Rates
                    </flux:button>
                    <flux:button wire:click="openCategoryModal" icon="plus">
                        Add Category
                    </flux:button>
                </div>
            </div>
        @else
            <!-- Categories Grid -->
            <div class="space-y-6">
                @foreach ($this->categories as $category)
                    <div wire:key="category-{{ $category->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <!-- Category Header -->
                        <div class="bg-zinc-50 dark:bg-zinc-800 px-6 py-4 flex items-center justify-between">
                            <div>
                                <flux:heading size="sm">{{ $category->name }}</flux:heading>
                                @if ($category->description)
                                    <flux:text class="text-sm text-zinc-500 mt-1">{{ $category->description }}</flux:text>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:button wire:click="openRateModal(null, {{ $category->id }})" size="sm" icon="plus">
                                    Add Rate
                                </flux:button>
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="openCategoryModal({{ $category->id }})" icon="pencil">
                                            Edit Category
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="confirmDelete('category', {{ $category->id }})" icon="trash" variant="danger">
                                            Delete Category
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>

                        <!-- Rates Table -->
                        @if ($category->rates->isNotEmpty())
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                    <thead class="bg-white dark:bg-zinc-900">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                Name
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                Unit Type
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                Production Rate
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                Hourly Rate
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                Material Cost
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                Default Coats
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                                        @foreach ($category->rates as $rate)
                                            <tr wire:key="rate-{{ $rate->id }}">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <flux:text class="font-medium">{{ $rate->name }}</flux:text>
                                                    @if ($rate->description)
                                                        <flux:text class="text-xs text-zinc-500">{{ Str::limit($rate->description, 50) }}</flux:text>
                                                    @endif
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <flux:badge size="sm">{{ $rate->unit_type_label }}</flux:badge>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                                    <flux:text>{{ number_format($rate->production_rate, 2) }} / hr</flux:text>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                                    <flux:text>${{ number_format($rate->hourly_labor_rate, 2) }}</flux:text>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                                    <flux:text>${{ number_format($rate->material_cost_per_unit, 2) }}</flux:text>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                                    <flux:text>{{ $rate->default_coats }}</flux:text>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                                    <div class="flex items-center justify-end gap-2">
                                                        <flux:button wire:click="openCalculator({{ $rate->id }})" variant="ghost" size="sm" icon="calculator">
                                                            Test
                                                        </flux:button>
                                                        <flux:dropdown>
                                                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                                            <flux:menu>
                                                                <flux:menu.item wire:click="openRateModal({{ $rate->id }})" icon="pencil">
                                                                    Edit
                                                                </flux:menu.item>
                                                                <flux:menu.item wire:click="confirmDelete('rate', {{ $rate->id }})" icon="trash" variant="danger">
                                                                    Delete
                                                                </flux:menu.item>
                                                            </flux:menu>
                                                        </flux:dropdown>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="p-6 text-center">
                                <flux:text class="text-zinc-500">No rates in this category yet.</flux:text>
                                <flux:button wire:click="openRateModal(null, {{ $category->id }})" size="sm" class="mt-2" icon="plus">
                                    Add Rate
                                </flux:button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @endif

        {{-- Substrates Tab Content --}}
        @if ($activeTab === 'substrates')
            @include('livewire.production-rates.partials.substrates')
        @endif

        {{-- Paint Catalog Tab Content --}}
        @if ($activeTab === 'paints')
            @include('livewire.production-rates.partials.paint-catalog')
        @endif

        {{-- Nested Area Templates Tab Content --}}
        @if ($activeTab === 'templates')
            @include('livewire.production-rates.partials.nested-templates')
        @endif
    </div>

    <!-- Category Modal -->
    <flux:modal wire:model="showCategoryModal" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingCategoryId ? 'Edit Category' : 'Add Category' }}
            </flux:heading>

            <form wire:submit="saveCategory" class="space-y-4">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model="categoryName" placeholder="Category name" />
                    @error('categoryName') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="categoryDescription" placeholder="Optional description" rows="3" />
                    @error('categoryDescription') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>

                <div class="flex justify-end gap-3 pt-4">
                    <flux:button type="button" wire:click="$set('showCategoryModal', false)" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveCategory">
                            {{ $editingCategoryId ? 'Update' : 'Create' }}
                        </span>
                        <span wire:loading wire:target="saveCategory">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <!-- Rate Modal -->
    <flux:modal wire:model="showRateModal" class="md:w-[32rem]">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingRateId ? 'Edit Rate' : 'Add Rate' }}
            </flux:heading>

            <form wire:submit="saveRate" class="space-y-4">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model="rateName" placeholder="Rate name" />
                    @error('rateName') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="rateDescription" placeholder="Optional description" rows="2" />
                    @error('rateDescription') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Unit Type</flux:label>
                        <flux:select wire:model="rateUnitType">
                            <option value="sqft">Square Feet</option>
                            <option value="lnft">Linear Feet</option>
                            <option value="unit">Unit</option>
                            <option value="hour">Hour</option>
                        </flux:select>
                        @error('rateUnitType') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>Production Rate (units/hr)</flux:label>
                        <flux:input wire:model="rateProductionRate" type="number" step="0.01" min="0.01" placeholder="300" />
                        @error('rateProductionRate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Hourly Labor Rate ($)</flux:label>
                        <flux:input wire:model="rateHourlyLaborRate" type="number" step="0.01" min="0" placeholder="45.00" />
                        @error('rateHourlyLaborRate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>Material Cost per Unit ($)</flux:label>
                        <flux:input wire:model="rateMaterialCostPerUnit" type="number" step="0.01" min="0" placeholder="0.15" />
                        @error('rateMaterialCostPerUnit') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Default Coats</flux:label>
                        <flux:select wire:model="rateDefaultCoats">
                            @for ($i = 1; $i <= 5; $i++)
                                <option value="{{ $i }}">{{ $i }}</option>
                            @endfor
                        </flux:select>
                        @error('rateDefaultCoats') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>Category</flux:label>
                        <flux:select wire:model="rateCategoryId">
                            <option value="">Select category...</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </flux:select>
                        @error('rateCategoryId') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <flux:button type="button" wire:click="$set('showRateModal', false)" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveRate">
                            {{ $editingRateId ? 'Update' : 'Create' }}
                        </span>
                        <span wire:loading wire:target="saveRate">Saving...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <!-- Delete Confirmation Modal -->
    <flux:modal wire:model="showDeleteModal" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">Confirm Delete</flux:heading>

            <flux:text>
                @if ($deleteType === 'category')
                    Are you sure you want to delete this category? All rates within this category will also be deleted.
                @else
                    Are you sure you want to delete this rate? This action cannot be undone.
                @endif
            </flux:text>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button wire:click="delete" variant="danger" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="delete">Delete</span>
                    <span wire:loading wire:target="delete">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Calculator Modal -->
    <flux:modal wire:model="showCalculatorModal" class="md:w-[32rem]">
        <div class="space-y-6">
            <flux:heading size="lg">PBP Calculator</flux:heading>

            @if ($calculatorResult && $calculatorResult['rate'])
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                    <flux:text class="font-medium">{{ $calculatorResult['rate']->name }}</flux:text>
                    <flux:text class="text-sm text-zinc-500">{{ $calculatorResult['rate']->unit_type_label }} · {{ number_format($calculatorResult['rate']->production_rate, 0) }} {{ $calculatorResult['rate']->unit_type }}/hr</flux:text>
                </div>
            @endif

            <div class="grid grid-cols-3 gap-4">
                <flux:field>
                    <flux:label>Quantity</flux:label>
                    <flux:input wire:model.live.debounce.300ms="calculatorSqft" type="number" step="1" min="1" />
                </flux:field>

                <flux:field>
                    <flux:label>Coats</flux:label>
                    <flux:select wire:model.live="calculatorCoats">
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}">{{ $i }}</option>
                        @endfor
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>Scope</flux:label>
                    <flux:select wire:model.live="calculatorScopeType">
                        <option value="interior">Interior</option>
                        <option value="exterior">Exterior</option>
                    </flux:select>
                </flux:field>
            </div>

            @if ($calculatorResult)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
                    {{-- Labor Section --}}
                    <div class="flex justify-between items-center px-4 py-2">
                        <flux:text class="text-zinc-500 text-sm">Labor Hours</flux:text>
                        <flux:text class="font-medium">{{ number_format($calculatorResult['labor_hours'], 2) }} hrs</flux:text>
                    </div>
                    <div class="flex justify-between items-center px-4 py-2">
                        <flux:text class="text-zinc-500 text-sm">Effective Rate ({{ ucfirst($calculatorResult['scope_type']) }})</flux:text>
                        <flux:text class="font-medium">${{ number_format($calculatorResult['effective_hourly_rate'], 2) }}/hr</flux:text>
                    </div>
                    <div class="flex justify-between items-center px-4 py-2">
                        <flux:text class="text-zinc-500 text-sm">Labor Cost</flux:text>
                        <flux:text class="font-medium">${{ number_format($calculatorResult['labor_cost'], 2) }}</flux:text>
                    </div>

                    {{-- Cleanup & Sundries --}}
                    <div class="flex justify-between items-center px-4 py-2 bg-zinc-50/50 dark:bg-zinc-800/50">
                        <flux:text class="text-zinc-500 text-sm">Cleanup Hours</flux:text>
                        <flux:text class="font-medium">{{ number_format($calculatorResult['cleanup_hours'], 2) }} hrs</flux:text>
                    </div>
                    <div class="flex justify-between items-center px-4 py-2 bg-zinc-50/50 dark:bg-zinc-800/50">
                        <flux:text class="text-zinc-500 text-sm">Cleanup Cost</flux:text>
                        <flux:text class="font-medium">${{ number_format($calculatorResult['cleanup_cost'], 2) }}</flux:text>
                    </div>
                    <div class="flex justify-between items-center px-4 py-2 bg-zinc-50/50 dark:bg-zinc-800/50">
                        <flux:text class="text-zinc-500 text-sm">Sundries Cost</flux:text>
                        <flux:text class="font-medium">${{ number_format($calculatorResult['sundries_cost'], 2) }}</flux:text>
                    </div>

                    {{-- Material --}}
                    <div class="flex justify-between items-center px-4 py-2">
                        <flux:text class="text-zinc-500 text-sm">Material Cost</flux:text>
                        <flux:text class="font-medium">${{ number_format($calculatorResult['material_cost'], 2) }}</flux:text>
                    </div>
                    <div class="flex justify-between items-center px-4 py-2">
                        <flux:text class="text-zinc-500 text-sm">Coat Multiplier</flux:text>
                        <flux:text class="font-medium">{{ $calculatorResult['coat_multiplier'] }}x</flux:text>
                    </div>

                    {{-- Totals --}}
                    <div class="flex justify-between items-center px-4 py-2">
                        <flux:text class="text-zinc-600 dark:text-zinc-400 text-sm font-medium">Base Total</flux:text>
                        <flux:text class="font-medium">${{ number_format($calculatorResult['base_total'], 2) }}</flux:text>
                    </div>
                    <div class="flex justify-between items-center px-4 py-3 bg-zinc-100 dark:bg-zinc-800">
                        <div>
                            <flux:text class="font-semibold">With Margins</flux:text>
                            <flux:text class="text-xs text-zinc-500">Profit + OH + {{ ucfirst($calculatorResult['scope_type']) }} Markup</flux:text>
                        </div>
                        <flux:text class="font-bold text-lg text-emerald-600 dark:text-emerald-400">${{ number_format($calculatorResult['with_margins'], 2) }}</flux:text>
                    </div>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button wire:click="closeCalculator" variant="ghost">
                    Close
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
