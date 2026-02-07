<?php

use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateOptionalItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\PackageTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    /** @var array<int, array<string, mixed>> */
    public array $packages = [];

    public ?int $selectedPackageId = null;

    /** @var array<int, array<string, mixed>> */
    public array $optionalItems = [];

    /**
     * Whether using the new value-add package template system.
     */
    public bool $usePackageTemplates = false;

    /** @var array<int, array<string, mixed>> */
    public array $packageTemplates = [];

    public ?string $selectedTier = null;

    /** @var array<int, bool> */
    public array $selectedPackageItems = [];

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
        $this->selectedPackageId = $estimate->selected_package_id;

        // Check if we should use the new package template system
        $hasLegacyPackages = $estimate->packages()->exists();
        $hasPackageTemplates = PackageTemplate::active()->exists();

        // Use templates if they exist AND no legacy packages are defined
        $this->usePackageTemplates = $hasPackageTemplates && ! $hasLegacyPackages;

        if ($this->usePackageTemplates) {
            $this->loadPackageTemplates();
        } else {
            $this->loadPackages();
            $this->loadOptionalItems();
        }
    }

    /**
     * Load package templates for the new value-add system.
     */
    public function loadPackageTemplates(): void
    {
        $templates = PackageTemplate::active()
            ->with('items')
            ->ordered()
            ->get();

        $paintingTotal = $this->getPaintingTotal();

        $this->packageTemplates = $templates->map(function (PackageTemplate $template) use ($paintingTotal) {
            $warrantyAmount = $template->calculateWarrantyCost($paintingTotal);

            $items = $template->items->map(function ($item) use ($paintingTotal) {
                $itemPrice = $item->calculatePrice($paintingTotal);
                $itemKey = 'item_'.$item->id;

                // Initialize selection state if not set
                if (! isset($this->selectedPackageItems[$itemKey])) {
                    $this->selectedPackageItems[$itemKey] = $item->is_included_by_default;
                }

                return [
                    'id' => $item->id,
                    'key' => $itemKey,
                    'name' => $item->name,
                    'description' => $item->description,
                    'pricing_type' => $item->pricing_type,
                    'price' => $itemPrice,
                    'price_display' => $item->price_display,
                    'is_optional' => $item->is_optional,
                    'is_included_by_default' => $item->is_included_by_default,
                ];
            })->toArray();

            return [
                'id' => $template->id,
                'name' => $template->name,
                'tier' => $template->tier,
                'description' => $template->description,
                'warranty_months' => $template->warranty_months,
                'warranty_description' => $template->warranty_description,
                'warranty_percent' => (float) $template->warranty_percent,
                'warranty_amount' => $warrantyAmount,
                'is_default' => $template->is_default,
                'is_popular' => $template->is_popular,
                'painting_total' => $paintingTotal,
                'items' => $items,
            ];
        })->toArray();

        // Set default selected tier if not already set
        if (! $this->selectedTier) {
            $defaultTemplate = collect($this->packageTemplates)->firstWhere('is_default', true);
            $this->selectedTier = $defaultTemplate ? $defaultTemplate['tier'] : (collect($this->packageTemplates)->first()['tier'] ?? null);
        }
    }

    /**
     * Get the painting total (areas_subtotal) for the estimate.
     */
    protected function getPaintingTotal(): float
    {
        return (float) $this->estimate->areas_subtotal;
    }

    public function loadPackages(): void
    {
        $packages = $this->estimate->packages()
            ->with(['items.itemable'])
            ->orderBy('sort_order')
            ->get();

        $this->packages = $packages->map(function (EstimatePackage $package) {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'tier' => $package->tier,
                'tier_label' => $package->tier_label,
                'description' => $package->description,
                'includes' => $package->includes,
                'excludes' => $package->excludes,
                'subtotal' => (float) $package->subtotal,
                'tax_rate' => (float) $package->tax_rate,
                'tax_amount' => (float) $package->tax_amount,
                'discount_amount' => (float) $package->discount_amount,
                'total' => (float) $package->total,
                'warranty_price' => (float) ($package->warranty_price ?? 0),
                'warranty_years' => $package->warranty_years,
                'warranty_type' => $package->warranty_type,
                'is_recommended' => $package->is_recommended,
                'is_selected' => $package->is_selected,
                'items' => $package->items->map(function (EstimatePackageItem $item) {
                    return [
                        'id' => $item->id,
                        'name' => $this->getItemName($item),
                        'description' => $this->getItemDescription($item),
                        'price' => $item->effective_price,
                        'is_included' => $item->is_included,
                        'customer_approved' => $item->customer_approved,
                    ];
                })->toArray(),
            ];
        })->toArray();
    }

    public function loadOptionalItems(): void
    {
        $optionalItems = $this->estimate->optionalItems()
            ->orderBy('sort_order')
            ->get();

        $packageTotal = $this->getPackageBaseTotal();

        $this->optionalItems = $optionalItems->map(function (EstimateOptionalItem $item) use ($packageTotal) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'pricing_type' => $item->pricing_type,
                'price' => $item->calculatePrice($packageTotal),
                'is_selected_by_customer' => $item->is_selected_by_customer,
            ];
        })->toArray();
    }

    public function selectPackage(int $packageId): void
    {
        // Cannot modify if already accepted
        if ($this->estimate->status === 'accepted') {
            return;
        }

        $package = $this->estimate->packages()->find($packageId);

        if (! $package) {
            return;
        }

        // Deselect all other packages
        $this->estimate->packages()->update(['is_selected' => false]);

        // Select this package
        $package->update(['is_selected' => true]);

        // Update estimate's selected package
        $this->estimate->update(['selected_package_id' => $packageId]);
        $this->selectedPackageId = $packageId;

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'package_selected',
            "Package '{$package->name}' selected",
            [
                'package_id' => $packageId,
                'package_name' => $package->name,
                'package_tier' => $package->tier,
            ]
        );

        // Reload packages and optional items (prices may change based on package)
        $this->loadPackages();
        $this->loadOptionalItems();

        $this->dispatch('package-selected', packageId: $packageId);
    }

    /**
     * Toggle line item approval - DISABLED for customers.
     * Customers can only select packages, not modify individual items.
     * Estimators can modify items via the PackageBuilder component.
     */
    public function toggleLineItemApproval(int $packageItemId): void
    {
        // Customers cannot modify individual line items
        // They can only select a package tier

    }

    public function toggleOptionalItem(int $optionalItemId): void
    {
        // Cannot modify if already accepted
        if ($this->estimate->status === 'accepted') {
            return;
        }

        $optionalItem = $this->estimate->optionalItems()->find($optionalItemId);

        if (! $optionalItem) {
            return;
        }

        if ($optionalItem->is_selected_by_customer) {
            $optionalItem->deselect();
        } else {
            $optionalItem->select();
        }

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'optional_item_toggled',
            $optionalItem->is_selected_by_customer
                ? "Optional item '{$optionalItem->name}' added"
                : "Optional item '{$optionalItem->name}' removed",
            [
                'optional_item_id' => $optionalItemId,
                'optional_item_name' => $optionalItem->name,
                'is_selected' => $optionalItem->is_selected_by_customer,
            ]
        );

        // Reload optional items
        $this->loadOptionalItems();
    }

    // ===== NEW PACKAGE TEMPLATE METHODS =====

    /**
     * Select a package tier (for new template system).
     */
    public function selectTier(string $tier): void
    {
        if (! $this->canModify()) {
            return;
        }

        $this->selectedTier = $tier;

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'package_tier_selected',
            "Package tier '{$tier}' selected",
            ['tier' => $tier]
        );

        $this->dispatch('tier-selected', tier: $tier);
    }

    /**
     * Toggle an optional item in the selected package (for new template system).
     */
    public function togglePackageItem(string $itemKey): void
    {
        if (! $this->canModify()) {
            return;
        }

        $this->selectedPackageItems[$itemKey] = ! ($this->selectedPackageItems[$itemKey] ?? false);
    }

    /**
     * Calculate the total for the selected package template tier.
     */
    #[Computed]
    public function selectedTemplateTotal(): float
    {
        if (! $this->selectedTier || empty($this->packageTemplates)) {
            return 0.0;
        }

        $selectedTemplate = collect($this->packageTemplates)->firstWhere('tier', $this->selectedTier);

        if (! $selectedTemplate) {
            return 0.0;
        }

        // Start with painting total
        $total = (float) $selectedTemplate['painting_total'];

        // Add warranty
        $total += (float) $selectedTemplate['warranty_amount'];

        // Add selected items
        foreach ($selectedTemplate['items'] as $item) {
            $isSelected = $this->selectedPackageItems[$item['key']] ?? $item['is_included_by_default'];
            if ($isSelected) {
                $total += (float) $item['price'];
            }
        }

        return round($total, 2);
    }

    /**
     * Get items total for the selected template.
     */
    #[Computed]
    public function selectedTemplateItemsTotal(): float
    {
        if (! $this->selectedTier || empty($this->packageTemplates)) {
            return 0.0;
        }

        $selectedTemplate = collect($this->packageTemplates)->firstWhere('tier', $this->selectedTier);

        if (! $selectedTemplate) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($selectedTemplate['items'] as $item) {
            $isSelected = $this->selectedPackageItems[$item['key']] ?? $item['is_included_by_default'];
            if ($isSelected) {
                $total += (float) $item['price'];
            }
        }

        return round($total, 2);
    }

    // ===== LEGACY PACKAGE METHODS =====

    #[Computed]
    public function selectedPackageTotal(): float
    {
        if (! $this->selectedPackageId) {
            return 0.0;
        }

        $selectedPackage = collect($this->packages)->firstWhere('id', $this->selectedPackageId);

        if (! $selectedPackage) {
            return 0.0;
        }

        // If package has no items, use the package's stored total
        if (empty($selectedPackage['items'])) {
            return (float) $selectedPackage['total'];
        }

        // Calculate total from approved items only
        $approvedItemsTotal = collect($selectedPackage['items'])
            ->filter(fn ($item) => $item['is_included'] && $item['customer_approved'])
            ->sum('price');

        // If no items are approved but package has items, still show $0
        // But if ALL items are approved, we want to show the full amount

        // Add warranty price if present
        $warrantyPrice = $selectedPackage['warranty_price'] ?? 0;

        // Apply discount
        $baseAmount = $approvedItemsTotal + $warrantyPrice;
        $discountAmount = $selectedPackage['discount_amount'] ?? 0;
        $taxableAmount = $baseAmount - $discountAmount;

        // Apply tax
        $taxRate = $selectedPackage['tax_rate'] ?? 0;
        $taxAmount = $taxableAmount * ($taxRate / 100);

        return round($taxableAmount + $taxAmount, 2);
    }

    #[Computed]
    public function optionalItemsTotal(): float
    {
        return collect($this->optionalItems)
            ->filter(fn ($item) => $item['is_selected_by_customer'])
            ->sum('price');
    }

    #[Computed]
    public function finalTotal(): float
    {
        // Use template total if using package templates
        if ($this->usePackageTemplates) {
            return $this->selectedTemplateTotal;
        }

        return round($this->selectedPackageTotal + $this->optionalItemsTotal, 2);
    }

    #[Computed]
    public function depositPercent(): float
    {
        return (float) \App\Models\CompanySetting::getInstance()->default_deposit_percent;
    }

    #[Computed]
    public function depositAmount(): float
    {
        return round($this->finalTotal * ($this->depositPercent / 100), 2);
    }

    #[Computed]
    public function balanceDue(): float
    {
        return round($this->finalTotal - $this->depositAmount, 2);
    }

    public function proceedToSignature(): void
    {
        // Validate selection based on system used
        if ($this->usePackageTemplates) {
            if (! $this->selectedTier) {
                session()->flash('error', 'Please select a package before proceeding.');

                return;
            }
        } else {
            if (! $this->selectedPackageId) {
                session()->flash('error', 'Please select a package before proceeding.');

                return;
            }
        }

        if ($this->estimate->status === 'accepted') {
            session()->flash('error', 'This proposal has already been accepted.');

            return;
        }

        $this->dispatch('proceed-to-signature', [
            'packageId' => $this->usePackageTemplates ? null : $this->selectedPackageId,
            'packageTier' => $this->usePackageTemplates ? $this->selectedTier : null,
            'finalTotal' => $this->finalTotal,
            'depositAmount' => $this->depositAmount,
            'balanceDue' => $this->balanceDue,
        ]);
    }

    #[On('estimate-accepted')]
    public function handleEstimateAccepted(): void
    {
        $this->estimate = $this->estimate->fresh();
    }

    public function canModify(): bool
    {
        return in_array($this->estimate->status, ['sent', 'draft'])
            && ! $this->estimate->isExpired();
    }

    protected function getPackageBaseTotal(): float
    {
        if (! $this->selectedPackageId) {
            // Use the first package's total as default for optional item calculations
            $firstPackage = collect($this->packages)->first();

            return $firstPackage ? $firstPackage['total'] : 0.0;
        }

        $selectedPackage = collect($this->packages)->firstWhere('id', $this->selectedPackageId);

        return $selectedPackage ? $selectedPackage['total'] : 0.0;
    }

    protected function getItemName(EstimatePackageItem $item): string
    {
        $itemable = $item->itemable;

        if (! $itemable) {
            return 'Item';
        }

        if ($itemable instanceof \App\Models\EstimateItem) {
            return $itemable->description ?? 'Line Item';
        }

        if ($itemable instanceof \App\Models\EstimateAreaSurface) {
            return $itemable->name ?? $itemable->productionRate?->name ?? 'Surface';
        }

        if ($itemable instanceof \App\Models\EstimateArea) {
            return $itemable->name ?? 'Area';
        }

        return 'Item';
    }

    protected function getItemDescription(EstimatePackageItem $item): ?string
    {
        $itemable = $item->itemable;

        if (! $itemable) {
            return null;
        }

        if ($itemable instanceof \App\Models\EstimateItem) {
            return $itemable->details;
        }

        if ($itemable instanceof \App\Models\EstimateAreaSurface) {
            $details = [];
            if ($itemable->quantity) {
                $details[] = number_format($itemable->quantity, 0).' '.($itemable->unit ?? 'sq ft');
            }
            if ($itemable->coats > 1) {
                $details[] = $itemable->coats.' coats';
            }

            return ! empty($details) ? implode(' - ', $details) : null;
        }

        if ($itemable instanceof \App\Models\EstimateArea) {
            if ($itemable->length && $itemable->width && $itemable->height) {
                return number_format($itemable->length, 1)."' x ".number_format($itemable->width, 1)."' x ".number_format($itemable->height, 1)."'";
            }
        }

        return null;
    }
}; ?>

<div class="space-y-8">
    {{-- Flash Messages --}}
    @if (session()->has('error'))
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    @if ($usePackageTemplates)
        {{-- NEW PACKAGE TEMPLATE SYSTEM --}}
        @include('partials.public.proposal-packages-templates')
    @else
        {{-- LEGACY PACKAGE SYSTEM --}}
        @include('partials.public.proposal-packages-legacy')
    @endif
</div>
