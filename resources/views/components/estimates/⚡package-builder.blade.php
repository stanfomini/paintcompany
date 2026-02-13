<?php

use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Notifications\EstimatePackageUpdated;
use App\Services\EstimateAmendmentService;
use App\Services\EstimatePackageGeneratorService;
use App\Services\EstimatePackageService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    /** @var array<int, array{id: int, name: string, tier: string, description: ?string, discount_percent: float, is_recommended: bool, subtotal: float, tax_amount: float, total: float, deposit_amount: float, balance_due: float, areas: array, services: array}> */
    public array $packages = [];

    /** @var array<string, bool> Track which areas are expanded per package (key: "packageId-areaId") */
    public array $expandedAreas = [];

    public bool $showPackageModal = false;

    public ?int $editingPackageId = null;

    public string $packageName = '';

    public string $packageTier = 'standard';

    public string $packageDescription = '';

    public float $packageDiscountPercent = 0;

    // Add-on service selector
    public bool $showAddonModal = false;
    public ?int $addonPackageId = null;

    /** @var array<int, array> */
    public array $availableAddons = [];

    protected function rules(): array
    {
        return [
            'packageName' => ['required', 'string', 'max:255'],
            'packageTier' => ['required', 'string', 'in:basic,standard,premium'],
            'packageDescription' => ['nullable', 'string', 'max:2000'],
            'packageDiscountPercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    protected function messages(): array
    {
        return [
            'packageName.required' => __('Please enter a package name.'),
            'packageTier.required' => __('Please select a tier.'),
            'packageDiscountPercent.min' => __('Discount cannot be negative.'),
            'packageDiscountPercent.max' => __('Discount cannot exceed 100%.'),
        ];
    }

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;

        // Auto-generate packages from templates if none exist
        $this->ensurePackagesExist();

        // Recalculate package totals to reflect current pricing settings
        $this->recalculatePackageTotals();

        $this->loadPackages();
    }

    /**
     * Recalculate all package totals so stored values match live getCustomerPrice().
     */
    protected function recalculatePackageTotals(): void
    {
        $service = app(EstimatePackageService::class);
        foreach ($this->estimate->packages as $package) {
            $service->calculatePackageTotal($package);
        }
    }

    /**
     * Ensure Good/Better/Best packages exist by auto-generating from templates.
     */
    protected function ensurePackagesExist(): void
    {
        // Only auto-generate if no packages exist and templates are configured
        if ($this->estimate->packages()->exists()) {
            return;
        }

        if (! PackageTemplate::active()->exists()) {
            return;
        }

        $generator = app(EstimatePackageGeneratorService::class);
        $generator->generateFromTemplates($this->estimate);
    }

    public function loadPackages(): void
    {
        $this->packages = $this->estimate->packages()
            ->with(['items.itemable'])
            ->orderBy('sort_order')
            ->get()
            ->map(function (EstimatePackage $package) {
                // Group surface items by area
                $surfaceItems = $package->items->filter(
                    fn ($item) => $item->itemable_type === EstimateAreaSurface::class
                );

                // Load area relationships for surfaces
                $surfaceItems->load('itemable.area');

                // Build areas structure with their surfaces
                // Surfaces show proportional customer prices (their share of area total)
                $areasMap = [];
                $areaPriceCache = [];

                foreach ($surfaceItems as $item) {
                    $surface = $item->itemable;
                    if (! $surface || ! $surface->area) {
                        continue;
                    }

                    $areaId = $surface->area_id;
                    if (! isset($areasMap[$areaId])) {
                        $area = $surface->area;
                        $areasMap[$areaId] = [
                            'id' => $areaId,
                            'name' => $area->name,
                            'area_model' => $area,
                            'surfaces' => [],
                            'included_total' => 0, // calculated below after all surfaces collected
                        ];
                        // Cache proportional customer prices for this area's surfaces
                        $areaPriceCache[$areaId] = $area->getSurfaceCustomerPrices();
                    }

                    $surfaceData = [
                        'id' => $item->id,
                        'surface_id' => $surface->id,
                        'name' => $surface->name,
                        'quantity' => (float) $surface->quantity,
                        'unit' => $surface->unit,
                        'labor_hours' => (float) $surface->labor_hours,
                        'effective_price' => $areaPriceCache[$areaId][$surface->id] ?? $surface->getCustomerPrice(),
                        'is_included' => $item->is_included,
                    ];

                    $areasMap[$areaId]['surfaces'][] = $surfaceData;
                }

                // Add fixed-price areas (linked as EstimateArea items, not surfaces)
                $fixedPriceItems = $package->items->filter(
                    fn ($item) => $item->itemable_type === EstimateArea::class
                );

                foreach ($fixedPriceItems as $item) {
                    $area = $item->itemable;
                    if (! $area) {
                        continue;
                    }

                    $areaId = $area->id;
                    $areasMap[$areaId] = [
                        'id' => $areaId,
                        'name' => $area->name,
                        'area_model' => $area,
                        'surfaces' => [],
                        'is_fixed_price' => true,
                        'item_id' => $item->id,
                        'is_included' => $item->is_included,
                        'included_total' => $item->is_included ? $area->getCustomerPrice() : 0,
                    ];
                }

                // Calculate included_total per area based on package-specific surface inclusions
                foreach ($areasMap as $areaId => &$areaData) {
                    // Skip fixed-price areas - they already have included_total set
                    if (! empty($areaData['is_fixed_price'])) {
                        continue;
                    }

                    $includedSurfaceIds = collect($areaData['surfaces'])
                        ->where('is_included', true)
                        ->pluck('surface_id')
                        ->toArray();

                    $areaData['included_total'] = $areaData['area_model']->getPartialCustomerPrice($includedSurfaceIds);
                }
                unset($areaData);

                // Get service items (non-surface, non-area items)
                $serviceItems = $package->items->filter(
                    fn ($item) => $item->itemable_type !== EstimateAreaSurface::class
                        && $item->itemable_type !== EstimateArea::class
                )->map(function (EstimatePackageItem $item) {
                    return [
                        'id' => $item->id,
                        'name' => $this->getItemName($item),
                        'effective_price' => (float) $item->effective_price,
                        'is_included' => $item->is_included,
                        'customer_approved' => $item->customer_approved,
                        'is_customer_selectable' => (bool) $item->is_customer_selectable,
                    ];
                })->values()->toArray();

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'tier' => $package->tier,
                    'tier_label' => $package->tier_label,
                    'description' => $package->description,
                    'discount_percent' => (float) $package->discount_percent,
                    'discount_amount' => (float) $package->discount_amount,
                    'is_recommended' => $package->is_recommended,
                    'is_selected' => $package->is_selected,
                    'subtotal' => (float) $package->subtotal,
                    'tax_rate' => (float) $package->tax_rate,
                    'tax_amount' => (float) $package->tax_amount,
                    'total' => (float) $package->total,
                    'deposit_amount' => (float) $package->deposit_amount,
                    'balance_due' => (float) $package->balance_due,
                    'warranty_price' => (float) $package->warranty_price,
                    'areas' => array_values(array_map(function ($area) {
                        unset($area['area_model']);
                        return $area;
                    }, $areasMap)),
                    'services' => $serviceItems,
                ];
            })
            ->toArray();
    }

    /**
     * Toggle area expansion within a package.
     * Each package tracks its own expanded areas independently.
     */
    public function toggleAreaExpansion(int $packageId, int $areaId): void
    {
        $key = "{$packageId}-{$areaId}";
        $this->expandedAreas[$key] = ! ($this->expandedAreas[$key] ?? false);
    }

    /**
     * Check if an area is expanded within a package.
     */
    public function isAreaExpanded(int $packageId, int $areaId): bool
    {
        return $this->expandedAreas["{$packageId}-{$areaId}"] ?? false;
    }

    protected function getItemName(EstimatePackageItem $item): string
    {
        $itemable = $item->itemable;

        if (! $itemable) {
            return 'Unknown Item';
        }

        if ($itemable instanceof \App\Models\EstimateItem) {
            return $itemable->description;
        }

        if ($itemable instanceof \App\Models\EstimateAreaSurface) {
            $areaName = $itemable->area?->name ?? 'Unknown Area';

            return $areaName.' - '.$itemable->name;
        }

        if ($itemable instanceof \App\Models\EstimateArea) {
            return $itemable->name.' (Area)';
        }

        return 'Item';
    }

    /**
     * Open modal to edit an existing package.
     * Only editing is allowed - no new package creation.
     */
    public function openPackageModal(int $packageId): void
    {
        $this->resetValidation();
        $this->editingPackageId = $packageId;

        $package = EstimatePackage::find($packageId);
        if ($package && $package->estimate_id === $this->estimate->id) {
            $this->packageName = $package->name;
            $this->packageTier = $package->tier;
            $this->packageDescription = $package->description ?? '';
            $this->packageDiscountPercent = (float) $package->discount_percent;
            $this->showPackageModal = true;
        }
    }

    public function closePackageModal(): void
    {
        $this->showPackageModal = false;
        $this->editingPackageId = null;
        $this->resetValidation();
    }

    /**
     * Save changes to an existing package.
     * Only editing is allowed - no new package creation.
     */
    public function savePackage(): void
    {
        if (! $this->editingPackageId) {
            return;
        }

        $this->validate();

        $package = EstimatePackage::find($this->editingPackageId);
        if (! $package || $package->estimate_id !== $this->estimate->id) {
            return;
        }

        // Track changes for notification
        $changes = $this->getPackageChanges($package);

        $package->update([
            'name' => $this->packageName,
            'tier' => $this->packageTier,
            'description' => $this->packageDescription ?: null,
            'discount_percent' => $this->packageDiscountPercent,
        ]);

        $service = app(EstimatePackageService::class);
        $service->calculatePackageTotal($package);

        // Notify customer if estimate is already sent
        if (! empty($changes)) {
            $this->notifyPackageChange($package, $changes, 'updated');
        }

        $this->loadPackages();
        $this->closePackageModal();
        $this->dispatch('packages-updated');
    }

    /**
     * Regenerate all packages from templates (used when settings change).
     */
    public function regeneratePackages(): void
    {
        if (! PackageTemplate::active()->exists()) {
            session()->flash('error', __('No package templates configured. Please set up Good/Better/Best in Company Settings.'));

            return;
        }

        $generator = app(EstimatePackageGeneratorService::class);

        // Clear and regenerate
        $generator->clearGeneratedPackages($this->estimate);
        $generator->generateFromTemplates($this->estimate);

        // Notify customer if estimate is already sent
        if ($this->estimate->status === 'sent') {
            $this->notifyPackageChange(null, ['Packages regenerated from updated templates'], 'regenerated');
        }

        $this->loadPackages();
        $this->dispatch('packages-updated');
    }

    /**
     * Check if package templates are configured.
     */
    public function hasPackageTemplates(): bool
    {
        return PackageTemplate::active()->exists();
    }

    /**
     * Get what changed on the package for notifications.
     *
     * @return array<string>
     */
    protected function getPackageChanges(EstimatePackage $package): array
    {
        $changes = [];

        if ($package->name !== $this->packageName) {
            $changes[] = "Package name changed from '{$package->name}' to '{$this->packageName}'";
        }

        if ((float) $package->discount_percent !== $this->packageDiscountPercent) {
            $changes[] = "Discount changed from {$package->discount_percent}% to {$this->packageDiscountPercent}%";
        }

        if ($package->description !== ($this->packageDescription ?: null)) {
            $changes[] = 'Package description updated';
        }

        return $changes;
    }

    /**
     * Notify customer when packages are changed on a sent estimate.
     *
     * @param  array<string>  $changes
     */
    protected function notifyPackageChange(?EstimatePackage $package, array $changes, string $type): void
    {
        if ($this->estimate->status !== 'sent') {
            return;
        }

        // Get customer through Deal relationship
        $customer = $this->estimate->deal?->customer;
        if (! $customer) {
            return;
        }

        // Log the activity
        $description = match ($type) {
            'regenerated' => 'Packages regenerated from templates',
            'surface_toggled' => implode(', ', $changes),
            default => "Package '{$package?->name}' modified: ".implode(', ', $changes),
        };

        EstimateActivity::log(
            $this->estimate,
            'packages_modified',
            $description,
            [
                'package_id' => $package?->id,
                'package_name' => $package?->name,
                'changes' => $changes,
                'type' => $type,
            ]
        );

        // Create amendment for tracking and customer acknowledgment
        if ($this->estimate->sent_snapshot) {
            $amendmentService = app(EstimateAmendmentService::class);
            $amendmentService->createAmendment($this->estimate, auth()->user());
        }

        // Send notification to customer
        $customer->notify(new EstimatePackageUpdated($this->estimate, $changes, $type));
    }

    public function toggleItemInclusion(int $packageId, int $itemId): void
    {
        $package = EstimatePackage::find($packageId);
        if (! $package || $package->estimate_id !== $this->estimate->id) {
            return;
        }

        $item = EstimatePackageItem::find($itemId);
        if (! $item || $item->package_id !== $packageId) {
            return;
        }

        $wasIncluded = $item->is_included;
        $item->is_included = ! $item->is_included;
        $item->save();

        // Recalculate package totals
        $service = app(EstimatePackageService::class);
        $service->calculatePackageTotal($package);

        // Notify customer if estimate is sent
        $itemName = $this->getItemName($item);
        $action = $item->is_included ? 'added to' : 'removed from';
        $changes = ["{$itemName} {$action} {$package->name} package"];

        $this->notifyPackageChange($package, $changes, 'item_toggled');

        $this->loadPackages();
        $this->dispatch('packages-updated');
    }

    public function markAsRecommended(int $packageId): void
    {
        $package = EstimatePackage::find($packageId);
        if (! $package || $package->estimate_id !== $this->estimate->id) {
            return;
        }

        // Remove recommended from all packages for this estimate
        $this->estimate->packages()->update(['is_recommended' => false]);

        // Set this package as recommended
        $package->is_recommended = true;
        $package->save();

        $this->loadPackages();
        $this->dispatch('packages-updated');
    }

    /**
     * Open modal to add a service add-on to a specific package.
     */
    public function openAddonModal(int $packageId): void
    {
        $package = EstimatePackage::find($packageId);
        if (! $package || $package->estimate_id !== $this->estimate->id) {
            return;
        }

        $this->addonPackageId = $packageId;

        // Get all optional template items not already in this package
        $existingServiceNames = $package->items()
            ->where('itemable_type', EstimateItem::class)
            ->with('itemable')
            ->get()
            ->pluck('itemable.description')
            ->filter()
            ->toArray();

        $paintingTotal = (float) $this->estimate->areas_subtotal;

        // Load ALL standalone items from the catalog (not just from one template)
        $this->availableAddons = PackageTemplateItem::query()
            ->where('is_optional', true)
            ->with('serviceCategory')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($item) => ! in_array($item->name, $existingServiceNames, true))
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'price_display' => $item->price_display,
                'calculated_price' => $item->calculatePrice($paintingTotal),
                'cost' => (float) $item->cost,
                'is_customer_selectable' => $item->is_customer_selectable,
                'service_category' => $item->serviceCategory ? __($item->serviceCategory->name) : null,
            ])
            ->values()
            ->toArray();

        $this->showAddonModal = true;
    }

    public function closeAddonModal(): void
    {
        $this->showAddonModal = false;
        $this->addonPackageId = null;
        $this->availableAddons = [];
    }

    /**
     * Add a template item as a service add-on to the package.
     */
    public function addServiceAddon(int $templateItemId): void
    {
        $package = EstimatePackage::find($this->addonPackageId);
        if (! $package || $package->estimate_id !== $this->estimate->id) {
            return;
        }

        $templateItem = PackageTemplateItem::find($templateItemId);
        if (! $templateItem) {
            return;
        }

        $paintingTotal = (float) $this->estimate->areas_subtotal;
        $price = $templateItem->calculatePrice($paintingTotal);

        // Create EstimateItem for this service
        $estimateItem = EstimateItem::create([
            'estimate_id' => $this->estimate->id,
            'description' => $templateItem->name,
            'details' => $templateItem->description,
            'quantity' => 1,
            'unit' => 'service',
            'unit_price' => $price,
            'total' => $price,
            'cost' => (float) $templateItem->cost,
            'sort_order' => 1000 + EstimateItem::where('estimate_id', $this->estimate->id)->count(),
        ]);

        // Link to the package
        $maxSort = $package->items()->max('sort_order') ?? 0;
        EstimatePackageItem::withoutEvents(function () use ($package, $estimateItem, $templateItem, $maxSort) {
            EstimatePackageItem::create([
                'package_id' => $package->id,
                'itemable_type' => EstimateItem::class,
                'itemable_id' => $estimateItem->id,
                'quantity' => 1,
                'price_override' => null,
                'is_included' => true,
                'is_customer_selectable' => $templateItem->is_customer_selectable,
                'customer_approved' => true,
                'customer_approved_at' => now(),
                'sort_order' => $maxSort + 1,
            ]);
        });

        // Recalculate package totals
        $service = app(EstimatePackageService::class);
        $service->calculatePackageTotal($package);

        // Notify customer if estimate is sent
        $this->notifyPackageChange($package, ["{$templateItem->name} added to {$package->name} package"], 'item_added');

        $this->closeAddonModal();
        $this->loadPackages();
        $this->dispatch('packages-updated');
    }

    /**
     * Remove a service item from a package and delete the orphaned EstimateItem.
     */
    public function removeServiceItem(int $packageId, int $packageItemId): void
    {
        $package = EstimatePackage::find($packageId);
        if (! $package || $package->estimate_id !== $this->estimate->id) {
            return;
        }

        $packageItem = EstimatePackageItem::find($packageItemId);
        if (! $packageItem || $packageItem->package_id !== $packageId) {
            return;
        }

        // Only allow removal of service items (not surfaces)
        if ($packageItem->itemable_type !== EstimateItem::class) {
            return;
        }

        $itemName = $this->getItemName($packageItem);
        $estimateItemId = $packageItem->itemable_id;

        // Delete the package item link
        $packageItem->delete();

        // Check if EstimateItem is orphaned (not linked to any other package)
        $otherLinks = EstimatePackageItem::where('itemable_type', EstimateItem::class)
            ->where('itemable_id', $estimateItemId)
            ->exists();

        if (! $otherLinks) {
            EstimateItem::find($estimateItemId)?->delete();
        }

        // Recalculate package totals
        $service = app(EstimatePackageService::class);
        $service->calculatePackageTotal($package);

        // Notify
        $this->notifyPackageChange($package, ["{$itemName} removed from {$package->name} package"], 'item_removed');

        $this->loadPackages();
        $this->dispatch('packages-updated');
    }

    public function getTierBadgeColor(string $tier): string
    {
        return match ($tier) {
            'basic' => 'zinc',
            'standard' => 'blue',
            'premium' => 'amber',
            'custom' => 'purple',
            default => 'zinc',
        };
    }
}; ?>

