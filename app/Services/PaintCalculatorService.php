<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;

class PaintCalculatorService
{
    protected CompanySetting $settings;

    public function __construct(?CompanySetting $settings = null)
    {
        $this->settings = $settings ?? CompanySetting::getInstance();
    }

    /**
     * Calculate gallons needed for a given square footage.
     *
     * @param  string  $applicationMethod  'spray' or 'brush_roll'
     * @return array{gallons: float, gallons_rounded: int, coverage_used: float}
     */
    public function calculateGallonsNeeded(
        float $sqft,
        int $baseCoverageSqft = 400,
        int $coats = 2,
        string $applicationMethod = 'brush_roll'
    ): array {
        return $this->settings->calculateGallonsNeeded(
            $sqft,
            $baseCoverageSqft,
            $coats,
            $applicationMethod
        );
    }

    /**
     * Calculate full paint cost for a surface.
     *
     * @return array{gallons: float, gallons_rounded: int, paint_cost: float, recycle_fee: float, total_cost: float}|null
     */
    public function calculatePaintCost(
        float $sqft,
        string $brand,
        string $type,
        int $coats = 2,
        string $applicationMethod = 'brush_roll'
    ): ?array {
        return $this->settings->calculatePaintCost(
            $sqft,
            $brand,
            $type,
            $coats,
            $applicationMethod
        );
    }

    /**
     * Calculate paint needs for an EstimateAreaSurface.
     *
     * @return array{gallons: float, gallons_rounded: int, paint_cost: float, recycle_fee: float, total_cost: float}|null
     */
    public function calculateForSurface(
        EstimateAreaSurface $surface,
        ?string $brand = null,
        ?string $type = null
    ): ?array {
        $brand = $brand ?? $surface->paint_brand ?? 'sherwin_williams';
        $type = $type ?? $surface->paint_type ?? 'superpaint';
        $applicationMethod = $surface->application_method ?? 'brush_roll';
        $coats = $surface->coats ?? 2;

        return $this->calculatePaintCost(
            (float) $surface->quantity,
            $brand,
            $type,
            $coats,
            $applicationMethod
        );
    }

    /**
     * Calculate paint needs for an entire EstimateArea (all surfaces combined).
     *
     * @return array{surfaces: array, totals: array{total_sqft: float, total_gallons: float, total_gallons_rounded: int, total_paint_cost: float, total_recycle_fee: float, grand_total: float}}
     */
    public function calculateForArea(EstimateArea $area, ?string $brand = null, ?string $type = null): array
    {
        $surfaces = [];
        $totalSqft = 0;
        $totalGallons = 0;
        $totalPaintCost = 0;
        $totalRecycleFee = 0;

        foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
            $calculation = $this->calculateForSurface($surface, $brand, $type);

            if ($calculation) {
                $surfaces[] = [
                    'surface_id' => $surface->id,
                    'surface_name' => $surface->name,
                    'sqft' => $surface->quantity,
                    'coats' => $surface->coats,
                    'application_method' => $surface->application_method ?? 'brush_roll',
                    ...$calculation,
                ];

                $totalSqft += $surface->quantity;
                $totalGallons += $calculation['gallons'];
                $totalPaintCost += $calculation['paint_cost'];
                $totalRecycleFee += $calculation['recycle_fee'];
            }
        }

