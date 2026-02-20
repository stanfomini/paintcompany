<?php

use App\Models\CompanySetting;
use App\Models\PaintProduct;
use App\Services\EstimateTierPricingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    #[Reactive]
    public string $projectScope = 'interior';

    public ?\App\Models\Estimate $estimate = null;

    public string $paintMode = 'single';

    public ?int $defaultPaintProductId = null;

    /** @var array<string, int|null> */
    public array $paintByCategory = [];

    public bool $useTierPricing = false;

    /** @var array<string, array{mode: string, default_paint_product_id: int|null, paint_by_category: array}> */
    public array $tierPaintConfig = [
        'good' => ['mode' => 'single', 'default_paint_product_id' => null, 'paint_by_category' => []],
        'better' => ['mode' => 'single', 'default_paint_product_id' => null, 'paint_by_category' => []],
        'best' => ['mode' => 'single', 'default_paint_product_id' => null, 'paint_by_category' => []],
    ];

    public string $activeTier = 'better';

    public function mount(string $projectScope = 'interior', ?\App\Models\Estimate $estimate = null, ?array $initialTierTotals = null): void
    {
        $this->projectScope = $projectScope;
        $this->estimate = $estimate;
        $this->liveTierTotals = $initialTierTotals;

        $settings = CompanySetting::getInstance();

        if ($estimate && $estimate->exists) {
            $this->defaultPaintProductId = $estimate->default_paint_product_id;

            $scope = $estimate->project_type ?? 'interior';
            $this->paintByCategory = ($estimate->paint_products_by_category[$scope] ?? []);

            if (! empty($this->paintByCategory)) {
                $this->paintMode = 'category';
            } elseif ($this->defaultPaintProductId) {
                $this->paintMode = 'single';
            }

            // Hydrate tier pricing config
            if ($estimate->hasPaintTiers()) {
                $this->useTierPricing = true;
                foreach (['good', 'better', 'best'] as $tier) {
                    $config = $estimate->getPaintTierConfig($tier);
                    if ($config) {
                        $this->tierPaintConfig[$tier] = $config;
                    }
                }
            }
        } else {
            // Load company default paint products for the current scope (new estimates only)
            $companyDefaults = $settings->getPaintDefaultsForScope($this->projectScope);
            if (! empty($companyDefaults)) {
                $this->paintByCategory = $companyDefaults;
                $this->paintMode = 'category';
            }
        }

        // Dispatch initial config to parent
        $this->dispatchPaintConfig();
    }

    protected function dispatchPaintConfig(): void
    {
        $this->dispatch('paint-config-changed', config: [
            'paintMode' => $this->paintMode,
            'defaultPaintProductId' => $this->defaultPaintProductId,
            'paintByCategory' => $this->paintByCategory,
            'useTierPricing' => $this->useTierPricing,
            'tierPaintConfig' => $this->tierPaintConfig,
        ]);
    }

    public function updatedPaintMode(): void
    {
        $this->dispatchPaintConfig();
    }

    public function updatedDefaultPaintProductId(): void
    {
        $this->dispatchPaintConfig();
    }

    public function updatePaintForCategory(string $category, ?int $paintProductId): void
    {
        if ($paintProductId === null || $paintProductId === 0 || $paintProductId === '') {
            unset($this->paintByCategory[$category]);
        } else {
            $this->paintByCategory[$category] = (int) $paintProductId;
        }
        $this->dispatchPaintConfig();
    }

    public function resetCategoryToDefault(string $category): void
    {
        unset($this->paintByCategory[$category]);
        $this->dispatchPaintConfig();
    }

    public function toggleTierPricing(): void
    {
        $this->saveTierPaintConfig();
        $this->dispatchPaintConfig();
    }

    public function saveTierPaintConfig(): void
    {
        if (! $this->estimate) {
            return;
        }

        if (! $this->useTierPricing) {
            $this->estimate->paint_tiers = null;
            $this->estimate->save();
            app(EstimateTierPricingService::class)->clearTierCosts($this->estimate);

            return;
        }

        foreach (['good', 'better', 'best'] as $tier) {
            $this->estimate->setPaintTier($tier, $this->tierPaintConfig[$tier]);
        }
        $this->estimate->save();

        app(EstimateTierPricingService::class)->recalculateTierCosts($this->estimate);

        foreach ($this->estimate->packages as $package) {
            $package->calculateTotals();
            $package->save();
        }
    }

    public function updatedTierPaintConfig(): void
    {
        $this->saveTierPaintConfig();
        $this->dispatchPaintConfig();
    }

    /** @var array{good: float, better: float, best: float}|null Live tier totals from template-builder */
    public ?array $liveTierTotals = null;

    #[On('live-tier-totals')]
    public function handleLiveTierTotals(array $totals): void
    {
        $this->liveTierTotals = $totals;
    }

    #[On('scope-changed')]
    public function handleScopeChanged(string $scope): void
    {
        $this->projectScope = $scope;

        // Clear computed caches so they reload for the new scope
        unset($this->availablePaintProducts, $this->surfaceCategoriesForScope, $this->paintSummaryByCategory, $this->paintOverrideCount);

        $settings = CompanySetting::getInstance();

        if ($this->estimate && $this->estimate->exists) {
            $this->paintByCategory = ($this->estimate->paint_products_by_category[$scope] ?? []);
        } else {
            $companyDefaults = $settings->getPaintDefaultsForScope($scope);
            if (! empty($companyDefaults)) {
                $this->paintByCategory = $companyDefaults;
                $this->paintMode = 'category';
            } else {
                $this->paintByCategory = [];
            }
        }

        $this->dispatchPaintConfig();
    }

    #[Computed]
    public function availablePaintProducts(): \Illuminate\Support\Collection
    {
        return PaintProduct::query()
            ->active()
            ->forScope($this->projectScope)
            ->with('brand')
            ->orderBy('paint_brand_id')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedPaintProduct(): ?PaintProduct
    {
        if (! $this->defaultPaintProductId) {
            return null;
        }

        return PaintProduct::with('brand')->find($this->defaultPaintProductId);
    }

    #[Computed]
    public function tierPaintProducts(): array
    {
        $products = [];
        foreach (['good', 'better', 'best'] as $tier) {
            $products[$tier] = PaintProduct::active()
                ->paintsOnly()
                ->forTier($tier)
                ->with('brand')
                ->orderBy('sort_order')
                ->get();
        }

        return $products;
    }

    #[Computed]
    public function surfaceCategoriesForScope(): array
    {
        return CompanySetting::getSurfaceCategoriesForScope($this->projectScope);
    }

    #[Computed]
    public function paintSummaryByCategory(): array
    {
        $categories = $this->surfaceCategoriesForScope;
        $settings = CompanySetting::getInstance();
        $companyDefaults = $settings->getPaintDefaultsForScope($this->projectScope);

        $productIds = [];
        $categoryMeta = [];

        foreach ($categories as $key => $label) {
            $paintProductId = $this->paintByCategory[$key] ?? null;
            $isOverride = $paintProductId !== null && $paintProductId !== '';

            $isCompanyDefault = false;
            if (! $isOverride) {
                $paintProductId = $companyDefaults[$key] ?? null;
                $isCompanyDefault = $paintProductId !== null;
            }

            if ($paintProductId) {
                $productIds[] = $paintProductId;
            }

            $categoryMeta[$key] = [
                'label' => $label,
                'paint_product_id' => $paintProductId ?: null,
                'is_override' => $isOverride,
                'is_company_default' => $isCompanyDefault,
            ];
        }

        $products = ! empty($productIds)
            ? PaintProduct::with('brand')->whereIn('id', array_unique($productIds))->get()->keyBy('id')
            : collect();

        $summary = [];
        foreach ($categoryMeta as $key => $meta) {
            $product = $meta['paint_product_id'] ? $products->get($meta['paint_product_id']) : null;

            $summary[$key] = [
                'label' => $meta['label'],
                'name' => $product?->full_name ?? 'Not set',
                'price' => $product?->price_per_gallon,
                'product' => $product,
                'is_override' => $meta['is_override'],
                'is_company_default' => $meta['is_company_default'],
                'paint_product_id' => $meta['paint_product_id'],
            ];
        }

        return $summary;
    }

    #[Computed]
    public function paintOverrideCount(): int
    {
        return count(array_filter($this->paintByCategory, fn ($v) => $v !== null && $v !== ''));
    }
};
?>

