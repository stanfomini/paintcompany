<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use Illuminate\Database\Eloquent\Model;

class EstimatePackageService
{
    /**
     * Create a new package for an estimate.
     */
    public function createPackage(Estimate $estimate, string $name, string $tier = EstimatePackage::TIER_STANDARD): EstimatePackage
    {
        // Get the next sort order
        $maxSortOrder = $estimate->packages()->max('sort_order') ?? 0;

        return EstimatePackage::create([
            'estimate_id' => $estimate->id,
            'name' => $name,
            'tier' => $tier,
            'tax_rate' => $estimate->tax_rate ?? 0,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'is_recommended' => false,
            'is_selected' => false,
            'sort_order' => $maxSortOrder + 1,
        ]);
    }

    /**
     * Duplicate all items, areas, and surfaces from an estimate to a package.
     */
    public function duplicateEstimateToPackage(Estimate $estimate, EstimatePackage $package): void
    {
        $sortOrder = 0;

        // Copy all estimate items
        foreach ($estimate->items as $item) {
            EstimatePackageItem::create([
                'package_id' => $package->id,
                'itemable_type' => EstimateItem::class,
                'itemable_id' => $item->id,
                'quantity' => $item->quantity,
                'is_included' => true,
                'customer_approved' => true,
                'customer_approved_at' => now(),
                'sort_order' => ++$sortOrder,
            ]);
        }

        // Copy all estimate areas and their surfaces
        foreach ($estimate->areas as $area) {
            // Add the area itself
            EstimatePackageItem::create([
                'package_id' => $package->id,
                'itemable_type' => EstimateArea::class,
                'itemable_id' => $area->id,
                'is_included' => true,
                'customer_approved' => true,
                'customer_approved_at' => now(),
                'sort_order' => ++$sortOrder,
            ]);

            // Add each surface within the area
            foreach ($area->surfaces as $surface) {
                EstimatePackageItem::create([
                    'package_id' => $package->id,
                    'itemable_type' => EstimateAreaSurface::class,
                    'itemable_id' => $surface->id,
                    'quantity' => $surface->quantity,
                    'is_included' => true,
                    'customer_approved' => true,
                    'customer_approved_at' => now(),
                    'sort_order' => ++$sortOrder,
                ]);
            }
        }

        // Recalculate package totals
        $this->calculatePackageTotal($package);
    }

