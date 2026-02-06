<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobCostCard;
use Illuminate\Support\Collection;

class JobCostCardService
{
    /**
     * Get all job cost cards for a year, combining manual entries
     * and auto-generated cards from completed jobs.
     *
     * @return Collection<int, JobCostCard>
     */
    public function getCardsForYear(int $year): Collection
    {
        $settings = CompanySetting::getInstance();

        // Get manual entries
        $manualCards = JobCostCard::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->where('is_manual', true)
            ->get();

        // Get auto cards from completed jobs
        $autoCards = $this->generateFromCompletedJobs($year);

        return $manualCards->merge($autoCards)->sortBy('client_name')->values();
    }

    /**
     * Generate cost cards from completed jobs for a given year.
     * Uses the estimate's subtotal for price and area COGS for costs.
     *
     * Returns non-persisted JobCostCard instances.
     *
     * @return Collection<int, JobCostCard>
     */
    public function generateFromCompletedJobs(int $year): Collection
    {
        $settings = CompanySetting::getInstance();

        // Find completed jobs for this year that already have persisted auto-cards
        $existingJobIds = JobCostCard::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->where('is_manual', false)
            ->whereNotNull('job_id')
            ->pluck('job_id')
            ->toArray();

        // Get persisted auto-cards
        $persistedCards = JobCostCard::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->where('is_manual', false)
            ->get();

        // Find completed jobs for the year that don't have a card yet
        $completedJobs = Job::query()
            ->where('stage', 'completed')
            ->whereYear('actual_end_date', $year)
            ->whereNotIn('id', $existingJobIds)
            ->with(['deal.customer', 'estimate.areas.surfaces'])
            ->get();

        $generatedCards = $completedJobs->map(function (Job $job) use ($settings, $year) {
            $estimate = $job->estimate;
            $customer = $job->deal?->customer;

            $clientName = $customer
                ? $customer->full_name
                : ($job->title ?? 'Unknown Client');

            $price = $estimate ? (float) $estimate->subtotal : 0.0;

            // Break down costs from estimate (using eager-loaded relationships)
            $materialCost = 0.0;
            $laborCost = 0.0;

            if ($estimate) {
                foreach ($estimate->areas as $area) {
                    $includedSurfaces = $area->surfaces->where('is_included', true);
                    $materialCost += (float) $includedSurfaces->sum('material_cost');
                    $laborCost += (float) $includedSurfaces->sum('labor_cost');
                }
            }

            // Estimate breakdowns based on company setting percentages
            $productionMgmt = round($price * ((float) $settings->production_management_percent / 100), 2);
            $salesCommission = round($price * ((float) $settings->sales_percent / 100), 2);
            $wcInsurance = round($laborCost * ((float) $settings->payroll_burden_multiplier - 1), 2);

            return new JobCostCard([
                'job_id' => $job->id,
                'company_setting_id' => $settings->id,
                'year' => $year,
                'client_name' => $clientName,
                'price' => $price,
                'production_mgmt_labor' => $productionMgmt,
                'subcontractor_cost' => 0,
                'wc_insurance_burden' => $wcInsurance,
                'material_cost' => $materialCost,
                'sales_commission' => $salesCommission,
                'is_manual' => false,
            ]);
        });

        return $persistedCards->merge($generatedCards);
    }

    /**
     * Get running totals across all cards for a year.
     *
     * @return array{
     *     price: float,
     *     production_mgmt_labor: float,
     *     subcontractor_cost: float,
     *     wc_insurance_burden: float,
     *     material_cost: float,
     *     sales_commission: float,
     *     total_cost: float,
     *     gross_profit: float,
     *     gross_profit_percent: float,
     *     count: int
     * }
     */
    public function getRunningTotals(int $year): array
    {
        $cards = $this->getCardsForYear($year);

        $totals = [
            'price' => 0.0,
            'production_mgmt_labor' => 0.0,
            'subcontractor_cost' => 0.0,
            'wc_insurance_burden' => 0.0,
            'material_cost' => 0.0,
            'sales_commission' => 0.0,
            'total_cost' => 0.0,
            'gross_profit' => 0.0,
            'gross_profit_percent' => 0.0,
            'count' => $cards->count(),
        ];

        foreach ($cards as $card) {
            $totals['price'] += (float) $card->price;
            $totals['production_mgmt_labor'] += (float) $card->production_mgmt_labor;
            $totals['subcontractor_cost'] += (float) $card->subcontractor_cost;
            $totals['wc_insurance_burden'] += (float) $card->wc_insurance_burden;
            $totals['material_cost'] += (float) $card->material_cost;
            $totals['sales_commission'] += (float) $card->sales_commission;
            $totals['total_cost'] += $card->total_cost;
            $totals['gross_profit'] += $card->gross_profit;
        }

        // Round all totals
        foreach (['price', 'production_mgmt_labor', 'subcontractor_cost', 'wc_insurance_burden', 'material_cost', 'sales_commission', 'total_cost', 'gross_profit'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }

        // Calculate overall gross profit percent
        if ($totals['price'] > 0) {
            $totals['gross_profit_percent'] = round($totals['gross_profit'] / $totals['price'] * 100, 1);
        }

        return $totals;
    }
}
