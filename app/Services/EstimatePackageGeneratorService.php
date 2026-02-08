<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\PackageTemplate;
use App\Models\ServiceCategory;
use Illuminate\Support\Collection;

class EstimatePackageGeneratorService
{
    /**
     * Generate estimate packages from package templates.
     *
     * @return Collection<int, EstimatePackage>
     */
    public function generateFromTemplates(Estimate $estimate, ?ServiceCategory $serviceCategory = null): Collection
    {
        $templates = PackageTemplate::active()
            ->forService($serviceCategory)
            ->with('items')
            ->ordered()
            ->get();

        if ($templates->isEmpty()) {
            return collect();
        }

        $paintingTotal = $this->getPaintingTotal($estimate);
        $packages = collect();

        foreach ($templates as $index => $template) {
            $package = $this->createPackageFromTemplate($estimate, $template, $paintingTotal, $index);
            $packages->push($package);
        }

        return $packages;
    }

    /**
     * Check if an estimate should use package templates.
     */
    public function shouldUseTemplates(Estimate $estimate, ?ServiceCategory $serviceCategory = null): bool
    {
        // Don't use templates if estimate already has legacy packages
        if ($estimate->packages()->exists()) {
            return false;
        }

        // Use templates if active templates exist (optionally filtered by service category)
        return PackageTemplate::active()->forService($serviceCategory)->exists();
    }

    /**
     * Get the painting total for warranty and percentage calculations.
     * areas_subtotal already includes margins (applied at area level).
     */
    protected function getPaintingTotal(Estimate $estimate): float
    {
        // areas_subtotal = sum of area customer prices (COGS + margin already applied)
        // See Estimate::calculateAreasSubtotal() - it sums getCustomerPrice() per area
        return (float) $estimate->areas_subtotal;
    }

    /**
     * Create an EstimatePackage from a PackageTemplate.
     */
    protected function createPackageFromTemplate(
        Estimate $estimate,
        PackageTemplate $template,
        float $paintingTotal,
        int $sortIndex
    ): EstimatePackage {
        // Map template tier to package tier
        $packageTier = $this->mapTier($template->tier);

        // Calculate warranty cost
        $warrantyPrice = $template->calculateWarrantyCost($paintingTotal);

        // Calculate services total (included by default items only)
        $servicesTotal = $this->calculateIncludedServicesTotal($template, $paintingTotal);

        // Subtotal = painting + services (warranty is shown separately in UI)
        $subtotal = $paintingTotal + $servicesTotal;

        // Base amount for tax = subtotal + warranty
        $baseAmount = $subtotal + $warrantyPrice;

        // Get tax rate from estimate
        $taxRate = (float) ($estimate->tax_rate ?? 0);
        $taxAmount = $baseAmount * ($taxRate / 100);

        // Create the package
        $package = EstimatePackage::create([
            'estimate_id' => $estimate->id,
            'name' => $template->name,
            'tier' => $packageTier,
            'description' => $template->description,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'discount_amount' => 0,
            'total' => $baseAmount + $taxAmount,
            'warranty_price' => $warrantyPrice,
            'warranty_years' => $template->warranty_months > 0 ? ceil($template->warranty_months / 12) : 0,
            'warranty_type' => $template->warranty_months > 0 ? $template->warranty_description : null,
            'is_recommended' => $template->is_popular,
            'is_selected' => $template->is_default,
            'sort_order' => $sortIndex,
            'service_category_id' => $template->service_category_id,
        ]);

        // If this is the default package, set it as selected on the estimate
        if ($template->is_default) {
            $estimate->update(['selected_package_id' => $package->id]);
        }

        // Add painting work (areas) as package items
        $this->addPaintingItems($package, $estimate);

        // Add service items from template
        $this->addServiceItems($package, $estimate, $template, $paintingTotal);

        return $package;
    }

    /**
     * Calculate total of services that are included by default.
     */
    protected function calculateIncludedServicesTotal(PackageTemplate $template, float $paintingTotal): float
    {
        return $template->items
            ->filter(fn ($item) => $item->is_included_by_default)
            ->sum(fn ($item) => $item->calculatePrice($paintingTotal));
    }

