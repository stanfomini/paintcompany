<?php

namespace App\Services;

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\WarrantyReserve;

/**
 * Service for breaking down estimate costs for internal visibility.
 *
 * IMPORTANT: This service does NOT recalculate pricing. It pulls numbers
 * from the SAME model methods the estimate page uses:
 * - EstimateArea::getFullCOGS() for area costs
 * - EstimateArea::getCustomerPrice() for area prices (margin already applied)
 * - Estimate stored subtotal/total for the customer-facing price
 *
 * The breakdown just decomposes those numbers into components for analysis.
 * Margins are applied ONCE at the area level - never again here.
 */
class EstimateCostBreakdownService
{
    /**
     * Get the active package for an estimate.
     *
     * Priority: signed package > selected package > recommended package
     * Returns null if no packages exist (legacy estimate)
     */
    public function getActivePackage(Estimate $estimate): ?EstimatePackage
    {
        // First check for signed package
        $signedPackage = $estimate->packages()
            ->whereNotNull('signed_at')
            ->first();

        if ($signedPackage) {
            return $signedPackage;
        }

        // Then check for selected package
        $selectedPackage = $estimate->packages()
            ->where('is_selected', true)
            ->first();

        if ($selectedPackage) {
            return $selectedPackage;
        }

        // Finally check for recommended package
        return $estimate->packages()
            ->where('is_recommended', true)
            ->first();
    }

    /**
     * Get package info for breakdown display.
     *
     * @return array<string, mixed>|null
     */
    public function getPackageInfo(?EstimatePackage $package): ?array
    {
        if (! $package) {
            return null;
        }

        return [
            'id' => $package->id,
            'name' => $package->name,
            'tier' => $package->tier,
            'tier_label' => $package->tier_label,
            'is_signed' => $package->signed_at !== null,
            'signed_at' => $package->signed_at?->format('M j, Y'),
            'is_selected' => $package->is_selected,
            'is_recommended' => $package->is_recommended,
            'total' => (float) $package->total,
            'warranty_years' => $package->warranty_years,
            'warranty_price' => (float) ($package->warranty_price ?? 0),
        ];
    }

    /**
     * Get warranty reserve info for a package.
     *
     * @return array<string, mixed>|null
     */
    public function getWarrantyReserveInfo(?EstimatePackage $package): ?array
    {
        if (! $package) {
            return null;
        }

        $reserve = WarrantyReserve::where('estimate_package_id', $package->id)->first();

        if (! $reserve) {
            // No reserve created yet - return null or a "pending" state
            if ($package->warranty_years && $package->warranty_years > 0) {
                return [
                    'status' => 'not_created',
                    'message' => 'Reserve will be created when estimate is signed',
                    'warranty_years' => $package->warranty_years,
                    'expected_reserve' => (float) ($package->warranty_price ?? 0),
                ];
            }

            return null;
        }

        return [
            'id' => $reserve->id,
            'status' => $reserve->status,
            'status_label' => WarrantyReserve::getStatuses()[$reserve->status] ?? $reserve->status,
            'warranty_years' => $reserve->warranty_years,
            'amount_reserved' => (float) $reserve->amount_reserved,
            'amount_claimed' => (float) $reserve->amount_claimed,
            'remaining_balance' => $reserve->remaining_balance,
            'claim_percentage' => round($reserve->claim_percentage, 1),
            'is_valid' => $reserve->is_valid,
            'warranty_starts_at' => $reserve->warranty_starts_at?->format('M j, Y'),
            'warranty_expires_at' => $reserve->warranty_expires_at?->format('M j, Y'),
            'days_until_expiration' => $reserve->days_until_expiration,
            'has_available_balance' => $reserve->has_available_balance,
            'job_id' => $reserve->job_id,
        ];
    }

    /**
     * Calculate complete breakdown for an estimate.
     *
     * If the estimate has packages, calculates from the active package only.
     * Otherwise falls back to legacy behavior (all areas + items).
     *
     * @return array<string, mixed>
     */
    public function calculateBreakdown(Estimate $estimate, array $allocationContext = []): array
    {
        $companySettings = CompanySetting::getInstance();

        // Check for active package
        $activePackage = $this->getActivePackage($estimate);

        if ($activePackage) {
            return $this->calculatePackageBreakdown($estimate, $activePackage, $allocationContext);
        }

        // Legacy behavior for estimates without packages
        return $this->calculateLegacyBreakdown($estimate, $allocationContext);
    }

