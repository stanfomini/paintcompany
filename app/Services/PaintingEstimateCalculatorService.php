<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EstimateArea;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;

/**
 * Core pricing calculator for painting estimates.
 *
 * Implements the full PBP calculation flow:
 * 1. Area dimensions → Calculate sqft/lnft
 * 2. Substrate selection → Apply prep/paint time multipliers
 * 3. Production rates → Calculate labor hours
 * 4. Paint selection → Calculate material costs
 * 5. Apply margins → Profit → Overhead → Markup
 * 6. Sum totals → Final price
 */
class PaintingEstimateCalculatorService
{
    protected CompanySetting $settings;

    public function __construct()
    {
        $this->settings = CompanySetting::getInstance();
    }

    /**
     * Calculate pricing breakdown for an area.
     *
     * @return array{
     *     area_id: int,
     *     name: string,
     *     scope_type: string,
     *     sqft: array{wall: float, ceiling: float, floor: float},
     *     labor: array{hours: float, base_cost: float, cleanup_hours: float, sundries_cost: float},
     *     materials: array{paint_cost: float, recycle_fee: float, total: float},
     *     nested_areas: array{count: int, total: float},
     *     subtotal: float,
     *     with_margins: float,
     *     is_fixed_price: bool
     * }
     */
    public function calculateArea(EstimateArea $area): array
    {
        // Fixed price areas (closets, etc.)
        if ($area->is_fixed_price) {
            return $this->calculateFixedPriceArea($area);
        }

        // Standard calculated areas
        $scopeType = $area->scope_type ?? 'interior';
        $sqft = $this->getAreaSqft($area);
        $labor = $this->calculateLabor($area, $sqft);
        $materials = $this->calculateMaterials($area);
        $nestedAreas = $this->calculateNestedAreas($area);

        // Calculate cleanup hours and sundries cost based on scope
        $cleanupHours = $this->settings->calculateCleanupHours($labor['hours'], $scopeType);
        $sundriesCost = $this->settings->calculateSundriesCost($labor['hours'], $scopeType);

        // Add cleanup labor cost and sundries to the total
        $cleanupLaborCost = $this->settings->calculateLaborCostForScope($cleanupHours, $scopeType);
        $labor['cleanup_hours'] = $cleanupHours;
        $labor['cleanup_cost'] = $cleanupLaborCost;
        $labor['sundries_cost'] = $sundriesCost;
        $labor['total_cost'] = $labor['base_cost'] + $cleanupLaborCost + $sundriesCost;

        $subtotal = $labor['total_cost'] + $materials['total'];

        // Use scope-specific margins
        $withMargins = $this->settings->calculateWithMarginsForScope($subtotal, $scopeType);

        // Add nested areas (already have margins applied)
        $total = $withMargins + $nestedAreas['total'];

        return [
            'area_id' => $area->id,
            'name' => $area->name,
            'scope_type' => $scopeType,
            'sqft' => $sqft,
            'labor' => $labor,
            'materials' => $materials,
            'nested_areas' => $nestedAreas,
            'subtotal' => round($subtotal, 2),
            'with_margins' => round($withMargins, 2),
            'total' => round($total, 2),
            'is_fixed_price' => false,
        ];
    }

    /**
     * Calculate a fixed-price area (closet, half bath, etc.).
     */
    protected function calculateFixedPriceArea(EstimateArea $area): array
    {
        $fixedPrice = (float) $area->fixed_price_amount;

        return [
            'area_id' => $area->id,
            'name' => $area->name,
            'sqft' => ['wall' => 0, 'ceiling' => 0, 'floor' => 0],
            'labor' => ['hours' => 0, 'base_cost' => 0],
            'materials' => ['paint_cost' => 0, 'recycle_fee' => 0, 'total' => 0],
            'nested_areas' => ['count' => 0, 'total' => 0],
            'subtotal' => $fixedPrice,
            'with_margins' => $fixedPrice, // Fixed prices already include margins
            'total' => $fixedPrice,
            'is_fixed_price' => true,
        ];
    }

