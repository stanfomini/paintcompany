<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateView;
use App\Models\ProductionRate;

class EstimateCalculationService
{
    /**
     * Calculate the total of all included surfaces for an area.
     */
    public function calculateAreaTotal(EstimateArea $area): float
    {
        $total = (float) $area->surfaces()
            ->where('is_included', true)
            ->sum('total');

        return $total;
    }

    /**
     * Calculate the total of all areas' subtotals for an estimate.
     */
    public function calculateEstimateAreasTotalFromAreas(Estimate $estimate): float
    {
        $total = 0.0;

        foreach ($estimate->areas as $area) {
            $total += $this->calculateAreaTotal($area);
        }

        $estimate->areas_subtotal = $total;

        return $total;
    }

    /**
     * Calculate the total of all estimate items.
     */
    public function calculateEstimateItemsTotal(Estimate $estimate): float
    {
        $total = (float) $estimate->items()->sum('total');

        $estimate->items_subtotal = $total;

        return $total;
    }

    /**
     * Calculate the total of all selected options.
     */
    public function calculateEstimateOptionsTotal(Estimate $estimate): float
    {
        $total = (float) $estimate->options()
            ->where('is_selected', true)
            ->sum('calculated_price');

        $estimate->options_subtotal = $total;

        return $total;
    }

    /**
     * Recalculate all totals for an estimate and save.
     */
    public function recalculateEstimateTotals(Estimate $estimate): void
    {
        // Recalculate subtotals
        $this->calculateEstimateAreasTotalFromAreas($estimate);
        $this->calculateEstimateItemsTotal($estimate);
        $this->calculateEstimateOptionsTotal($estimate);

        // Calculate main subtotal
        $estimate->subtotal = $estimate->areas_subtotal
            + $estimate->items_subtotal
            + $estimate->options_subtotal;

        // Calculate tax amount
        $taxRate = (float) ($estimate->tax_rate ?? 0);
        $estimate->tax_amount = $estimate->subtotal * ($taxRate / 100);

        // Calculate total
        $discountAmount = (float) ($estimate->discount_amount ?? 0);
        $estimate->total = $estimate->subtotal + $estimate->tax_amount - $discountAmount;

        $estimate->save();
    }

    /**
     * Create a new area from dimensions with auto-calculated sqft values.
     */
    public function createAreaFromDimensions(
        Estimate $estimate,
        string $name,
        float $length,
        float $width,
        float $height,
        ?string $notes = null
    ): EstimateArea {
        // Get the next sort order
        $maxSortOrder = $estimate->areas()->max('sort_order') ?? 0;

        return EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => $name,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'notes' => $notes,
            'sort_order' => $maxSortOrder + 1,
        ]);
    }

    /**
     * Add a surface to an area using a production rate.
     */
    public function addSurfaceToArea(
        EstimateArea $area,
        ProductionRate $rate,
        float $quantity,
        ?int $coats = null
    ): EstimateAreaSurface {
        $surface = EstimateAreaSurface::createFromRate($area, $rate, $quantity, $coats);

        // Recalculate area total
        $this->calculateAreaTotal($area);

        return $surface;
    }

    /**
     * Generate a public proposal URL for the estimate.
     */
    public function generatePublicProposalUrl(Estimate $estimate, int $expiryDays = 30): string
    {
        // Generate token if not exists or expired
        if (! $estimate->public_token || ! $estimate->isPublicTokenValid()) {
            $estimate->generatePublicToken($expiryDays);
        }

        return $estimate->getPublicUrl();
    }

    /**
     * Record a view of the estimate.
     */
    public function recordEstimateView(Estimate $estimate): EstimateView
    {
        return EstimateView::record($estimate, auth()->user());
    }

    /**
     * Log an activity for the estimate.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function logActivity(
        Estimate $estimate,
        string $type,
        ?string $description = null,
        ?array $metadata = null
    ): EstimateActivity {
        return EstimateActivity::log($estimate, $type, $description, $metadata);
    }
}