    /**
     * Calculate breakdown from a specific package.
     *
     * Only includes areas and items that are part of the package.
     *
     * @return array<string, mixed>
     */
    public function calculatePackageBreakdown(Estimate $estimate, EstimatePackage $package, array $allocationContext = []): array
    {
        $companySettings = CompanySetting::getInstance();

        // Get package items with their itemables
        $packageItems = $package->items()
            ->included()
            ->with('itemable')
            ->get();

        // Collect area IDs and item IDs from package
        $areaIds = [];
        $itemIds = [];

        foreach ($packageItems as $packageItem) {
            $itemable = $packageItem->itemable;
            if (! $itemable) {
                continue;
            }

            if ($itemable instanceof EstimateAreaSurface) {
                // Surface belongs to an area - track the area
                if ($itemable->area_id && ! in_array($itemable->area_id, $areaIds)) {
                    $areaIds[] = $itemable->area_id;
                }
            } elseif ($itemable instanceof EstimateArea) {
                // Direct area reference
                if (! in_array($itemable->id, $areaIds)) {
                    $areaIds[] = $itemable->id;
                }
            } elseif ($itemable instanceof EstimateItem) {
                // Line item
                if (! in_array($itemable->id, $itemIds)) {
                    $itemIds[] = $itemable->id;
                }
            }
        }

        // Load only the areas and items from this package
        $areas = $estimate->areas()
            ->whereIn('id', $areaIds)
            ->with(['surfaces.productionRate', 'addons'])
            ->get();

        $items = $estimate->items()
            ->whereIn('id', $itemIds)
            ->orderBy('sort_order')
            ->get();

        $areaBreakdowns = [];
        $totals = $this->initializeTotals();

        foreach ($areas as $area) {
            $areaBreakdown = $this->calculateAreaBreakdown($area);
            $areaBreakdowns[] = $areaBreakdown;

            // Accumulate totals from area model methods
            $totals['labor_hours'] += $areaBreakdown['detail']['labor_hours'];
            $totals['cleanup_hours'] += $areaBreakdown['detail']['cleanup_hours'];
            $totals['labor_cost'] += $areaBreakdown['detail']['labor_cost'];
            $totals['cleanup_cost'] += $areaBreakdown['detail']['cleanup_cost'];
            $totals['sundries_cost'] += $areaBreakdown['detail']['sundries_cost'];
            $totals['material_cost'] += $areaBreakdown['detail']['material_cost'];
            $totals['material_tax'] += $areaBreakdown['detail']['material_tax'];
            $totals['labor_tax'] += $areaBreakdown['detail']['labor_tax'];
            $totals['addon_cost'] += $areaBreakdown['detail']['addon_cost'];
            $totals['addon_labor_hours'] += $areaBreakdown['detail']['addon_labor_hours'] ?? 0;
            $totals['addon_labor_cost'] += $areaBreakdown['detail']['addon_labor_cost'] ?? 0;
            $totals['addon_material_cost'] += $areaBreakdown['detail']['addon_material_cost'] ?? 0;
            $totals['cogs'] += $areaBreakdown['cogs'];
            $totals['customer_price'] += $areaBreakdown['customer_price'];
        }

        // Process line items with vendor cost tracking
        $itemsBreakdown = [];
        $itemsTotal = 0;
        $itemsVendorCost = 0;
        foreach ($items as $item) {
            $itemTotal = (float) $item->total;
            $vendorCost = $item->cost; // Uses accessor - defaults to total if null
            $profit = $itemTotal - $vendorCost;

            $itemsBreakdown[] = [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'total' => round($itemTotal, 2),
                'vendor_cost' => round($vendorCost, 2),
                'profit' => round($profit, 2),
                'has_vendor_cost' => $item->has_vendor_cost,
            ];
            $itemsTotal += $itemTotal;
            $itemsVendorCost += $vendorCost;
        }

        // Items: vendor cost goes to COGS, customer price is the total
        $totals['items_total'] = $itemsTotal;
        $totals['items_vendor_cost'] = $itemsVendorCost;
        $totals['cogs'] += $itemsVendorCost; // Only vendor cost is COGS
        $totals['customer_price'] += $itemsTotal; // Customer pays the full total

        // The package total IS the customer price (authoritative, stored at generation time)
        $packageTotal = (float) $package->total;
        $customerPrice = $packageTotal;

        // COGS is calculated from the package's included areas/surfaces
        $totalCogs = $totals['cogs'];

        // Keep the dynamically calculated price for reconciliation reference
        $calculatedPrice = $totals['customer_price'];

        // Gross profit = package total - COGS
        $grossProfit = $customerPrice - $totalCogs;
        $grossMarginPercent = $customerPrice > 0 ? ($grossProfit / $customerPrice) * 100 : 0;

        // Effective COGS-to-price ratio (for display)
        $effectiveMarginRate = $customerPrice > 0 ? $totalCogs / $customerPrice : 0;
        $effectiveMarginPercent = round($effectiveMarginRate * 100, 2);

        // Calculate allocations (marketing + sales + prod mgmt as % of revenue)
        $defaultSettings = $this->getCurrentSettings($estimate);
        $totalLaborHours = $totals['labor_hours'] + $totals['cleanup_hours'] + $totals['addon_labor_hours'];
        $allocations = $this->calculateOverheadAllocations($customerPrice, $defaultSettings, $totalLaborHours, $allocationContext);

        // Net profit = gross profit minus allocations
        $netProfit = $grossProfit - $allocations['total'];
        $netMarginPercent = $customerPrice > 0 ? ($netProfit / $customerPrice) * 100 : 0;

        // Target comparison
        $targetNetMargin = $defaultSettings['net_profit_margin_percent'];
        $marginDelta = $netMarginPercent - $targetNetMargin;

        // Get crew bonus info
        $crewBonus = $this->getCrewBonusInfo($estimate, $customerPrice);

        return [
            'estimate_id' => $estimate->id,
            'estimate_number' => $estimate->estimate_number,
            'scope' => $estimate->project_type ?? 'interior',
            'package' => $this->getPackageInfo($package),
            'warranty_reserve' => null,
            'areas' => $areaBreakdowns,
            'items' => $itemsBreakdown,
            'items_total' => round($itemsTotal, 2),
            'totals' => [
                'labor_hours' => round($totals['labor_hours'], 2),
                'cleanup_hours' => round($totals['cleanup_hours'], 2),
                'addon_labor_hours' => round($totals['addon_labor_hours'], 2),
                'total_hours' => round($totals['labor_hours'] + $totals['cleanup_hours'] + $totals['addon_labor_hours'], 2),
            ],
            'cogs' => [
                'labor_cost' => round($totals['labor_cost'], 2),
                'cleanup_cost' => round($totals['cleanup_cost'], 2),
                'sundries_cost' => round($totals['sundries_cost'], 2),
                'material_cost' => round($totals['material_cost'], 2),
                'material_tax' => round($totals['material_tax'], 2),
                'labor_tax' => round($totals['labor_tax'], 2),
                'addon_cost' => round($totals['addon_cost'], 2),
                'addon_labor_hours' => round($totals['addon_labor_hours'], 2),
                'addon_labor_cost' => round($totals['addon_labor_cost'], 2),
                'addon_material_cost' => round($totals['addon_material_cost'], 2),
                'items_total' => round($totals['items_total'], 2),
                'items_vendor_cost' => round($totals['items_vendor_cost'], 2),
                'items_profit' => round($totals['items_total'] - $totals['items_vendor_cost'], 2),
                'warranty_price' => 0,
                'total' => round($totalCogs, 2),
            ],
            'pricing' => [
                'margin_rate' => $effectiveMarginRate,
                'margin_percent' => $effectiveMarginPercent,
                'customer_price' => round($customerPrice, 2),
                'package_total' => round($packageTotal, 2),
            ],
            'gross_profit' => [
                'amount' => round($grossProfit, 2),
                'percent' => round($grossMarginPercent, 2),
            ],
            'allocations' => $allocations,
            'crew_bonus' => $crewBonus,
            'net_profit' => [
                'amount' => round($netProfit, 2),
                'percent' => round($netMarginPercent, 2),
                'target_percent' => $targetNetMargin,
                'delta_percent' => round($marginDelta, 2),
                'is_profitable' => $netProfit > 0,
                'meets_target' => $netMarginPercent >= $targetNetMargin,
                'after_bonus_amount' => $crewBonus ? round($netProfit - $crewBonus['bonus_amount'], 2) : null,
                'after_bonus_percent' => $crewBonus && $customerPrice > 0 ? round(($netProfit - $crewBonus['bonus_amount']) / $customerPrice * 100, 2) : null,
            ],
            'reconciliation' => [
                'stored_subtotal' => round($packageTotal, 2),
                'calculated_price' => round($calculatedPrice, 2),
                'difference' => round($packageTotal - $calculatedPrice, 2),
            ],
            'settings_used' => $defaultSettings,
        ];
    }