    /**
     * Get sqft values for an area.
     *
     * @return array{wall: float, ceiling: float, floor: float}
     */
    protected function getAreaSqft(EstimateArea $area): array
    {
        return [
            'wall' => (float) $area->wall_sqft,
            'ceiling' => (float) $area->ceiling_sqft,
            'floor' => (float) $area->floor_sqft,
        ];
    }

    /**
     * Calculate labor for an area based on surfaces and substrate.
     *
     * @return array{hours: float, base_cost: float, breakdown: array}
     */
    public function calculateLabor(EstimateArea $area, ?array $sqft = null): array
    {
        $sqft = $sqft ?? $this->getAreaSqft($area);
        $substrate = $area->substrate;
        $scopeType = $area->scope_type ?? 'interior';
        $breakdown = [];
        $totalHours = 0;

        // Get substrate multipliers (default to 1.0 if no substrate)
        $prepMultiplier = $substrate ? (float) $substrate->prep_time_multiplier : 1.0;
        $paintMultiplier = $substrate ? (float) $substrate->paint_time_multiplier : 1.0;

        // Calculate labor for each surface
        foreach ($area->surfaces as $surface) {
            if (! $surface->is_included) {
                continue;
            }

            $productionRate = $surface->productionRate;
            if (! $productionRate) {
                continue;
            }

            $surfaceSqft = (float) $surface->quantity;
            $rate = (float) $productionRate->production_rate;
            $coatMultiplier = $this->getCoatMultiplier($productionRate, $surface->coats ?? 2);

            // Base hours = sqft / production_rate
            $baseHours = $surfaceSqft / $rate;

            // Apply multipliers: base × prep × paint × coats
            $adjustedHours = $baseHours * $prepMultiplier * $paintMultiplier * $coatMultiplier;

            $breakdown[] = [
                'surface_type' => $surface->surface_type,
                'sqft' => $surfaceSqft,
                'production_rate' => $rate,
                'base_hours' => round($baseHours, 2),
                'prep_multiplier' => $prepMultiplier,
                'paint_multiplier' => $paintMultiplier,
                'coat_multiplier' => $coatMultiplier,
                'adjusted_hours' => round($adjustedHours, 2),
            ];

            $totalHours += $adjustedHours;
        }

        // Use scope-specific hourly rate
        $baseCost = $this->settings->calculateLaborCostForScope($totalHours, $scopeType);

        return [
            'hours' => round($totalHours, 2),
            'base_cost' => $baseCost,
            'scope_type' => $scopeType,
            'hourly_rate' => $this->settings->getEffectiveHourlyRateForScope($scopeType),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Calculate materials (paint) for an area.
     *
     * @return array{paint_cost: float, recycle_fee: float, total: float, breakdown: array}
     */
    public function calculateMaterials(EstimateArea $area): array
    {
        $breakdown = [];
        $totalPaintCost = 0;
        $totalRecycleFee = 0;

        foreach ($area->paintSelections as $selection) {
            // Recalculate costs using current paint product pricing
            $selection->calculateCost((float) ($this->settings->recycle_fee_per_gallon ?? 0.54));

            $breakdown[] = [
                'surface_type' => $selection->surface_type,
                'paint_name' => $selection->paint_name,
                'sqft' => (float) $selection->sqft_covered,
                'coats' => $selection->coats,
                'gallons' => (float) $selection->gallons_needed,
                'paint_cost' => (float) $selection->paint_cost,
                'recycle_fee' => (float) $selection->recycle_fee,
            ];

            $totalPaintCost += (float) $selection->paint_cost;
            $totalRecycleFee += (float) $selection->recycle_fee;
        }

        return [
            'paint_cost' => round($totalPaintCost, 2),
            'recycle_fee' => round($totalRecycleFee, 2),
            'total' => round($totalPaintCost + $totalRecycleFee, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Calculate nested areas (closets, etc.) attached to this area.
     *
     * @return array{count: int, total: float, items: array}
     */
    public function calculateNestedAreas(EstimateArea $area): array
    {
        $items = [];
        $total = 0;

        foreach ($area->childAreas as $child) {
            $childTotal = $child->is_fixed_price
                ? (float) $child->fixed_price_amount
                : $this->calculateArea($child)['total'];

            $items[] = [
                'id' => $child->id,
                'name' => $child->name,
                'is_fixed_price' => $child->is_fixed_price,
                'total' => $childTotal,
            ];

            $total += $childTotal;
        }

        return [
            'count' => count($items),
            'total' => round($total, 2),
            'items' => $items,
        ];
    }

    /**
     * Calculate complete estimate totals across all areas.
     *
     * @return array{
     *     areas: array,
     *     summary: array{total_sqft: float, total_hours: float, labor_cost: float, material_cost: float, subtotal: float, total: float},
     *     pricing: array{profit_percent: float, overhead_percent: float, markup_percent: float}
     * }
     */
    public function calculateEstimate(iterable $areas): array
    {
        $calculatedAreas = [];
        $totalSqft = 0;
        $totalHours = 0;
        $totalLaborCost = 0;
        $totalMaterialCost = 0;
        $grandTotal = 0;

        foreach ($areas as $area) {
            $areaCalc = $this->calculateArea($area);
            $calculatedAreas[] = $areaCalc;

            $totalSqft += $areaCalc['sqft']['wall'] + $areaCalc['sqft']['ceiling'];
            $totalHours += $areaCalc['labor']['hours'];
            $totalLaborCost += $areaCalc['labor']['base_cost'];
            $totalMaterialCost += $areaCalc['materials']['total'];
            $grandTotal += $areaCalc['total'];
        }

        return [
            'areas' => $calculatedAreas,
            'summary' => [
                'total_sqft' => round($totalSqft, 2),
                'total_hours' => round($totalHours, 2),
                'labor_cost' => round($totalLaborCost, 2),
                'material_cost' => round($totalMaterialCost, 2),
                'subtotal' => round($totalLaborCost + $totalMaterialCost, 2),
                'total' => round($grandTotal, 2),
            ],
            'pricing' => [
                'profit_percent' => (float) ($this->settings->net_profit_margin_percent ?? 30),
                'overhead_percent' => (float) ($this->settings->overhead_percent ?? 12),
                'markup_percent' => (float) ($this->settings->markup_percent ?? 45),
                'effective_hourly_rate' => $this->settings->effective_hourly_rate,
            ],
        ];
    }

    /**
     * Get coat multiplier from production rate.
     */
    protected function getCoatMultiplier(ProductionRate $rate, int $coats): float
    {
        $multipliers = $rate->coat_multipliers ?? ['1' => 1.0, '2' => 1.8, '3' => 2.5];

        return (float) ($multipliers[(string) $coats] ?? $coats * 0.9);
    }

    /**
     * Create an area from a nested area template.
     * Returns data array ready for EstimateArea::create().
     *
     * @return array<string, mixed>
     */
    public function createAreaFromTemplate(
        NestedAreaTemplate $template,
        int $estimateId,
        ?int $parentAreaId = null
    ): array {
        return [
            'estimate_id' => $estimateId,
            'parent_area_id' => $parentAreaId,
            'nested_area_template_id' => $template->id,
            'name' => $template->name,
            'scope_type' => $template->scope_type,
            'length' => $template->default_length,
            'width' => $template->default_width,
            'height' => $template->default_height,
            'is_fixed_price' => true,
            'fixed_price_amount' => $template->fixed_price,
        ];
    }

    /**
     * Quick estimate calculator - for on-the-fly pricing without full area setup.
     *
     * @return array{hours: float, labor_cost: float, price_with_margins: float}
     */
    public function quickEstimate(
        float $sqft,
        int $productionRate,
        int $coats = 2,
        ?int $substrateId = null
    ): array {
        $substrate = $substrateId ? Substrate::find($substrateId) : null;
        $prepMultiplier = $substrate ? (float) $substrate->prep_time_multiplier : 1.0;
        $paintMultiplier = $substrate ? (float) $substrate->paint_time_multiplier : 1.0;

        // Standard coat multipliers
        $coatMultiplier = match ($coats) {
            1 => 1.0,
            2 => 1.8,
            3 => 2.5,
            default => $coats * 0.9,
        };

        $baseHours = $sqft / $productionRate;
        $adjustedHours = $baseHours * $prepMultiplier * $paintMultiplier * $coatMultiplier;
        $laborCost = $this->settings->calculateLaborCost($adjustedHours);
        $priceWithMargins = $this->settings->calculateWithMargins($laborCost);

        return [
            'sqft' => $sqft,
            'production_rate' => $productionRate,
            'coats' => $coats,
            'base_hours' => round($baseHours, 2),
            'multipliers' => [
                'prep' => $prepMultiplier,
                'paint' => $paintMultiplier,
                'coat' => $coatMultiplier,
            ],
            'hours' => round($adjustedHours, 2),
            'labor_cost' => round($laborCost, 2),
            'price_with_margins' => round($priceWithMargins, 2),
        ];
    }

    /**
     * Calculate paint cost for a surface.
     *
     * @return array{gallons: float, gallons_rounded: int, paint_cost: float, recycle_fee: float, total: float}|null
     */
    public function calculatePaintForSurface(
        float $sqft,
        int $paintProductId,
        int $coats = 2,
        string $applicationMethod = 'brush_roll'
    ): ?array {
        $product = PaintProduct::find($paintProductId);
        if (! $product) {
            return null;
        }

        return $product->calculateCost(
            $sqft,
            $coats,
            $applicationMethod,
            (float) ($this->settings->recycle_fee_per_gallon ?? 0.54)
        );
    }

    /**
     * Get pricing breakdown explanation for transparency.
     *
     * @return array{formula: string, steps: array, settings: array, scope_settings: array}
     */
    public function getPricingExplanation(): array
    {
        $profitPercent = (float) ($this->settings->net_profit_margin_percent ?? 30);
        $overheadPercent = (float) ($this->settings->overhead_percent ?? 12);
        $markupPercent = (float) ($this->settings->markup_percent ?? 45);

        return [
            'formula' => '(sqft ÷ production_rate) × substrate_multipliers × coat_multiplier × hourly_rate + cleanup + sundries + materials → Apply margins',
            'steps' => [
                '1. Calculate production hours: sqft ÷ production_rate × substrate_prep_multiplier × substrate_paint_multiplier × coat_multiplier',
                '2. Calculate labor cost: hours × hourly_rate (scope-specific)',
                '3. Calculate cleanup hours: production_hours ÷ cleanup_ratio',
                '4. Calculate cleanup cost: cleanup_hours × hourly_rate',
                '5. Calculate sundries: production_hours × sundries_rate (scope-specific)',
                '6. Calculate materials: gallons_needed × price_per_gallon + recycle_fee',
                '7. Get subtotal: labor_cost + cleanup_cost + sundries + material_cost',
                "8. Apply profit: subtotal × (1 + {$profitPercent}%)",
                "9. Apply overhead: with_profit × (1 + {$overheadPercent}%)",
                '10. Apply markup: with_overhead × (1 + markup%) (scope-specific)',
            ],
            'settings' => [
                // Backward compatible keys
                'base_hourly_wage' => (float) ($this->settings->base_hourly_wage ?? 25.00),
                'payroll_burden_multiplier' => (float) ($this->settings->payroll_burden_multiplier ?? 1.35),
                'effective_hourly_rate' => $this->settings->effective_hourly_rate,
                'profit_percent' => $profitPercent,
                'overhead_percent' => $overheadPercent,
                'markup_percent' => $markupPercent,
                'recycle_fee_per_gallon' => (float) ($this->settings->recycle_fee_per_gallon ?? 0.54),
                'spray_coverage_multiplier' => (float) ($this->settings->spray_paint_coverage_multiplier ?? 2.0),
                'brush_roll_coverage_multiplier' => (float) ($this->settings->brush_roll_coverage_multiplier ?? 1.3),
            ],
            'scope_settings' => [
                'interior' => $this->settings->getPricingConfigForScope('interior'),
                'exterior' => $this->settings->getPricingConfigForScope('exterior'),
            ],
        ];
    }
}