<div>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg">
        <div class="flex items-center justify-between p-4 border-b border-zinc-200 dark:border-zinc-700">
            <div>
                <flux:heading size="sm">{{ __('Pricing Packages (Good/Better/Best)') }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-1">
                    {{ __('Toggle items to differentiate packages. Changes will notify the customer if the estimate has been sent.') }}
                </flux:text>
            </div>
            @if ($this->hasPackageTemplates())
                <flux:button
                    wire:click="regeneratePackages"
                    wire:loading.attr="disabled"
                    wire:confirm="{{ __('This will regenerate all packages from the current templates. Any customizations will be lost. Continue?') }}"
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="arrow-path"
                >
                    <span wire:loading.remove wire:target="regeneratePackages">{{ __('Regenerate from Templates') }}</span>
                    <span wire:loading wire:target="regeneratePackages">{{ __('Regenerating...') }}</span>
                </flux:button>
            @endif
        </div>

        @if (count($packages) > 0)
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 p-4">
                @foreach ($packages as $package)
                    <div
                        wire:key="package-{{ $package['id'] }}"
                        class="border rounded-lg {{ $package['is_recommended'] ? 'border-green-500 dark:border-green-400 ring-2 ring-green-500/20' : 'border-zinc-200 dark:border-zinc-700' }}"
                    >
                        {{-- Package Header --}}
                        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 {{ $package['is_recommended'] ? 'bg-green-50 dark:bg-green-900/20' : 'bg-zinc-50 dark:bg-zinc-800' }} rounded-t-lg">
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="sm">{{ $package['name'] }}</flux:heading>
                                    <flux:badge color="{{ $this->getTierBadgeColor($package['tier']) }}" size="sm">
                                        {{ $package['tier_label'] }}
                                    </flux:badge>
                                </div>
                                <flux:button
                                    wire:click="openPackageModal({{ $package['id'] }})"
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    icon="pencil"
                                    title="Edit package details"
                                />
                            </div>

                            @if ($package['is_recommended'])
                                <flux:badge color="green" size="sm" class="mb-2">{{ __('Recommended') }}</flux:badge>
                            @else
                                <flux:button
                                    wire:click="markAsRecommended({{ $package['id'] }})"
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    class="text-zinc-500 hover:text-green-600"
                                >
                                    {{ __('Set as Recommended') }}
                                </flux:button>
                            @endif

                            @if ($package['description'])
                                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mt-2">
                                    {{ $package['description'] }}
                                </flux:text>
                            @endif
                        </div>

                        {{-- Painting Areas with Expandable Surfaces --}}
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800 max-h-80 overflow-y-auto">
                            @forelse ($package['areas'] as $area)
                                <div wire:key="package-{{ $package['id'] }}-area-{{ $area['id'] }}">
                                    @if (!empty($area['is_fixed_price']))
                                        {{-- Fixed-price area: no expand, toggle inclusion --}}
                                        <div class="w-full px-4 py-2 flex items-center gap-3 {{ !($area['is_included'] ?? true) ? 'opacity-50' : '' }}">
                                            <flux:checkbox
                                                wire:click="toggleItemInclusion({{ $package['id'] }}, {{ $area['item_id'] }})"
                                                :checked="$area['is_included'] ?? true"
                                            />
                                            <div class="flex-1 min-w-0 text-left">
                                                <flux:text size="sm" class="font-medium truncate">{{ $area['name'] }}</flux:text>
                                                <flux:text size="xs" class="text-zinc-400">{{ __('Fixed Price') }}</flux:text>
                                            </div>
                                            <flux:text size="sm" class="font-medium {{ ($area['is_included'] ?? true) ? 'text-green-600 dark:text-green-400' : 'line-through' }}">
                                                ${{ number_format($area['included_total'], 2) }}
                                            </flux:text>
                                        </div>
                                    @else
                                        {{-- Standard area with expandable surfaces --}}
                                        <button
                                            wire:click="toggleAreaExpansion({{ $package['id'] }}, {{ $area['id'] }})"
                                            type="button"
                                            class="w-full px-4 py-2 flex items-center gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                                        >
                                            <flux:icon
                                                name="{{ $this->isAreaExpanded($package['id'], $area['id']) ? 'chevron-down' : 'chevron-right' }}"
                                                class="w-4 h-4 text-zinc-400 shrink-0"
                                            />
                                            <div class="flex-1 min-w-0 text-left">
                                                <flux:text size="sm" class="font-medium truncate">{{ $area['name'] }}</flux:text>
                                                <flux:text size="xs" class="text-zinc-400">
                                                    {{ count($area['surfaces']) }} {{ __('surfaces') }}
                                                </flux:text>
                                            </div>
                                            <flux:text size="sm" class="font-medium text-green-600 dark:text-green-400">
                                                ${{ number_format($area['included_total'], 2) }}
                                            </flux:text>
                                        </button>

                                        {{-- Expanded Surfaces --}}
                                        @if ($this->isAreaExpanded($package['id'], $area['id']))
                                            <div class="bg-zinc-50 dark:bg-zinc-900 border-t border-zinc-100 dark:border-zinc-800">
                                                @foreach ($area['surfaces'] as $surface)
                                                    <div
                                                        wire:key="package-{{ $package['id'] }}-surface-{{ $surface['id'] }}"
                                                        class="px-6 py-2 flex items-center gap-3 border-b border-zinc-100 dark:border-zinc-800 last:border-b-0 {{ !$surface['is_included'] ? 'opacity-50' : '' }}"
                                                    >
                                                        <flux:checkbox
                                                            wire:click="toggleItemInclusion({{ $package['id'] }}, {{ $surface['id'] }})"
                                                            :checked="$surface['is_included']"
                                                        />
                                                        <div class="flex-1 min-w-0">
                                                            <flux:text size="sm">{{ $surface['name'] }}</flux:text>
                                                            <flux:text size="xs" class="text-zinc-400">
                                                                {{ number_format($surface['quantity'], 0) }} {{ $surface['unit'] }}
                                                                &bull; {{ number_format($surface['labor_hours'], 1) }} hrs
                                                            </flux:text>
                                                        </div>
                                                        <flux:text size="sm" class="font-medium {{ $surface['is_included'] ? '' : 'line-through' }}">
                                                            ${{ number_format($surface['effective_price'], 2) }}
                                                        </flux:text>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @empty
                                <div class="p-4 text-center">
                                    <flux:text size="sm" class="text-zinc-400">{{ __('No painting areas') }}</flux:text>
                                </div>
                            @endforelse

                            {{-- Service Items --}}
                            <div class="px-4 py-2 bg-zinc-100 dark:bg-zinc-800 flex items-center justify-between">
                                <flux:text size="xs" class="font-medium text-zinc-500 uppercase tracking-wide">{{ __('Add-on Services') }}</flux:text>
                                <flux:button
                                    wire:click="openAddonModal({{ $package['id'] }})"
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    icon="plus"
                                >
                                    {{ __('Add Service') }}
                                </flux:button>
                            </div>
                            @if (count($package['services']) > 0)
                                @foreach ($package['services'] as $service)
                                    <div
                                        wire:key="package-{{ $package['id'] }}-service-{{ $service['id'] }}"
                                        class="px-4 py-2 flex items-center gap-3 {{ !$service['is_included'] ? 'opacity-50 bg-zinc-50 dark:bg-zinc-900' : '' }}"
                                    >
                                        <flux:checkbox
                                            wire:click="toggleItemInclusion({{ $package['id'] }}, {{ $service['id'] }})"
                                            :checked="$service['is_included']"
                                        />
                                        <div class="flex-1 min-w-0">
                                            <flux:text size="sm" class="truncate">{{ $service['name'] }}</flux:text>
                                        </div>
                                        <flux:text size="sm" class="font-medium {{ $service['is_included'] ? '' : 'line-through' }}">
                                            ${{ number_format($service['effective_price'], 2) }}
                                        </flux:text>
                                        <flux:button
                                            wire:click="removeServiceItem({{ $package['id'] }}, {{ $service['id'] }})"
                                            wire:confirm="{{ __('Remove this service from the package?') }}"
                                            type="button"
                                            variant="ghost"
                                            size="xs"
                                            icon="x-mark"
                                            class="text-red-500 hover:text-red-700 shrink-0"
                                        />
                                    </div>
                                @endforeach
                            @else
                                <div class="px-4 py-3 text-center">
                                    <flux:text size="sm" class="text-zinc-400">{{ __('No add-on services') }}</flux:text>
                                </div>
                            @endif
                        </div>

                        {{-- Package Totals --}}
                        <div class="p-4 bg-zinc-50 dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700 rounded-b-lg space-y-2">
                            <div class="flex justify-between text-sm">
                                <flux:text class="text-zinc-500">{{ __('Subtotal') }}</flux:text>
                                <flux:text>${{ number_format($package['subtotal'], 2) }}</flux:text>
                            </div>

                            @if (($package['warranty_price'] ?? 0) > 0)
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">{{ __('Warranty') }}</flux:text>
                                    <flux:text>${{ number_format($package['warranty_price'], 2) }}</flux:text>
                                </div>
                            @endif

                            @if ($package['discount_percent'] > 0 || $package['discount_amount'] > 0)
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">
                                        {{ __('Discount') }}
                                        @if ($package['discount_percent'] > 0)
                                            ({{ number_format($package['discount_percent'], 1) }}%)
                                        @endif
                                    </flux:text>
                                    <flux:text class="text-red-600 dark:text-red-400">
                                        -${{ number_format($package['discount_amount'], 2) }}
                                    </flux:text>
                                </div>
                            @endif

                            @if ($package['tax_rate'] > 0)
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">{{ __('Tax') }} ({{ number_format($package['tax_rate'], 2) }}%)</flux:text>
                                    <flux:text>${{ number_format($package['tax_amount'], 2) }}</flux:text>
                                </div>
                            @endif

                            <div class="flex justify-between font-semibold pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:text>{{ __('Total') }}</flux:text>
                                <flux:text class="text-lg">${{ number_format($package['total'], 2) }}</flux:text>
                            </div>

                            <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700 space-y-1">
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">{{ __('Deposit Required') }}</flux:text>
                                    <flux:text class="text-green-600 dark:text-green-400 font-medium">
                                        ${{ number_format($package['deposit_amount'], 2) }}
                                    </flux:text>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">{{ __('Balance Due') }}</flux:text>
                                    <flux:text>${{ number_format($package['balance_due'], 2) }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-8 text-center">
                <flux:icon name="cube" class="w-12 h-12 mx-auto text-zinc-400 dark:text-zinc-500 mb-3" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No package templates configured') }}</flux:text>
                <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500 mt-1">
                    {{ __('Please set up Good/Better/Best packages in Company Settings to enable package options.') }}
                </flux:text>
                <div class="mt-4">
                    <flux:button href="{{ route('company.edit') }}" variant="primary" size="sm">
                        {{ __('Configure Package Templates') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </div>


    {{-- Edit Package Modal (editing only, no creation) --}}
    <flux:modal wire:model="showPackageModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Edit Package') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Package Name') }}</flux:label>
                    <flux:input
                        wire:model="packageName"
                        :placeholder="__('e.g., Premium Package')"
                    />
                    <flux:error name="packageName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Tier') }}</flux:label>
                    <flux:select wire:model="packageTier">
                        <option value="basic">{{ __('Good (Basic)') }}</option>
                        <option value="standard">{{ __('Better (Standard)') }}</option>
                        <option value="premium">{{ __('Best (Premium)') }}</option>
                    </flux:select>
                    <flux:error name="packageTier" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:textarea
                        wire:model="packageDescription"
                        rows="3"
                        :placeholder="__('Describe what this package includes...')"
                    />
                    <flux:error name="packageDescription" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Discount Percent (%)') }}</flux:label>
                    <flux:input
                        wire:model="packageDiscountPercent"
                        type="number"
                        step="0.1"
                        min="0"
                        max="100"
                        placeholder="0"
                    />
                    <flux:error name="packageDiscountPercent" />
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-1">
                        {{ __('Enter a percentage to discount the package total.') }}
                    </flux:text>
                </flux:field>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closePackageModal" type="button" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    wire:click="savePackage"
                    type="button"
                    variant="primary"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="savePackage">{{ __('Update Package') }}</span>
                    <span wire:loading wire:target="savePackage">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Add-on Service Selector Modal --}}
    <flux:modal wire:model="showAddonModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Add Service') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ __('Select a service to add to this package.') }}</flux:text>

            @if (count($availableAddons) > 0)
                <div class="space-y-2 max-h-80 overflow-y-auto">
                    @foreach ($availableAddons as $addon)
                        <button
                            wire:click="addServiceAddon({{ $addon['id'] }})"
                            wire:key="addon-{{ $addon['id'] }}"
                            type="button"
                            class="w-full text-left px-4 py-3 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                        >
                            <div class="flex items-center justify-between">
                                <flux:text size="sm" class="font-medium">{{ $addon['name'] }}</flux:text>
                                <flux:text size="sm" class="font-medium text-green-600 dark:text-green-400">
                                    ${{ number_format($addon['calculated_price'], 2) }}
                                </flux:text>
                            </div>
                            @if ($addon['description'])
                                <flux:text size="xs" class="text-zinc-500 mt-1">{{ $addon['description'] }}</flux:text>
                            @endif
                        </button>
                    @endforeach
                </div>
            @else
                <div class="py-6 text-center">
                    <flux:icon name="check-circle" class="w-10 h-10 mx-auto text-zinc-400 mb-2" />
                    <flux:text size="sm" class="text-zinc-500">{{ __('All available services have been added to this package.') }}</flux:text>
                </div>
            @endif

            <div class="flex justify-end pt-2 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closeAddonModal" type="button" variant="ghost">{{ __('Close') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