    /**
     * Calculate breakdown for legacy estimates without packages.
     *
     * Uses all areas and items on the estimate.
     *
     * @return array<string, mixed>
     */
    protected function calculateLegacyBreakdown(Estimate $estimate, array $allocationContext = []): array
    {
        $companySettings = CompanySetting::getInstance();

        $areas = $estimate->areas()->with(['surfaces.productionRate', 'addons'])->get();
        $items = $estimate->items()->orderBy('sort_order')->get();
        $areaBreakdowns = [];
        $totals = $this->initializeTotals();

        foreach ($areas as $area) {
            $areaBreakdown = $this->calculateAreaBreakdown($area);
            $areaBreakdowns[] = $areaBreakdown;

            // Accumulate totals from area model methods
            $totals['labor_hours'] += $areaBreakdown['detail']['labor_hours'];
            $totals['cleanup_hours'] += $areaBreakdown['detail']['cleanup_hours'];
            $totals['labor_cost'] += $areaBreakdown['detail']['labor_cost'];
            $totals['cleanup_cost'] += $areaBreakdown['detail']['cleanup_cost'];
            $totals['sundries_cost'] += $areaBreakdown['detail']['sundries_cost'];
            $totals['material_cost'] += $areaBreakdown['detail']['material_cost'];
            $totals['material_tax'] += $areaBreakdown['detail']['material_tax'];
            $totals['labor_tax'] += $areaBreakdown['detail']['labor_tax'];
            $totals['addon_cost'] += $areaBreakdown['detail']['addon_cost'];
            $totals['addon_labor_hours'] += $areaBreakdown['detail']['addon_labor_hours'] ?? 0;
            $totals['addon_labor_cost'] += $areaBreakdown['detail']['addon_labor_cost'] ?? 0;
            $totals['addon_material_cost'] += $areaBreakdown['detail']['addon_material_cost'] ?? 0;
            $totals['cogs'] += $areaBreakdown['cogs'];
            $totals['customer_price'] += $areaBreakdown['customer_price'];
        }

        // Process line items with vendor cost tracking
        $itemsBreakdown = [];
        $itemsTotal = 0;
        $itemsVendorCost = 0;
        foreach ($items as $item) {
            $itemTotal = (float) $item->total;
            $vendorCost = $item->cost; // Uses accessor - defaults to total if null
            $profit = $itemTotal - $vendorCost;

            $itemsBreakdown[] = [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'total' => round($itemTotal, 2),
                'vendor_cost' => round($vendorCost, 2),
                'profit' => round($profit, 2),
                'has_vendor_cost' => $item->has_vendor_cost,
            ];
            $itemsTotal += $itemTotal;
            $itemsVendorCost += $vendorCost;
        }

        // Items: vendor cost goes to COGS, customer price is the total
        $totals['items_total'] = $itemsTotal;
        $totals['items_vendor_cost'] = $itemsVendorCost;
        $totals['cogs'] += $itemsVendorCost; // Only vendor cost is COGS
        $totals['customer_price'] += $itemsTotal; // Customer pays the full total

        // Customer price = sum of per-area customer prices + items
        $customerPrice = $totals['customer_price'];
        $totalCogs = $totals['cogs'];

        // Gross profit = customer price - COGS
        $grossProfit = $customerPrice - $totalCogs;
        $grossMarginPercent = $customerPrice > 0 ? ($grossProfit / $customerPrice) * 100 : 0;

        // Effective COGS-to-price ratio
        $effectiveMarginRate = $customerPrice > 0 ? $totalCogs / $customerPrice : 0;
        $effectiveMarginPercent = round($effectiveMarginRate * 100, 2);

        // Calculate allocations
        $defaultSettings = $this->getCurrentSettings($estimate);
        $totalLaborHours = $totals['labor_hours'] + $totals['cleanup_hours'] + $totals['addon_labor_hours'];
        $allocations = $this->calculateOverheadAllocations($customerPrice, $defaultSettings, $totalLaborHours, $allocationContext);

        // Net profit
        $netProfit = $grossProfit - $allocations['total'];
        $netMarginPercent = $customerPrice > 0 ? ($netProfit / $customerPrice) * 100 : 0;

        $targetNetMargin = $defaultSettings['net_profit_margin_percent'];
        $marginDelta = $netMarginPercent - $targetNetMargin;

        // Get crew bonus info
        $crewBonus = $this->getCrewBonusInfo($estimate, $customerPrice);

        return [
            'estimate_id' => $estimate->id,
            'estimate_number' => $estimate->estimate_number,
            'scope' => $estimate->project_type ?? 'interior',
            'package' => null, // No package for legacy estimates
            'warranty_reserve' => null, // No warranty tracking for legacy estimates
            'areas' => $areaBreakdowns,
            'items' => $itemsBreakdown,
            'items_total' => round($itemsTotal, 2),
            'totals' => [
                'labor_hours' => round($totals['labor_hours'], 2),
                'cleanup_hours' => round($totals['cleanup_hours'], 2),
                'addon_labor_hours' => round($totals['addon_labor_hours'], 2),
                'total_hours' => round($totals['labor_hours'] + $totals['cleanup_hours'] + $totals['addon_labor_hours'], 2),
            ],
            'cogs' => [
                'labor_cost' => round($totals['labor_cost'], 2),
                'cleanup_cost' => round($totals['cleanup_cost'], 2),
                'sundries_cost' => round($totals['sundries_cost'], 2),
                'material_cost' => round($totals['material_cost'], 2),
                'material_tax' => round($totals['material_tax'], 2),
                'labor_tax' => round($totals['labor_tax'], 2),
                'addon_cost' => round($totals['addon_cost'], 2),
                'addon_labor_hours' => round($totals['addon_labor_hours'], 2),
                'addon_labor_cost' => round($totals['addon_labor_cost'], 2),
                'addon_material_cost' => round($totals['addon_material_cost'], 2),
                'items_total' => round($totals['items_total'], 2),
                'items_vendor_cost' => round($totals['items_vendor_cost'], 2),
                'items_profit' => round($totals['items_total'] - $totals['items_vendor_cost'], 2),
                'warranty_price' => 0,
                'total' => round($totalCogs, 2),
            ],
            'pricing' => [
                'margin_rate' => $effectiveMarginRate,
                'margin_percent' => $effectiveMarginPercent,
                'customer_price' => round($customerPrice, 2),
                'package_total' => null,
            ],
            'gross_profit' => [
                'amount' => round($grossProfit, 2),
                'percent' => round($grossMarginPercent, 2),
            ],
            'allocations' => $allocations,
            'crew_bonus' => $crewBonus,
            'net_profit' => [
                'amount' => round($netProfit, 2),
                'percent' => round($netMarginPercent, 2),
                'target_percent' => $targetNetMargin,
                'delta_percent' => round($marginDelta, 2),
                'is_profitable' => $netProfit > 0,
                'meets_target' => $netMarginPercent >= $targetNetMargin,
                'after_bonus_amount' => $crewBonus ? round($netProfit - $crewBonus['bonus_amount'], 2) : null,
                'after_bonus_percent' => $crewBonus && $customerPrice > 0 ? round(($netProfit - $crewBonus['bonus_amount']) / $customerPrice * 100, 2) : null,
            ],
            'reconciliation' => [
                'stored_subtotal' => round((float) $estimate->subtotal, 2),
                'calculated_price' => round($customerPrice, 2),
                'difference' => round((float) $estimate->subtotal - $customerPrice, 2),
            ],
            'settings_used' => $defaultSettings,
        ];
    }

