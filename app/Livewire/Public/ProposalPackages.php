<?php

namespace App\Livewire\Public;

use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateOptionalItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProposalPackages extends Component
{
    public Estimate $estimate;

    /** @var array<int, array<string, mixed>> */
    public array $packages = [];

    public ?int $selectedPackageId = null;

    /** @var array<int, array<string, mixed>> */
    public array $optionalItems = [];

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
        $this->selectedPackageId = $estimate->selected_package_id;
        $this->loadPackages();
        $this->loadOptionalItems();
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

    public function toggleLineItemApproval(int $packageItemId): void
    {
        // Cannot modify if already accepted
        if ($this->estimate->status === 'accepted') {
            return;
        }

        $packageItem = EstimatePackageItem::whereHas('package', function ($query) {
            $query->where('estimate_id', $this->estimate->id);
        })->find($packageItemId);

        if (! $packageItem) {
            return;
        }

        $packageItem->customer_approved = ! $packageItem->customer_approved;
        $packageItem->customer_approved_at = $packageItem->customer_approved ? now() : null;
        $packageItem->save();

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'item_approval_toggled',
            $packageItem->customer_approved
                ? 'Line item approved'
                : 'Line item removed',
            [
                'package_item_id' => $packageItemId,
                'customer_approved' => $packageItem->customer_approved,
            ]
        );

        // Reload packages to reflect changes
        $this->loadPackages();
        $this->loadOptionalItems();
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
        return round($this->selectedPackageTotal + $this->optionalItemsTotal, 2);
    }

    #[Computed]
    public function depositAmount(): float
    {
        $depositPercent = $this->estimate->deposit_percent ?? 30;

        return round($this->finalTotal * ($depositPercent / 100), 2);
    }

    #[Computed]
    public function balanceDue(): float
    {
        return round($this->finalTotal - $this->depositAmount, 2);
    }

    public function proceedToSignature(): void
    {
        if (! $this->selectedPackageId) {
            session()->flash('error', 'Please select a package before proceeding.');

            return;
        }

        if ($this->estimate->status === 'accepted') {
            session()->flash('error', 'This proposal has already been accepted.');

            return;
        }

        $this->dispatch('proceed-to-signature', [
            'packageId' => $this->selectedPackageId,
            'finalTotal' => $this->finalTotal,
            'depositAmount' => $this->depositAmount,
            'balanceDue' => $this->balanceDue,
        ]);
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

    public function render(): View
    {
        return view('livewire.public.proposal-packages');
    }
}
