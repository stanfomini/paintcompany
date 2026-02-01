<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Services\EstimatePackageService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PackageBuilder extends Component
{
    public Estimate $estimate;

    /** @var array<int, array{id: int, name: string, tier: string, description: ?string, discount_percent: float, is_recommended: bool, subtotal: float, tax_amount: float, total: float, deposit_amount: float, balance_due: float, items: array}> */
    public array $packages = [];

    public bool $showPackageModal = false;

    public ?int $editingPackageId = null;

    public string $packageName = '';

    public string $packageTier = 'standard';

    public string $packageDescription = '';

    public float $packageDiscountPercent = 0;

    protected function rules(): array
    {
        return [
            'packageName' => ['required', 'string', 'max:255'],
            'packageTier' => ['required', 'string', 'in:basic,standard,premium,custom'],
            'packageDescription' => ['nullable', 'string', 'max:2000'],
            'packageDiscountPercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    protected function messages(): array
    {
        return [
            'packageName.required' => 'Please enter a package name.',
            'packageTier.required' => 'Please select a tier.',
            'packageDiscountPercent.min' => 'Discount cannot be negative.',
            'packageDiscountPercent.max' => 'Discount cannot exceed 100%.',
        ];
    }

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
        $this->loadPackages();
    }

    public function loadPackages(): void
    {
        $this->packages = $this->estimate->packages()
            ->with(['items.itemable'])
            ->orderBy('sort_order')
            ->get()
            ->map(function (EstimatePackage $package) {
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
                    'items' => $package->items->map(function (EstimatePackageItem $item) {
                        return [
                            'id' => $item->id,
                            'itemable_type' => class_basename($item->itemable_type),
                            'name' => $this->getItemName($item),
                            'effective_price' => (float) $item->effective_price,
                            'is_included' => $item->is_included,
                            'customer_approved' => $item->customer_approved,
                        ];
                    })->toArray(),
                ];
            })
            ->toArray();
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

    public function openPackageModal(?int $packageId = null): void
    {
        $this->resetValidation();
        $this->editingPackageId = $packageId;

        if ($packageId) {
            $package = EstimatePackage::find($packageId);
            if ($package && $package->estimate_id === $this->estimate->id) {
                $this->packageName = $package->name;
                $this->packageTier = $package->tier;
                $this->packageDescription = $package->description ?? '';
                $this->packageDiscountPercent = (float) $package->discount_percent;
            }
        } else {
            $this->packageName = '';
            $this->packageTier = 'standard';
            $this->packageDescription = '';
            $this->packageDiscountPercent = 0;
        }

        $this->showPackageModal = true;
    }

    public function closePackageModal(): void
    {
        $this->showPackageModal = false;
        $this->editingPackageId = null;
        $this->resetValidation();
    }

    public function savePackage(): void
    {
        $this->validate();

        $service = app(EstimatePackageService::class);

        if ($this->editingPackageId) {
            $package = EstimatePackage::find($this->editingPackageId);
            if ($package && $package->estimate_id === $this->estimate->id) {
                $package->update([
                    'name' => $this->packageName,
                    'tier' => $this->packageTier,
                    'description' => $this->packageDescription ?: null,
                    'discount_percent' => $this->packageDiscountPercent,
                ]);

                $service->calculatePackageTotal($package);
            }
        } else {
            $package = $service->createPackage(
                $this->estimate,
                $this->packageName,
                $this->packageTier
            );

            $package->update([
                'description' => $this->packageDescription ?: null,
                'discount_percent' => $this->packageDiscountPercent,
            ]);

            $service->duplicateEstimateToPackage($this->estimate, $package);
        }

        $this->loadPackages();
        $this->closePackageModal();
        $this->dispatch('packages-updated');
    }

    public function deletePackage(int $id): void
    {
        $package = EstimatePackage::find($id);
        if ($package && $package->estimate_id === $this->estimate->id) {
            $package->delete();
            $this->loadPackages();
            $this->dispatch('packages-updated');
        }
    }

    public function createGoodBetterBest(): void
    {
        $service = app(EstimatePackageService::class);
        $service->createGoodBetterBestPackages($this->estimate);

        $this->loadPackages();
        $this->dispatch('packages-updated');
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

        $item->is_included = ! $item->is_included;
        $item->save();

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

    public function render(): View
    {
        return view('livewire.estimates.package-builder');
    }
}