    /**
     * Calculate breakdown for a single area using the area's OWN model methods.
     *
     * COGS comes from $area->getFullCOGS() (same method the estimate page uses).
     * Customer price comes from $area->getCustomerPrice() (margin applied once here).
     * Detail components are decomposed for visibility but don't override the totals.
     *
     * @return array<string, mixed>
     */
    public function calculateAreaBreakdown(EstimateArea $area): array
    {
        $scope = $area->scope_type ?? 'interior';

        // AUTHORITATIVE numbers from the model (same as estimate page)
        $cogs = $area->getFullCOGS();
        $customerPrice = $area->getCustomerPrice();

        // Get settings for display of component detail
        $settings = CompanySetting::getInstance();
        $marginPercent = $settings->getMarginPercentForScope($scope);

        // Decompose COGS into components for visibility (informational detail)
        $detail = $this->decomposeAreaCosts($area, $settings);

        // Check for custom pricing on surfaces
        $hasCustomPricing = $area->surfaces()
            ->where('is_included', true)
            ->where('is_custom', true)
            ->whereNotNull('sale_price')
            ->exists();

        return [
            'area_id' => $area->id,
            'name' => $area->name,
            'scope_type' => $scope,
            'is_fixed_price' => $area->is_fixed_price,
            'has_custom_pricing' => $hasCustomPricing,
            'cogs' => round($cogs, 2),
            'customer_price' => round($customerPrice, 2),
            'margin_percent' => $marginPercent,
            'detail' => $detail,
        ];
    }

