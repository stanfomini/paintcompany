<?php

namespace App\Services;

use App\Models\Estimate;

class EstimateTierPricingService
{
    /**
     * Recalculate tier-specific material costs for all surfaces in an estimate.
     * Called when tier paint configs change or areas/surfaces are modified.
     *
     * @return int Number of surfaces updated
     */
    public function recalculateTierCosts(Estimate $estimate): int
    {
        if (! $estimate->hasPaintTiers()) {
            return 0;
        }

        $updated = 0;

        foreach ($estimate->areas()->with('surfaces')->get() as $area) {
            $scope = $area->scope_type ?? 'interior';

            foreach ($area->surfaces as $surface) {
                $changed = false;

                foreach (['good', 'better', 'best'] as $tier) {
                    $tierConfig = $estimate->getPaintTierConfig($tier);
                    if (! $tierConfig) {
                        continue;
                    }

                    // Resolve paint product for this tier + surface category
                    $category = $surface->getSurfaceCategory();
                    $paintProduct = $estimate->resolveTierPaint($tier, $category, $scope);

                    if (! $paintProduct) {
                        continue;
                    }

                    // Calculate material cost for this tier
                    $costCalc = $paintProduct->calculateCost(
                        (float) $surface->quantity,
                        $surface->coats ?? 2,
                        $surface->application_method ?? 'brush_roll'
                    );

                    $materialCost = $costCalc['total_cost'];
                    $productIdColumn = "paint_product_id_{$tier}";
                    $costColumn = "material_cost_{$tier}";

                    if ((float) $surface->{$costColumn} !== $materialCost || $surface->{$productIdColumn} !== $paintProduct->id) {
                        $surface->{$productIdColumn} = $paintProduct->id;
                        $surface->{$costColumn} = $materialCost;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $surface->saveQuietly();
                    $updated++;
                }
            }
        }

        return $updated;
    }

    /**
     * Calculate tier-specific totals for the estimate.
     * Returns customer-facing price for each tier.
     *
     * @return array{good: float, better: float, best: float}
     */
    public function calculateTierTotals(Estimate $estimate): array
    {
        $totals = ['good' => 0.0, 'better' => 0.0, 'best' => 0.0];

        if (! $estimate->hasPaintTiers()) {
            $baseTotal = (float) $estimate->areas_subtotal;

            return ['good' => $baseTotal, 'better' => $baseTotal, 'best' => $baseTotal];
        }

        foreach (['good', 'better', 'best'] as $tier) {
            $totals[$tier] = $estimate->calculateAreasSubtotalForTier($tier);
        }

        return $totals;
    }

    /**
     * Clear all tier-specific material costs from an estimate's surfaces.
     * Called when tier pricing is disabled.
     *
     * @return int Number of surfaces cleared
     */
    public function clearTierCosts(Estimate $estimate): int
    {
        $cleared = 0;

        foreach ($estimate->areas()->with('surfaces')->get() as $area) {
            foreach ($area->surfaces as $surface) {
                $hasData = $surface->material_cost_good !== null
                    || $surface->material_cost_better !== null
                    || $surface->material_cost_best !== null;

                if ($hasData) {
                    $surface->material_cost_good = null;
                    $surface->material_cost_better = null;
                    $surface->material_cost_best = null;
                    $surface->paint_product_id_good = null;
                    $surface->paint_product_id_better = null;
                    $surface->paint_product_id_best = null;
                    $surface->saveQuietly();
                    $cleared++;
                }
            }
        }

        return $cleared;
    }
}
