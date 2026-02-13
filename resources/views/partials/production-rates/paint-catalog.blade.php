{{-- Paint Catalog Tab Content --}}
<div>
    {{-- Brands Summary --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        @foreach ($this->paintBrands as $brand)
            <div wire:key="brand-{{ $brand->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 {{ !$brand->is_active ? 'opacity-50' : '' }}">
                <div class="flex items-center justify-between mb-2">
                    <flux:text class="font-semibold">{{ $brand->name }}</flux:text>
                    <flux:button wire:click="openBrandModal({{ $brand->id }})" variant="ghost" size="xs" icon="pencil" />
                </div>
                <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400">
                    <span>{{ $brand->products_count }} {{ __('products') }}</span>
                    <span class="font-mono text-xs">{{ $brand->code }}</span>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <div class="flex-1 min-w-[200px]">
            <flux:input wire:model.live.debounce.300ms="paintSearch" :placeholder="__('Search products...')" icon="magnifying-glass" />
        </div>
        <flux:select wire:model.live="paintBrandFilter" class="w-44">
            <option value="all">{{ __('All Brands') }}</option>
            @foreach ($this->paintBrands as $brand)
                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="paintScopeFilter" class="w-36">
            <option value="all">{{ __('All Scopes') }}</option>
            <option value="interior">{{ __('Interior') }}</option>
            <option value="exterior">{{ __('Exterior') }}</option>
        </flux:select>
        <flux:select wire:model.live="paintTypeFilter" class="w-36">
            <option value="all">{{ __('All Types') }}</option>
            <option value="paint">{{ __('Paint') }}</option>
            <option value="primer">{{ __('Primer') }}</option>
            <option value="stain">{{ __('Stain') }}</option>
            <option value="sealer">{{ __('Sealer') }}</option>
        </flux:select>
    </div>

    {{-- Products Table --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Product') }}</th>
                    <th class="text-left px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Brand') }}</th>
                    <th class="text-left px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Sheen') }}</th>
                    <th class="text-center px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Scope') }}</th>
                    <th class="text-right px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('$/Gallon') }}</th>
                    <th class="text-center px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Coverage') }}</th>
                    <th class="text-center px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Active') }}</th>
                    <th class="text-right px-4 py-3 font-medium text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->paintProducts as $product)
                    <tr wire:key="product-{{ $product->id }}" class="{{ !$product->is_active ? 'opacity-50' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $product->name }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">{{ $product->code }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $product->brand?->name }}</td>
                        <td class="px-4 py-3 capitalize">{{ $product->sheen }}</td>
                        <td class="px-4 py-3 text-center">
                            <flux:badge size="sm" :variant="$product->scope_type === 'exterior' ? 'warning' : 'default'">
                                {{ ucfirst($product->scope_type) }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right font-medium">${{ number_format($product->price_per_gallon, 2) }}</td>
                        <td class="px-4 py-3 text-center text-xs">
                            {{ $product->coverage_sqft_brush_roll }} sqft
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:switch wire:click="toggleProductActive({{ $product->id }})" :checked="$product->is_active" />
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button wire:click="openProductModal({{ $product->id }})" variant="ghost" size="sm" icon="pencil" />
                                <flux:button wire:click="deleteProduct({{ $product->id }})" variant="ghost" size="sm" icon="trash" :wire:confirm="__('Are you sure?')" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('No products found. Add a brand first, then add products.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->paintProducts->links() }}
    </div>
</div>

{{-- Brand Modal --}}
<flux:modal wire:model="showBrandModal" variant="flyout" class="max-w-md">
    <form wire:submit="saveBrand">
        <flux:heading size="lg" class="mb-6">
            {{ $editingBrand ? __('Edit Brand') : __('Add Brand') }}
        </flux:heading>

        <div class="space-y-4">
            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="brandName" :placeholder="__('e.g., Sherwin-Williams')" />
                <flux:error name="brandName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Code') }}</flux:label>
                <flux:input wire:model="brandCode" :placeholder="__('e.g., SW')" class="uppercase" />
                <flux:error name="brandCode" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Website') }}</flux:label>
                <flux:input wire:model="brandWebsite" type="url" placeholder="https://..." />
                <flux:error name="brandWebsite" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Sort Order') }}</flux:label>
                    <flux:input wire:model="brandSortOrder" type="number" min="0" />
                </flux:field>
                <div class="flex items-end pb-2">
                    <flux:checkbox wire:model="brandIsActive" :label="__('Active')" />
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="closeBrandModal" type="button">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">
                {{ $editingBrand ? __('Update') : __('Create') }}
            </flux:button>
        </div>
    </form>
</flux:modal>

{{-- Product Modal --}}
<flux:modal wire:model="showProductModal" variant="flyout" class="max-w-lg">
    <form wire:submit="saveProduct">
        <flux:heading size="lg" class="mb-6">
            {{ $editingProduct ? __('Edit Product') : __('Add Product') }}
        </flux:heading>

        <div class="space-y-4 max-h-[70vh] overflow-y-auto pr-2">
            <flux:field>
                <flux:label>{{ __('Brand') }}</flux:label>
                <flux:select wire:model="productBrandId">
                    <option value="">{{ __('Select brand...') }}</option>
                    @foreach ($this->paintBrands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="productBrandId" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="productName" :placeholder="__('e.g., Duration Home')" />
                    <flux:error name="productName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Code') }}</flux:label>
                    <flux:input wire:model="productCode" :placeholder="__('e.g., SW-DUR-HOME')" class="uppercase" />
                    <flux:error name="productCode" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="productDescription" rows="2" />
                <flux:error name="productDescription" />
            </flux:field>

            <div class="grid grid-cols-3 gap-4">
                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    <flux:select wire:model="productType">
                        <option value="paint">{{ __('Paint') }}</option>
                        <option value="primer">{{ __('Primer') }}</option>
                        <option value="stain">{{ __('Stain') }}</option>
                        <option value="sealer">{{ __('Sealer') }}</option>
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Sheen') }}</flux:label>
                    <flux:select wire:model="productSheen">
                        <option value="flat">{{ __('Flat') }}</option>
                        <option value="matte">{{ __('Matte') }}</option>
                        <option value="eggshell">{{ __('Eggshell') }}</option>
                        <option value="satin">{{ __('Satin') }}</option>
                        <option value="semi-gloss">{{ __('Semi-Gloss') }}</option>
                        <option value="gloss">{{ __('Gloss') }}</option>
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Scope') }}</flux:label>
                    <flux:select wire:model="productScopeType">
                        <option value="interior">{{ __('Interior') }}</option>
                        <option value="exterior">{{ __('Exterior') }}</option>
                        <option value="both">{{ __('Both') }}</option>
                    </flux:select>
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Surface Type') }}</flux:label>
                <flux:select wire:model="productSurfaceType">
                    <option value="walls">{{ __('Walls') }}</option>
                    <option value="ceiling">{{ __('Ceiling') }}</option>
                    <option value="trim">{{ __('Trim') }}</option>
                    <option value="cabinets">{{ __('Cabinets') }}</option>
                    <option value="siding">{{ __('Siding') }}</option>
                    <option value="all">{{ __('All Surfaces') }}</option>
                </flux:select>
            </flux:field>

            <div class="border-t pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __('Pricing') }}</flux:text>
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Price/Gallon') }}</flux:label>
                        <flux:input wire:model="pricePerGallon" type="number" step="0.01" min="0" />
                        <flux:error name="pricePerGallon" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Price/5 Gallon') }}</flux:label>
                        <flux:input wire:model="pricePerFiveGallon" type="number" step="0.01" min="0" :placeholder="__('Optional')" />
                    </flux:field>
                </div>
            </div>

            <div class="border-t pt-4">
                <flux:text class="text-sm font-medium mb-3">{{ __('Coverage (sqft/gallon)') }}</flux:text>
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Brush/Roll') }}</flux:label>
                        <flux:input wire:model="coverageBrushRoll" type="number" min="50" />
                        <flux:error name="coverageBrushRoll" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Spray') }}</flux:label>
                        <flux:input wire:model="coverageSpray" type="number" min="50" />
                        <flux:error name="coverageSpray" />
                    </flux:field>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Recommended Coats') }}</flux:label>
                    <flux:input wire:model="recommendedCoats" type="number" min="1" max="5" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('2nd Coat Multiplier') }}</flux:label>
                    <flux:input wire:model="secondCoatMultiplier" type="number" step="0.05" min="1" max="2" />
                </flux:field>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <flux:checkbox wire:model="isPremium" :label="__('Premium')" />
                <flux:checkbox wire:model="isLowVoc" :label="__('Low VOC')" />
                <flux:checkbox wire:model="isSelfPriming" :label="__('Self-Priming')" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Sort Order') }}</flux:label>
                    <flux:input wire:model="productSortOrder" type="number" min="0" />
                </flux:field>
                <div class="flex items-end pb-2">
                    <flux:checkbox wire:model="productIsActive" :label="__('Active')" />
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="closeProductModal" type="button">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">
                {{ $editingProduct ? __('Update') : __('Create') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