    /**
     * Add an item (EstimateItem, EstimateArea, or EstimateAreaSurface) to a package.
     */
    public function addItemToPackage(EstimatePackage $package, Model $item, ?float $priceOverride = null): EstimatePackageItem
    {
        // Validate the item is of an allowed type
        $allowedTypes = [EstimateItem::class, EstimateArea::class, EstimateAreaSurface::class];
        if (! in_array(get_class($item), $allowedTypes, true)) {
            throw new \InvalidArgumentException('Item must be an EstimateItem, EstimateArea, or EstimateAreaSurface.');
        }

        // Get the next sort order
        $maxSortOrder = $package->items()->max('sort_order') ?? 0;

        // Get quantity from item if available
        $quantity = null;
        if ($item instanceof EstimateItem || $item instanceof EstimateAreaSurface) {
            $quantity = $item->quantity;
        }

        $packageItem = EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => get_class($item),
            'itemable_id' => $item->id,
            'quantity' => $quantity,
            'price_override' => $priceOverride,
            'is_included' => true,
            'customer_approved' => true,
            'customer_approved_at' => now(),
            'sort_order' => $maxSortOrder + 1,
        ]);

        // Totals are recalculated via model events on EstimatePackageItem
        return $packageItem;
    }

    /**
     * Remove an item from a package.
     */
    public function removeItemFromPackage(EstimatePackage $package, EstimatePackageItem $packageItem): void
    {
        // Verify the item belongs to this package
        if ($packageItem->package_id !== $package->id) {
            throw new \InvalidArgumentException('The package item does not belong to this package.');
        }

        // Delete the item - totals will be recalculated via model events
        $packageItem->delete();
    }

    /**
     * Calculate the total for a package.
     *
     * IMPORTANT: Painting work uses AREA-level pricing (margin applied once per area).
     * Surfaces are grouped by area and summed using the area's customer price.
     * Service items (EstimateItem) use their own effective price.
     */
    public function calculatePackageTotal(EstimatePackage $package): void
    {
        $items = $package->items()
            ->where('is_included', true)
            ->where('customer_approved', true)
            ->with('itemable')
            ->get();

        // Separate surface items from service items
        $surfaceItems = $items->filter(
            fn ($item) => $item->itemable_type === EstimateAreaSurface::class
        );
        $serviceItems = $items->filter(
            fn ($item) => $item->itemable_type !== EstimateAreaSurface::class
        );

        // Group surfaces by area and use AREA-level customer price
        // This ensures margin is applied once per area, not per surface
        $paintingTotal = 0.0;
        $areaIds = $surfaceItems
            ->map(fn ($item) => $item->itemable?->area_id)
            ->filter()
            ->unique();

        foreach ($areaIds as $areaId) {
            $area = EstimateArea::find($areaId);
            if ($area) {
                // Use area's customer price (COGS + cleanup + sundries + margin)
                $paintingTotal += $area->getCustomerPrice();
            }
        }

        // Add service items (already have correct prices)
        $servicesTotal = $serviceItems->sum(fn ($item) => $item->effective_price);

        $subtotal = $paintingTotal + $servicesTotal;
        $package->subtotal = $subtotal;

        // Add warranty price to base amount
        $baseAmount = $subtotal + ($package->warranty_price ?? 0);

        // Calculate discount
        $discountAmount = $package->discount_amount ?? 0;
        if ($package->discount_percent > 0) {
            $discountAmount = $baseAmount * ($package->discount_percent / 100);
        }
        $package->discount_amount = $discountAmount;

        // Calculate subtotal after discount (before tax)
        $taxableAmount = $baseAmount - $discountAmount;

        // Calculate tax
        $taxRate = (float) ($package->tax_rate ?? 0);
        $package->tax_amount = $taxableAmount * ($taxRate / 100);

        // Calculate total
        $package->total = $taxableAmount + $package->tax_amount;

        $package->save();
    }

    /**
     * Select a package for the estimate.
     */
    public function selectPackage(Estimate $estimate, EstimatePackage $package): void
    {
        // Verify the package belongs to this estimate
        if ($package->estimate_id !== $estimate->id) {
            throw new \InvalidArgumentException('The package does not belong to this estimate.');
        }

        // Deselect all other packages for this estimate
        $estimate->packages()
            ->where('id', '!=', $package->id)
            ->update(['is_selected' => false]);

        // Select this package
        $package->is_selected = true;
        $package->save();

        // Update the estimate's selected_package_id
        $estimate->selected_package_id = $package->id;
        $estimate->save();
    }

    /**
     * Approve a line item in a package.
     */
    public function approveLineItem(EstimatePackageItem $item): void
    {
        $item->customer_approved = true;
        $item->customer_approved_at = now();
        $item->save();

        // Recalculate package totals
        $this->calculatePackageTotal($item->package);
    }

    /**
     * Reject a line item in a package.
     */
    public function rejectLineItem(EstimatePackageItem $item): void
    {
        $item->customer_approved = false;
        $item->customer_approved_at = null;
        $item->save();

        // Recalculate package totals (rejected items are not counted)
        $this->calculatePackageTotal($item->package);
    }

    /**
     * Create Good/Better/Best packages for an estimate.
     *
     * @return array<EstimatePackage>
     */
    public function createGoodBetterBestPackages(Estimate $estimate): array
    {
        $packages = [];

        // Create Good (basic) package
        $goodPackage = $this->createPackage($estimate, 'Good', EstimatePackage::TIER_BASIC);
        $goodPackage->description = 'Our essential package with quality workmanship.';
        $goodPackage->is_recommended = false;
        $goodPackage->sort_order = 1;
        $goodPackage->save();
        $this->duplicateEstimateToPackage($estimate, $goodPackage);
        $packages[] = $goodPackage;

        // Create Better (standard) package - recommended
        $betterPackage = $this->createPackage($estimate, 'Better', EstimatePackage::TIER_STANDARD);
        $betterPackage->description = 'Our most popular package with premium materials.';
        $betterPackage->is_recommended = true;
        $betterPackage->sort_order = 2;
        $betterPackage->save();
        $this->duplicateEstimateToPackage($estimate, $betterPackage);
        $packages[] = $betterPackage;

        // Create Best (premium) package
        $bestPackage = $this->createPackage($estimate, 'Best', EstimatePackage::TIER_PREMIUM);
        $bestPackage->description = 'Our premium package with the finest materials and extended warranty.';
        $bestPackage->is_recommended = false;
        $bestPackage->sort_order = 3;
        $bestPackage->save();
        $this->duplicateEstimateToPackage($estimate, $bestPackage);
        $packages[] = $bestPackage;

        return $packages;
    }
}