<div>
    {{-- Unified Paint Selection --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
        <div class="p-4 border-b border-zinc-100 dark:border-zinc-800">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="sm">{{ __('Paint Selection') }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500">{{ __('Choose paint products for this estimate') }}</flux:text>
                </div>
                @if ($paintMode === 'category' && $this->paintOverrideCount > 0)
                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300">
                        {{ $this->paintOverrideCount }} {{ __('custom') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="p-4">
            {{-- Paint Mode Toggle --}}
            <div class="flex flex-col sm:flex-row gap-3 mb-4">
                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition-all {{ $paintMode === 'single' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}">
                    <input
                        type="radio"
                        wire:model.live="paintMode"
                        value="single"
                        class="text-blue-600 focus:ring-blue-500"
                    />
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Same paint for all surfaces') }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Use one paint product across the entire estimate') }}</div>
                    </div>
                </label>
                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition-all {{ $paintMode === 'category' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}">
                    <input
                        type="radio"
                        wire:model.live="paintMode"
                        value="category"
                        class="text-blue-600 focus:ring-blue-500"
                    />
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Different paint per surface type') }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Customize paint for walls, ceiling, trim, doors') }}</div>
                    </div>
                </label>
            </div>

            {{-- Single Paint Mode --}}
            @if ($paintMode === 'single')
                <div class="space-y-3">
                    <flux:select wire:model.live="defaultPaintProductId" :placeholder="__('Select paint product...')">
                        <flux:select.option value="">{{ __('No default (use production rate material cost)') }}</flux:select.option>
                        @foreach ($this->availablePaintProducts as $product)
                            <flux:select.option value="{{ $product->id }}">
                                {{ $product->brand?->name ?? 'Other' }} - {{ $product->name }} ({{ ucfirst($product->sheen) }}) - ${{ number_format($product->price_per_gallon, 2) }}/gal
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->selectedPaintProduct)
                        <div class="p-3 bg-blue-50 dark:bg-blue-950/30 rounded-lg text-sm">
                            <div class="flex items-center gap-3">
                                <div class="flex-1">
                                    <span class="font-medium text-blue-800 dark:text-blue-200">{{ $this->selectedPaintProduct->full_name }}</span>
                                    <span class="text-blue-600 dark:text-blue-400 ml-2">{{ ucfirst($this->selectedPaintProduct->sheen) }}</span>
                                </div>
                                <div class="text-right text-blue-700 dark:text-blue-300">
                                    <div>Coverage: {{ $this->selectedPaintProduct->coverage_sqft_brush_roll }} sqft/gal</div>
                                    <div>${{ number_format($this->selectedPaintProduct->price_per_gallon, 2) }}/gallon</div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @else
                {{-- Category Paint Mode --}}
                <div class="space-y-3">
                    <div class="grid sm:grid-cols-2 gap-3">
                        @foreach ($this->paintSummaryByCategory as $categoryKey => $categoryData)
                            <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $categoryData['label'] }}</span>
                                    @if ($categoryData['is_override'])
                                        <span class="text-xs text-blue-600 dark:text-blue-400">({{ __('Custom') }})</span>
                                    @elseif ($categoryData['is_company_default'])
                                        <span class="text-xs text-green-600 dark:text-green-400">({{ __('Default') }})</span>
                                    @endif
                                </div>
                                <select
                                    wire:change="updatePaintForCategory('{{ $categoryKey }}', $event.target.value)"
                                    class="w-full text-sm bg-white dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 rounded-md py-1.5 px-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">
                                        @php
                                            $companyDefault = \App\Models\CompanySetting::getInstance()->getDefaultPaintForCategory($categoryKey, $projectScope);
                                        @endphp
                                        @if ($companyDefault)
                                            {{ __('Company Default') }}
                                        @else
                                            {{ __('Not Set') }}
                                        @endif
                                    </option>
                                    @foreach ($this->availablePaintProducts as $product)
                                        <option value="{{ $product->id }}" {{ ($categoryData['paint_product_id'] ?? null) == $product->id && $categoryData['is_override'] ? 'selected' : '' }}>
                                            {{ $product->brand?->name ?? 'Other' }} - {{ $product->name }} (${{ number_format($product->price_per_gallon, 2) }})
                                        </option>
                                    @endforeach
                                </select>
                                @if ($categoryData['price'])
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                        ${{ number_format($categoryData['price'], 2) }}/gal · {{ $categoryData['product']?->coverage_sqft_brush_roll ?? 400 }} sqft coverage
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if ($this->paintOverrideCount > 0)
                        <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <button
                                wire:click="$set('paintByCategory', [])"
                                type="button"
                                class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-red-600 dark:hover:text-red-400"
                            >
                                {{ __('Reset to company defaults') }}
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Tier Paint Pricing Section --}}
    <div class="mt-4 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
        <div class="p-4 border-b border-zinc-100 dark:border-zinc-800">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="sm">{{ __('Good / Better / Best Pricing') }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500">{{ __('Offer customers 3 price tiers with different paint quality') }}</flux:text>
                </div>
                <flux:switch wire:model.live="useTierPricing" wire:change="toggleTierPricing" />
            </div>
        </div>

        @if ($useTierPricing)
            <div class="p-4 space-y-4">
                {{-- Tier Tabs --}}
                <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                    @foreach (['good' => 'Good', 'better' => 'Better', 'best' => 'Best'] as $tierKey => $tierLabel)
                        <button
                            wire:click="$set('activeTier', '{{ $tierKey }}')"
                            type="button"
                            class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition {{ $activeTier === $tierKey ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                        >
                            {{ $tierLabel }}
                        </button>
                    @endforeach
                </div>

                {{-- Active Tier Config --}}
                <div wire:key="tier-config-{{ $activeTier }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Default Paint') }}</label>
                            <select
                                wire:key="tier-select-{{ $activeTier }}"
                                wire:model.live="tierPaintConfig.{{ $activeTier }}.default_paint_product_id"
                                class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            >
                                <option value="">{{ __('Select paint...') }}</option>
                                @foreach ($this->tierPaintProducts[$activeTier] ?? [] as $product)
                                    <option value="{{ $product->id }}">{{ $product->full_name }} — ${{ number_format($product->price_per_gallon, 2) }}/gal</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Tier Totals Preview --}}
                        @php
                            // Use live totals from template-builder when available, fall back to DB
                            $tierTotals = $this->liveTierTotals;
                            if (! $tierTotals && $this->estimate?->hasPaintTiers()) {
                                $tierTotals = app(EstimateTierPricingService::class)->calculateTierTotals($this->estimate);
                            }
                        @endphp
                        @if ($tierTotals)
                            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                                @foreach (['good' => 'Good', 'better' => 'Better', 'best' => 'Best'] as $tierKey => $tierLabel)
                                    <div class="rounded-md p-2 {{ $activeTier === $tierKey ? 'bg-blue-50 ring-2 ring-blue-500 dark:bg-blue-900/20' : 'bg-zinc-50 dark:bg-zinc-800/50' }}">
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $tierLabel }}</div>
                                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">${{ number_format($tierTotals[$tierKey] ?? 0, 2) }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