    /**
     * Decompose area COGS into visible components (labor, cleanup, sundries, etc.).
     *
     * These are informational - the authoritative total comes from getFullCOGS().
     * We read stored surface values and calculate cleanup/sundries for display.
     *
     * @return array<string, float>
     */
    protected function decomposeAreaCosts(EstimateArea $area, CompanySetting $settings): array
    {
        $scope = $area->scope_type ?? 'interior';

        if ($area->is_fixed_price) {
            // Even fixed-price areas can have addons (e.g., closets within a closet area)
            $addons = $area->addons()->with('nestedAreaTemplate')->get();
            $addonCost = 0;
            $addonLaborHours = 0;
            $addonLaborCost = 0;
            $addonMaterialCost = 0;
            $addonBreakdown = [];

            foreach ($addons as $addon) {
                $addonCost += (float) $addon->total_price;
                $addonLaborHours += (float) ($addon->labor_hours ?? 0);
                $addonLaborCost += (float) ($addon->labor_cost ?? 0);
                $addonMaterialCost += (float) ($addon->material_cost ?? 0);

                $addonBreakdown[] = [
                    'id' => $addon->id,
                    'name' => $addon->nestedAreaTemplate?->name ?? 'Addon',
                    'quantity' => (int) $addon->quantity,
                    'unit_price' => (float) $addon->unit_price,
                    'total_price' => (float) $addon->total_price,
                    'labor_hours' => round((float) ($addon->labor_hours ?? 0), 2),
                    'labor_cost' => round((float) ($addon->labor_cost ?? 0), 2),
                    'material_cost' => round((float) ($addon->material_cost ?? 0), 2),
                ];
            }

            // Use stored labor breakdown if available, else fall back to zeros
            $laborHours = (float) ($area->estimated_labor_hours ?? 0);
            $laborCost = (float) ($area->estimated_labor_cost ?? 0);
            $materialCost = (float) ($area->estimated_material_cost ?? 0);

            $cleanupHours = 0;
            $cleanupCost = 0;
            $sundriesCost = 0;
            $materialTax = 0;
            $laborTax = 0;

            if ($laborHours > 0) {
                $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
                $cleanupCost = $cleanupHours * $settings->getEffectiveHourlyRateForScope($scope);
                $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);
                $materialTax = $settings->calculateMaterialTax($materialCost);
                $laborTax = $settings->calculateLaborTax($laborCost, $cleanupCost);
            }

            return [
                'labor_hours' => round($laborHours, 2),
                'cleanup_hours' => round($cleanupHours, 2),
                'labor_cost' => round($laborCost, 2),
                'cleanup_cost' => round($cleanupCost, 2),
                'sundries_cost' => round($sundriesCost, 2),
                'material_cost' => round($materialCost, 2),
                'material_tax' => round($materialTax, 2),
                'labor_tax' => round($laborTax, 2),
                'addon_cost' => round($addonCost, 2),
                'addon_labor_hours' => round($addonLaborHours, 2),
                'addon_labor_cost' => round($addonLaborCost, 2),
                'addon_material_cost' => round($addonMaterialCost, 2),
                'addons' => $addonBreakdown,
                'fixed_price' => round((float) $area->fixed_price_amount, 2),
            ];
        }

        // Read stored values from surfaces (these were set when the area was saved)
        $laborHours = (float) $area->surfaces()->where('is_included', true)->sum('labor_hours');
        $laborCost = (float) $area->surfaces()->where('is_included', true)->sum('labor_cost');
        $materialCost = (float) $area->surfaces()->where('is_included', true)->sum('material_cost');

