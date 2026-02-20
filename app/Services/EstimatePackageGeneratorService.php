<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
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
            ->with(['defaultItems', 'items'])
            ->ordered()
            ->get();

        if ($templates->isEmpty()) {
            return collect();
        }

        $packages = collect();

        foreach ($templates as $index => $template) {
            // Determine paint tier for this package template
            $packageTier = $this->mapTier($template->tier);
            $paintTier = match ($packageTier) {
                EstimatePackage::TIER_BASIC => 'good',
                EstimatePackage::TIER_STANDARD => 'better',
                EstimatePackage::TIER_PREMIUM => 'best',
                default => null,
            };

            $paintingTotal = $this->getPaintingTotal($estimate, $paintTier);
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
     *
     * @param  string|null  $paintTier  Paint quality tier (good/better/best) or null for default
     */
    protected function getPaintingTotal(Estimate $estimate, ?string $paintTier = null): float
    {
        if ($paintTier !== null && $estimate->hasPaintTiers()) {
            return $estimate->calculateAreasSubtotalForTier($paintTier);
        }

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

        // Resolve items: prefer defaultItems (pivot), fall back to legacy items() if empty
        $templateItems = $this->getTemplateItems($template);

        // Find warranty item from defaults (customer-selectable + percent pricing)
        $warrantyItem = $templateItems->first(
            fn (PackageTemplateItem $item) => $item->is_customer_selectable && $item->pricing_type === PackageTemplateItem::PRICING_PERCENT
        );

        // Calculate warranty cost from warranty item, fall back to template's warranty_percent
        if ($warrantyItem) {
            $warrantyPrice = $warrantyItem->calculatePrice($paintingTotal);
            $warrantyYears = $this->deriveWarrantyYears($warrantyItem->name);
            $warrantyType = $warrantyItem->name;
        } elseif ($template->warranty_months > 0) {
            // Backward compat: use template-level warranty settings
            $warrantyPrice = $template->calculateWarrantyCost($paintingTotal);
            $warrantyYears = ceil($template->warranty_months / 12);
            $warrantyType = $template->warranty_description;
        } else {
            $warrantyPrice = 0;
            $warrantyYears = 0;
            $warrantyType = null;
        }

        // Calculate services total (included by default items only, excluding warranty)
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
            'warranty_years' => $warrantyYears,
            'warranty_type' => $warrantyType,
            'is_recommended' => $template->is_popular,
            'is_selected' => $template->is_default,
            'is_visible' => true,
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
     * Calculate total of services that are included by default, excluding warranty items.
     */
    protected function calculateIncludedServicesTotal(PackageTemplate $template, float $paintingTotal): float
    {
        $items = $this->getTemplateItems($template);

        return $items
            ->filter(fn (PackageTemplateItem $item) => $item->is_included_by_default)
            ->reject(fn (PackageTemplateItem $item) => $this->isWarrantyItem($item))
            ->sum(fn (PackageTemplateItem $item) => $item->calculatePrice($paintingTotal));
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
            if ($area->is_fixed_price) {
                // Fixed-price areas are added as whole-area items (no surfaces)
                EstimatePackageItem::withoutEvents(function () use ($package, $area, &$sortOrder) {
                    EstimatePackageItem::create([
                        'package_id' => $package->id,
                        'itemable_type' => EstimateArea::class,
                        'itemable_id' => $area->id,
                        'quantity' => 1,
                        'price_override' => null,
                        'is_included' => true,
                        'customer_approved' => true,
                        'customer_approved_at' => now(),
                        'sort_order' => $sortOrder++,
                    ]);
                });

                continue;
            }

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
     * Add service items from template (excluding warranty items, which are handled separately).
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

        $items = $this->getTemplateItems($template)
            ->reject(fn (PackageTemplateItem $item) => $this->isWarrantyItem($item));

        foreach ($items->values() as $index => $templateItem) {
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
                    'is_customer_selectable' => $templateItem->is_customer_selectable,
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
     * Get the items for a template, preferring defaultItems (pivot) over legacy items().
     *
     * @return \Illuminate\Support\Collection<int, PackageTemplateItem>
     */
    protected function getTemplateItems(PackageTemplate $template): Collection
    {
        $defaultItems = $template->defaultItems;

        if ($defaultItems->isNotEmpty()) {
            return $defaultItems;
        }

        // Backward compat: fall back to legacy direct FK relationship
        return $template->items;
    }

    /**
     * Check if a template item is a warranty item (customer-selectable + percent pricing).
     */
    protected function isWarrantyItem(PackageTemplateItem $item): bool
    {
        return $item->is_customer_selectable && $item->pricing_type === PackageTemplateItem::PRICING_PERCENT;
    }

    /**
     * Derive warranty years from the item name (e.g., "2-Year Warranty" => 2).
     */
    protected function deriveWarrantyYears(string $name): int
    {
        if (preg_match('/(\d+)-Year/i', $name, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)-Month/i', $name, $matches)) {
            return (int) ceil((int) $matches[1] / 12);
        }

        return 0;
    }

    /**
     * Snapshot package item selections before clearing packages.
     * Keys by "{tier}:{area_name}:{surface_name}" for surfaces,
     * "{tier}:area:{area_name}" for fixed-price areas,
     * "{tier}:service:{item_description}" for service items.
     *
     * @return array<string, array{is_included: bool, customer_approved?: bool}>
     */
    public function snapshotPackageSelections(Estimate $estimate): array
    {
        $packages = $estimate->packages()->with(['items.itemable'])->get();
        $snapshot = [];

        foreach ($packages as $package) {
            foreach ($package->items as $item) {
                $key = $this->buildSnapshotKey($package, $item);
                if ($key === null) {
                    continue;
                }

                $data = ['is_included' => (bool) $item->is_included];

                if ($item->itemable instanceof EstimateItem) {
                    $data['customer_approved'] = (bool) $item->customer_approved;
                }

                $snapshot[$key] = $data;
            }
        }

        return $snapshot;
    }

    /**
     * Restore package item selections from a snapshot.
     * Matches newly generated items by the same composite key.
     *
     * @param  array<string, array{is_included: bool, customer_approved?: bool}>  $snapshot
     */
    public function restorePackageSelections(Estimate $estimate, array $snapshot): void
    {
        if (empty($snapshot)) {
            return;
        }

        $packages = $estimate->packages()->with(['items.itemable'])->get();

        foreach ($packages as $package) {
            $needsRecalc = false;

            foreach ($package->items as $item) {
                $key = $this->buildSnapshotKey($package, $item);
                if ($key === null || ! isset($snapshot[$key])) {
                    continue;
                }

                $saved = $snapshot[$key];
                $updates = [];

                if ((bool) $item->is_included !== $saved['is_included']) {
                    $updates['is_included'] = $saved['is_included'];
                }

                if (isset($saved['customer_approved']) && (bool) $item->customer_approved !== $saved['customer_approved']) {
                    $updates['customer_approved'] = $saved['customer_approved'];
                    $updates['customer_approved_at'] = $saved['customer_approved'] ? now() : null;
                }

                if (! empty($updates)) {
                    // Bypass model events to avoid cascading recalculations per item
                    EstimatePackageItem::withoutEvents(function () use ($item, $updates) {
                        $item->update($updates);
                    });
                    $needsRecalc = true;
                }
            }

            if ($needsRecalc) {
                $package->calculateTotals();
                $package->save();
            }
        }
    }

    /**
     * Build a composite key for a package item to match across regeneration.
     */
    protected function buildSnapshotKey(EstimatePackage $package, EstimatePackageItem $item): ?string
    {
        $itemable = $item->itemable;
        if (! $itemable) {
            return null;
        }

        $tier = $package->tier;

        if ($itemable instanceof EstimateAreaSurface) {
            $area = $itemable->area;
            if (! $area) {
                return null;
            }

            return "{$tier}:{$area->name}:{$itemable->name}";
        }

        if ($itemable instanceof EstimateArea) {
            return "{$tier}:area:{$itemable->name}";
        }

        if ($itemable instanceof EstimateItem) {
            return "{$tier}:service:{$itemable->description}";
        }

        return null;
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