        return [
            'surfaces' => $surfaces,
            'totals' => [
                'total_sqft' => round($totalSqft, 2),
                'total_gallons' => round($totalGallons, 2),
                'total_gallons_rounded' => (int) ceil($totalGallons),
                'total_paint_cost' => round($totalPaintCost, 2),
                'total_recycle_fee' => round($totalRecycleFee, 2),
                'grand_total' => round($totalPaintCost + $totalRecycleFee, 2),
            ],
        ];
    }

    /**
     * Calculate paint needs for an entire Estimate (all areas).
     *
     * @return array{areas: array, grand_totals: array{total_sqft: float, total_gallons: float, total_gallons_rounded: int, total_paint_cost: float, total_recycle_fee: float, grand_total: float}}
     */
    public function calculateForEstimate(Estimate $estimate, ?string $brand = null, ?string $type = null): array
    {
        $areas = [];
        $grandTotalSqft = 0;
        $grandTotalGallons = 0;
        $grandTotalPaintCost = 0;
        $grandTotalRecycleFee = 0;

        foreach ($estimate->areas as $area) {
            $areaCalculation = $this->calculateForArea($area, $brand, $type);

            $areas[] = [
                'area_id' => $area->id,
                'area_name' => $area->name,
                ...$areaCalculation,
            ];

            $grandTotalSqft += $areaCalculation['totals']['total_sqft'];
            $grandTotalGallons += $areaCalculation['totals']['total_gallons'];
            $grandTotalPaintCost += $areaCalculation['totals']['total_paint_cost'];
            $grandTotalRecycleFee += $areaCalculation['totals']['total_recycle_fee'];
        }

        return [
            'areas' => $areas,
            'grand_totals' => [
                'total_sqft' => round($grandTotalSqft, 2),
                'total_gallons' => round($grandTotalGallons, 2),
                'total_gallons_rounded' => (int) ceil($grandTotalGallons),
                'total_paint_cost' => round($grandTotalPaintCost, 2),
                'total_recycle_fee' => round($grandTotalRecycleFee, 2),
                'grand_total' => round($grandTotalPaintCost + $grandTotalRecycleFee, 2),
            ],
        ];
    }

    /**
     * Get available paint brands.
     *
     * @return array<string>
     */
    public function getAvailableBrands(): array
    {
        return $this->settings->getAvailablePaintBrands();
    }

    /**
     * Get paint types for a brand.
     *
     * @return array<string>
     */
    public function getPaintTypesForBrand(string $brand): array
    {
        return $this->settings->getPaintTypesForBrand($brand);
    }

    /**
     * Get paint details (price and coverage).
     *
     * @return array{price: float, coverage_sqft: int}|null
     */
    public function getPaintDetails(string $brand, string $type): ?array
    {
        return $this->settings->getPaintDetails($brand, $type);
    }

    /**
     * Get all paints as a flat list for dropdowns.
     *
     * @return array<array{brand: string, type: string, price: float, coverage_sqft: int, label: string}>
     */
    public function getAllPaintsFlat(): array
    {
        $paints = [];
        $catalog = $this->settings->paint_catalog ?? CompanySetting::getDefaultPaintCatalog();

        foreach ($catalog as $brand => $types) {
            $brandLabel = ucwords(str_replace('_', ' ', $brand));

            foreach ($types as $type => $details) {
                $typeLabel = ucwords(str_replace('_', ' ', $type));
                $price = is_array($details) ? $details['price'] : $details;
                $coverage = is_array($details) ? $details['coverage_sqft'] : 400;

                $paints[] = [
                    'brand' => $brand,
                    'type' => $type,
                    'price' => (float) $price,
                    'coverage_sqft' => (int) $coverage,
                    'label' => "{$brandLabel} - {$typeLabel} (\${$price}/gal)",
                ];
            }
        }

        return $paints;
    }

    /**
     * Get coverage multipliers for display.
     *
     * @return array{spray: float, brush_roll: float}
     */
    public function getCoverageMultipliers(): array
    {
        return [
            'spray' => (float) ($this->settings->spray_paint_coverage_multiplier ?? 2.0),
            'brush_roll' => (float) ($this->settings->brush_roll_coverage_multiplier ?? 1.3),
        ];
    }

    /**
     * Get recycle fee per gallon.
     */
    public function getRecycleFeePerGallon(): float
    {
        return (float) ($this->settings->recycle_fee_per_gallon ?? 0.54);
    }
}