        // Calculate cleanup and sundries (same formulas as getFullCOGS uses)
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
        $cleanupCost = $cleanupHours * $settings->getEffectiveHourlyRateForScope($scope);
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);

        // Addon costs and labor tracking
        $addons = $area->addons()->with('nestedAreaTemplate')->get();
        $addonCost = 0;
        $addonLaborHours = 0;
        $addonLaborCost = 0;
        $addonMaterialCost = 0;
        $addonBreakdown = [];

        foreach ($addons as $addon) {
            $addonCost += (float) $addon->total_price;
            $addonLaborHours += (float) ($addon->labor_hours ?? 0);
            $addonLaborCost += (float) ($addon->labor_cost ?? 0);
            $addonMaterialCost += (float) ($addon->material_cost ?? 0);

            // Build addon detail for display
            $addonBreakdown[] = [
                'id' => $addon->id,
                'name' => $addon->nestedAreaTemplate?->name ?? 'Addon',
                'quantity' => (int) $addon->quantity,
                'unit_price' => (float) $addon->unit_price,
                'total_price' => (float) $addon->total_price,
                'labor_hours' => round((float) ($addon->labor_hours ?? 0), 2),
                'labor_cost' => round((float) ($addon->labor_cost ?? 0), 2),
                'material_cost' => round((float) ($addon->material_cost ?? 0), 2),
            ];
        }

        // Taxes (same as calculateCOGS uses)
        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost + $addonCost);

        return [
            'labor_hours' => round($laborHours, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'labor_cost' => round($laborCost, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($materialCost, 2),
            'material_tax' => round($cogsResult['material_tax'], 2),
            'labor_tax' => round($cogsResult['labor_tax'], 2),
            'addon_cost' => round($addonCost, 2),
            'addon_labor_hours' => round($addonLaborHours, 2),
            'addon_labor_cost' => round($addonLaborCost, 2),
            'addon_material_cost' => round($addonMaterialCost, 2),
            'addons' => $addonBreakdown,
        ];
    }

    /**
     * Calculate overhead allocations from customer price.
     * These are percentage-based deductions from revenue for business analysis.
     *
     * Supports both legacy settings array format and dynamic allocations.
     * If dynamic allocations are configured, uses those. Otherwise falls back
     * to the hardcoded settings for backwards compatibility.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function calculateOverheadAllocations(float $customerPrice, array $settings, float $laborHours = 0, array $context = []): array
    {
        $companySettings = CompanySetting::getInstance();

        // Check if dynamic allocations are configured
        if ($companySettings->allocations()->exists()) {
            return $this->calculateDynamicAllocations($companySettings, $customerPrice, $laborHours, $context);
        }

        // Legacy fallback: use hardcoded settings
        return $this->calculateLegacyAllocations($customerPrice, $settings);
    }

    /**
     * Calculate allocations using the dynamic allocation system.
     *
     * @return array<string, mixed>
     */
    protected function calculateDynamicAllocations(CompanySetting $settings, float $customerPrice, float $laborHours, array $context = []): array
    {
        $hasContext = ! empty($context);

        // Get all active, non-informational allocations
        $allAllocations = $settings->allocations()
            ->active()
            ->where('is_informational', false)
            ->ordered()
            ->get();

        $details = [];
        $total = 0.0;

        foreach ($allAllocations as $allocation) {
            // When context is provided, evaluate gate conditions and resolve tiers
            if ($hasContext) {
                if (! $allocation->evaluateConditions($context)) {
                    continue;
                }

                $resolved = $allocation->resolveAndCalculate($context, $customerPrice, $laborHours);
                $amount = $resolved['amount'];
                $type = $resolved['type'];
                $value = $resolved['value'];
                $tierLabel = $resolved['tier_label'];
            } else {
                // No context: use base value (legacy behavior)
                $amount = $allocation->calculateAmount($customerPrice, $laborHours);
                $type = $allocation->type;
                $value = (float) $allocation->value;
                $tierLabel = null;
            }

            if ($amount <= 0) {
                continue;
            }

            $details[] = [
                'id' => $allocation->id,
                'name' => $allocation->name,
                'slug' => $allocation->slug,
                'type' => $type,
                'type_label' => match ($type) {
                    CompanyAllocation::TYPE_PERCENT_OF_REVENUE => 'Percent of Revenue',
                    CompanyAllocation::TYPE_FIXED_PER_JOB => 'Fixed per Job',
                    CompanyAllocation::TYPE_PER_LABOR_HOUR => 'Per Labor Hour',
                    default => 'Unknown',
                },
                'value' => $value,
                'formatted_value' => match ($type) {
                    CompanyAllocation::TYPE_PERCENT_OF_REVENUE => number_format($value, 2).'%',
                    CompanyAllocation::TYPE_FIXED_PER_JOB => '$'.number_format($value, 2),
                    CompanyAllocation::TYPE_PER_LABOR_HOUR => '$'.number_format($value, 2).'/hr',
                    default => (string) $value,
                },
                'amount' => $amount,
                'is_system' => $allocation->is_system,
                'tier_label' => $tierLabel,
            ];
            $total += $amount;
        }

        // Build allocations array with both indexed list and named keys for backwards compatibility
        $allocations = [
            'items' => $details,
            'total' => round($total, 2),
            'total_percent' => 0,
        ];

        // Calculate total percent for percent-based allocations
        $totalPercent = 0;
        foreach ($details as $detail) {
            if ($detail['type'] === 'percent_of_revenue') {
                $totalPercent += $detail['value'];
            }

            $allocations[$detail['slug']] = [
                'rate' => $detail['value'],
                'amount' => $detail['amount'],
                'informational' => false,
            ];
        }
        $allocations['total_percent'] = round($totalPercent, 2);

        // Add informational allocations separately
        $informational = $settings->allocations()
            ->active()
            ->where('is_informational', true)
            ->get();

        foreach ($informational as $allocation) {
            if ($hasContext && ! $allocation->evaluateConditions($context)) {
                continue;
            }

            if ($hasContext) {
                $resolved = $allocation->resolveAndCalculate($context, $customerPrice, $laborHours);
                $amount = $resolved['amount'];
            } else {
                $amount = $allocation->calculateAmount($customerPrice, $laborHours);
            }

            $allocations[$allocation->slug] = [
                'rate' => (float) $allocation->value,
                'amount' => $amount,
                'informational' => true,
            ];
        }

        return $allocations;
    }

    /**
     * Calculate allocations using the legacy hardcoded settings.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function calculateLegacyAllocations(float $customerPrice, array $settings): array
    {
        $marketingPercent = $settings['marketing_percent'];
        $salesPercent = $settings['sales_percent'];
        $prodMgmtPercent = $settings['production_management_percent'];
        $overheadPercent = $settings['overhead_percent'];

        $marketing = $customerPrice * ($marketingPercent / 100);
        $sales = $customerPrice * ($salesPercent / 100);
        $prodMgmt = $customerPrice * ($prodMgmtPercent / 100);
        $overhead = $customerPrice * ($overheadPercent / 100);

        // Active allocations that reduce net profit
        $total = $marketing + $sales + $prodMgmt;
        $totalPercent = $marketingPercent + $salesPercent + $prodMgmtPercent;

        return [
            'marketing' => [
                'rate' => $marketingPercent,
                'amount' => round($marketing, 2),
            ],
            'sales' => [
                'rate' => $salesPercent,
                'amount' => round($sales, 2),
            ],
            'production_management' => [
                'rate' => $prodMgmtPercent,
                'amount' => round($prodMgmt, 2),
            ],
            'overhead' => [
                'rate' => $overheadPercent,
                'amount' => round($overhead, 2),
                'informational' => true,
            ],
            'total' => round($total, 2),
            'total_percent' => round($totalPercent, 2),
        ];
    }

    /**
     * Calculate what-if scenario with a different margin rate.
     *
     * This is the ONLY place we recalculate from scratch - for hypothetical analysis.
     * It temporarily overrides the margin to show what customer price WOULD be.
     *
     * @return array<string, mixed>
     */
    public function calculateWhatIfScenario(Estimate $estimate, float $marginPercent): array
    {
        $settings = CompanySetting::getInstance();
        $areas = $estimate->areas()->with(['surfaces.productionRate', 'addons'])->get();
        $items = $estimate->items()->orderBy('sort_order')->get();

        $totalCogs = 0;
        $totalCustomerPrice = 0;
        $areaBreakdowns = [];

        foreach ($areas as $area) {
            $cogs = $area->getFullCOGS();
            $marginRate = $marginPercent / 100;
            $whatIfPrice = $marginRate > 0 ? $cogs / $marginRate : $cogs;

            $areaBreakdowns[] = [
                'area_id' => $area->id,
                'name' => $area->name,
                'scope_type' => $area->scope_type ?? 'interior',
                'cogs' => round($cogs, 2),
                'customer_price' => round($whatIfPrice, 2),
                'margin_percent' => $marginPercent,
            ];

            $totalCogs += $cogs;
            $totalCustomerPrice += $whatIfPrice;
        }

        $itemsTotal = (float) $items->sum('total');
        $totalCogs += $itemsTotal;
        $totalCustomerPrice += $itemsTotal;

        $grossProfit = $totalCustomerPrice - $totalCogs;
        $grossMarginPercent = $totalCustomerPrice > 0 ? ($grossProfit / $totalCustomerPrice) * 100 : 0;

        $defaultSettings = $this->getCurrentSettings($estimate);
        // Note: Labor hours not used in what-if scenarios (mainly percent-based allocations)
        $allocations = $this->calculateOverheadAllocations($totalCustomerPrice, $defaultSettings, 0);
        $netProfit = $grossProfit - $allocations['total'];
        $netMarginPercent = $totalCustomerPrice > 0 ? ($netProfit / $totalCustomerPrice) * 100 : 0;
        $targetNetMargin = $defaultSettings['net_profit_margin_percent'];

        return [
            'areas' => $areaBreakdowns,
            'pricing' => [
                'margin_rate' => $marginPercent / 100,
                'margin_percent' => $marginPercent,
                'customer_price' => round($totalCustomerPrice, 2),
            ],
            'gross_profit' => [
                'amount' => round($grossProfit, 2),
                'percent' => round($grossMarginPercent, 2),
            ],
            'allocations' => $allocations,
            'net_profit' => [
                'amount' => round($netProfit, 2),
                'percent' => round($netMarginPercent, 2),
                'target_percent' => $targetNetMargin,
                'delta_percent' => round($netMarginPercent - $targetNetMargin, 2),
                'is_profitable' => $netProfit > 0,
                'meets_target' => $netMarginPercent >= $targetNetMargin,
            ],
        ];
    }

    /**
     * Calculate required margin rate to achieve target net profit margin.
     *
     * @param  array<string, mixed>  $costs  Must include: cogs (total COGS from model)
     * @param  array<string, mixed>  $settings  Must include: overhead allocation percents
     * @return array<string, mixed>
     */
    public function calculateRequiredMarginForTarget(float $targetNetMargin, array $costs, array $settings): array
    {
        $totalCogs = $costs['cogs'] ?? $costs['loaded_cogs'] ?? 0;

        if ($totalCogs <= 0) {
            return [
                'is_achievable' => false,
                'required_margin_percent' => null,
                'required_customer_price' => null,
                'message' => 'Cannot calculate margin requirement with zero costs.',
            ];
        }

        $percentAllocPercent = (
            $settings['marketing_percent'] +
            $settings['sales_percent'] +
            $settings['production_management_percent']
        ) / 100;

        $targetNetMarginDecimal = $targetNetMargin / 100;
        $divisor = 1 - $percentAllocPercent - $targetNetMarginDecimal;

        if ($divisor <= 0) {
            return [
                'is_achievable' => false,
                'required_margin_percent' => null,
                'required_customer_price' => null,
                'message' => 'Target net margin is not achievable with current overhead allocation structure.',
            ];
        }

        $requiredCustomerPrice = $totalCogs / $divisor;

        if ($requiredCustomerPrice <= 0) {
            return [
                'is_achievable' => false,
                'required_margin_percent' => null,
                'required_customer_price' => null,
                'message' => 'Target net margin results in invalid customer price.',
            ];
        }

        // margin% = (COGS / CustomerPrice) × 100
        $requiredMarginPercent = ($totalCogs / $requiredCustomerPrice) * 100;

        // Verify
        $grossProfit = $requiredCustomerPrice - $totalCogs;
        $allocations = $requiredCustomerPrice * $percentAllocPercent;
        $netProfit = $grossProfit - $allocations;
        $verifyNetMargin = $requiredCustomerPrice > 0 ? ($netProfit / $requiredCustomerPrice) * 100 : 0;

        return [
            'is_achievable' => true,
            'required_margin_percent' => round($requiredMarginPercent, 2),
            'required_customer_price' => round($requiredCustomerPrice, 2),
            'verification' => [
                'gross_profit' => round($grossProfit, 2),
                'allocations' => round($allocations, 2),
                'net_profit' => round($netProfit, 2),
                'net_margin_percent' => round($verifyNetMargin, 2),
            ],
        ];
    }

    /**
     * Compare original snapshot settings vs current settings.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function compareSnapshots(array $original, array $current): array
    {
        $comparisons = [];
        $significantChanges = [];

        $keysToCompare = [
            'hourly_wage' => 'Hourly Wage',
            'payroll_burden_multiplier' => 'Payroll Burden',
            'margin_percent' => 'Margin %',
            'net_profit_margin_percent' => 'Target Net Margin',
            'overhead_percent' => 'Overhead %',
            'marketing_percent' => 'Marketing %',
            'sales_percent' => 'Sales %',
            'production_management_percent' => 'Production Mgmt %',
            'cleanup_hours_ratio' => 'Cleanup Ratio',
            'sundries_cost_per_hour' => 'Sundries/Hour',
            'material_tax_rate' => 'Material Tax %',
            'labor_tax_rate' => 'Labor Tax %',
        ];

        foreach ($keysToCompare as $key => $label) {
            $origValue = $original[$key] ?? null;
            $currValue = $current[$key] ?? null;

            $comparison = [
                'key' => $key,
                'label' => $label,
                'original' => $origValue,
                'current' => $currValue,
                'changed' => $origValue !== $currValue,
            ];

            if ($origValue !== null && $currValue !== null && $origValue != $currValue) {
                $comparison['delta'] = $currValue - $origValue;
                $comparison['delta_percent'] = $origValue > 0 ? (($currValue - $origValue) / $origValue) * 100 : null;
                $significantChanges[] = $comparison;
            }

            $comparisons[$key] = $comparison;
        }

        return [
            'comparisons' => $comparisons,
            'significant_changes' => $significantChanges,
            'has_changes' => count($significantChanges) > 0,
            'original_captured_at' => $original['captured_at'] ?? null,
        ];
    }

    /**
     * Get scope-specific settings from CompanySetting.
     *
     * @return array<string, mixed>
     */
    public function getSettingsForScope(CompanySetting $settings, string $scope): array
    {
        return [
            'captured_at' => now()->toIso8601String(),
            'scope' => $scope,
            'hourly_wage' => $settings->getHourlyWageForScope($scope),
            'payroll_burden_multiplier' => (float) ($settings->payroll_burden_multiplier ?? 1.35),
            'margin_percent' => $settings->getMarginPercentForScope($scope),
            'net_profit_margin_percent' => (float) $settings->net_profit_margin_percent,
            'overhead_percent' => (float) $settings->overhead_percent,
            'marketing_percent' => (float) $settings->marketing_percent,
            'sales_percent' => (float) $settings->sales_percent,
            'production_management_percent' => (float) $settings->production_management_percent,
            'cleanup_hours_ratio' => $settings->getCleanupHoursRatioForScope($scope),
            'sundries_cost_per_hour' => $settings->getSundriesCostForScope($scope),
            'material_tax_rate' => $settings->getMaterialTaxRate(),
            'labor_tax_rate' => $settings->getLaborTaxRate(),
            'recycle_fee_per_gallon' => (float) $settings->recycle_fee_per_gallon,
        ];
    }

    /**
     * Get current settings for an estimate (uses estimate's project_type as default scope).
     *
     * @return array<string, mixed>
     */
    public function getCurrentSettings(Estimate $estimate): array
    {
        $settings = CompanySetting::getInstance();
        $scope = $estimate->project_type ?? 'interior';

        return $this->getSettingsForScope($settings, $scope);
    }

    /**
     * Get crew lead bonus info for an estimate's assigned crew.
     *
     * @return array<string, mixed>|null
     */
    public function getCrewBonusInfo(Estimate $estimate, float $customerPrice): ?array
    {
        $job = $estimate->job;
        if (! $job) {
            return null;
        }

        $acceptedCrew = $job->acceptedCrew()->with('crewProfile')->first();
        if (! $acceptedCrew || ! $acceptedCrew->crewProfile) {
            return null;
        }

        $profile = $acceptedCrew->crewProfile;
        $bonusAmount = $profile->calculateBonusAmount($customerPrice);

        if ($bonusAmount === null) {
            return null;
        }

        return [
            'crew_name' => $acceptedCrew->name,
            'bonus_percent' => (float) $profile->bonus_percent,
            'bonus_amount' => $bonusAmount,
        ];
    }

    /**
     * Get cost breakdown grouped by service category.
     *
     * Returns data for each service category that has packages on the estimate.
     * Only meaningful when an estimate has packages with different service categories.
     *
     * @return array<string, array{
     *   category_name: string,
     *   category_color: string,
     *   cogs: float,
     *   price: float,
     *   margin_percent: float,
     *   packages: array
     * }>
     */
    public function getPerServiceBreakdown(Estimate $estimate): array
    {
        $packages = $estimate->packages()
            ->with(['serviceCategory', 'items.itemable'])
            ->get();

        $breakdown = [];

        foreach ($packages as $package) {
            $categoryName = $package->serviceCategory?->name ?? 'Painting';
            $categoryColor = $package->serviceCategory?->color ?? 'blue';
            $categorySlug = $package->serviceCategory?->slug ?? 'painting';

            if (! isset($breakdown[$categorySlug])) {
                $breakdown[$categorySlug] = [
                    'category_name' => $categoryName,
                    'category_color' => $categoryColor,
                    'cogs' => 0,
                    'price' => 0,
                    'margin_percent' => 0,
                    'packages' => [],
                ];
            }

            // Calculate COGS from package items
            $packageCogs = 0;
            $packageItems = $package->items()->included()->with('itemable')->get();

            $areaIds = [];
            foreach ($packageItems as $packageItem) {
                $itemable = $packageItem->itemable;
                if (! $itemable) {
                    continue;
                }

                if ($itemable instanceof EstimateAreaSurface) {
                    $areaId = $itemable->area_id;
                    if ($areaId && ! in_array($areaId, $areaIds)) {
                        $areaIds[] = $areaId;
                    }
                } elseif ($itemable instanceof EstimateArea) {
                    if (! in_array($itemable->id, $areaIds)) {
                        $areaIds[] = $itemable->id;
                        $packageCogs += $itemable->getFullCOGS();
                    }
                } elseif ($itemable instanceof EstimateItem) {
                    $packageCogs += $itemable->cost;
                }
            }

            // Add COGS from areas referenced via surfaces
            foreach ($areaIds as $areaId) {
                $area = EstimateArea::find($areaId);
                if ($area && ! ($area instanceof EstimateArea && in_array($areaId, array_column($breakdown[$categorySlug]['packages'], 'area_id', null) ?? []))) {
                    // Only add COGS for areas not already counted via direct EstimateArea items
                    if (! collect($packageItems)->contains(fn ($pi) => $pi->itemable instanceof EstimateArea && $pi->itemable->id === $areaId)) {
                        $packageCogs += $area->getFullCOGS();
                    }
                }
            }

            $packagePrice = (float) $package->subtotal;

            $breakdown[$categorySlug]['cogs'] += $packageCogs;
            $breakdown[$categorySlug]['price'] += $packagePrice;
            $breakdown[$categorySlug]['packages'][] = [
                'name' => $package->name,
                'tier' => $package->tier_label,
                'subtotal' => $packagePrice,
            ];
        }

        // Calculate margins
        foreach ($breakdown as &$service) {
            if ($service['price'] > 0) {
                $service['margin_percent'] = round(
                    (($service['price'] - $service['cogs']) / $service['price']) * 100,
                    1
                );
            }
        }

        return $breakdown;
    }

    /**
     * Initialize totals array.
     *
     * @return array<string, float>
     */
    protected function initializeTotals(): array
    {
        return [
            'labor_hours' => 0,
            'cleanup_hours' => 0,
            'labor_cost' => 0,
            'cleanup_cost' => 0,
            'sundries_cost' => 0,
            'material_cost' => 0,
            'material_tax' => 0,
            'labor_tax' => 0,
            'addon_cost' => 0,
            'addon_labor_hours' => 0,
            'addon_labor_cost' => 0,
            'addon_material_cost' => 0,
            'items_total' => 0,
            'items_vendor_cost' => 0,
            'cogs' => 0,
            'customer_price' => 0,
        ];
    }
}