    /**
     * Add painting work (surfaces) as package items.
     * Each surface is added individually so estimators can toggle them per-package.
     */
    protected function addPaintingItems(EstimatePackage $package, Estimate $estimate): void
    {
        $areas = $estimate->areas()->with('surfaces')->orderBy('sort_order')->get();

        $sortOrder = 0;
        foreach ($areas as $area) {
            foreach ($area->surfaces()->orderBy('sort_order')->get() as $surface) {
                // Disable auto-calculation during creation to avoid issues
                EstimatePackageItem::withoutEvents(function () use ($package, $surface, &$sortOrder) {
                    EstimatePackageItem::create([
                        'package_id' => $package->id,
                        'itemable_type' => EstimateAreaSurface::class,
                        'itemable_id' => $surface->id,
                        'quantity' => 1,
                        'price_override' => null, // Use surface's calculated price
                        'is_included' => $surface->is_included, // Respect surface's inclusion status
                        'customer_approved' => true, // Painting work is always approved
                        'customer_approved_at' => now(),
                        'sort_order' => $sortOrder++,
                    ]);
                });
            }
        }
    }

    /**
     * Add service items from template.
     */
    protected function addServiceItems(
        EstimatePackage $package,
        Estimate $estimate,
        PackageTemplate $template,
        float $paintingTotal
    ): void {
        // Start after all surfaces
        $startSort = EstimateAreaSurface::whereIn(
            'area_id',
            $estimate->areas()->pluck('id')
        )->count();

        foreach ($template->items as $index => $templateItem) {
            $price = $templateItem->calculatePrice($paintingTotal);

            // Create an EstimateItem for this service
            $estimateItem = EstimateItem::create([
                'estimate_id' => $estimate->id,
                'description' => $templateItem->name,
                'details' => $templateItem->description,
                'quantity' => 1,
                'unit' => 'service',
                'unit_price' => $price,
                'total' => $price,
                'cost' => (float) $templateItem->cost,
                'sort_order' => 1000 + $index, // Put services after regular items
            ]);

            // Link to package
            EstimatePackageItem::withoutEvents(function () use ($package, $estimateItem, $templateItem, $startSort, $index) {
                EstimatePackageItem::create([
                    'package_id' => $package->id,
                    'itemable_type' => EstimateItem::class,
                    'itemable_id' => $estimateItem->id,
                    'quantity' => 1,
                    'price_override' => null,
                    'is_included' => true,
                    'customer_approved' => $templateItem->is_included_by_default,
                    'customer_approved_at' => $templateItem->is_included_by_default ? now() : null,
                    'sort_order' => $startSort + $index,
                ]);
            });
        }
    }

    /**
     * Map template tier to package tier constant.
     */
    protected function mapTier(string $templateTier): string
    {
        return match ($templateTier) {
            PackageTemplate::TIER_GOOD => EstimatePackage::TIER_BASIC,
            PackageTemplate::TIER_BETTER => EstimatePackage::TIER_STANDARD,
            PackageTemplate::TIER_BEST => EstimatePackage::TIER_PREMIUM,
            default => EstimatePackage::TIER_CUSTOM,
        };
    }

    /**
     * Remove all generated packages from an estimate.
     * Useful for regenerating packages after template changes.
     */
    public function clearGeneratedPackages(Estimate $estimate): void
    {
        // Delete package items first (foreign key constraint)
        EstimatePackageItem::whereIn(
            'package_id',
            $estimate->packages()->pluck('id')
        )->delete();

        // Delete packages
        $estimate->packages()->delete();

        // Clear selected package
        $estimate->update(['selected_package_id' => null]);
    }

    /**
     * Regenerate packages from templates (clear and recreate).
     *
     * @return Collection<int, EstimatePackage>
     */
    public function regenerateFromTemplates(Estimate $estimate, ?ServiceCategory $serviceCategory = null): Collection
    {
        $this->clearGeneratedPackages($estimate);

        return $this->generateFromTemplates($estimate, $serviceCategory);
    }
}
