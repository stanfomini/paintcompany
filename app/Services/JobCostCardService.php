<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobCostCard;
use Illuminate\Support\Collection;

class JobCostCardService
{
    /**
     * Get all job cost cards for a year, combining persisted entries
     * and auto-generated cards from completed jobs without cards.
     *
     * @return Collection<int, JobCostCard>
     */
    public function getCardsForYear(int $year): Collection
    {
        $settings = CompanySetting::getInstance();

        // Get all persisted cards (manual + projected + active + completed)
        $persistedCards = JobCostCard::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->get();

        // Also generate non-persisted cards from completed jobs that don't have cards yet
        $existingJobIds = $persistedCards->whereNotNull('job_id')->pluck('job_id')->toArray();

        $completedJobs = Job::query()
            ->where('stage', 'completed')
            ->whereYear('actual_end_date', $year)
            ->whereNotIn('id', $existingJobIds)
            ->with(['deal.customer', 'estimate.areas.surfaces'])
            ->get();

        $generatedCards = $completedJobs->map(function (Job $job) use ($settings, $year) {
            $costs = $this->extractCostsFromEstimate($job->estimate, $job, $settings);

            return new JobCostCard([
                'job_id' => $job->id,
                'company_setting_id' => $settings->id,
                'year' => $year,
                'client_name' => $costs['client_name'],
                'price' => $costs['price'],
                'production_mgmt_labor' => $costs['production_mgmt'],
                'subcontractor_cost' => 0,
                'wc_insurance_burden' => $costs['wc_insurance'],
                'material_cost' => $costs['material_cost'],
                'sales_commission' => $costs['sales_commission'],
                'is_manual' => false,
                'status' => JobCostCard::STATUS_COMPLETED,
            ]);
        });

        return $persistedCards->merge($generatedCards)
            ->sortBy([
                fn ($a, $b) => $this->statusSortOrder($a->status ?? 'active') <=> $this->statusSortOrder($b->status ?? 'active'),
                fn ($a, $b) => ($a->client_name ?? '') <=> ($b->client_name ?? ''),
            ])
            ->values();
    }

    protected function statusSortOrder(string $status): int
    {
        return match ($status) {
            'projected' => 0,
            'active' => 1,
            'completed' => 2,
            default => 1,
        };
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
            $costs = $this->extractCostsFromEstimate($job->estimate, $job, $settings);

            return new JobCostCard([
                'job_id' => $job->id,
                'company_setting_id' => $settings->id,
                'year' => $year,
                'client_name' => $costs['client_name'],
                'price' => $costs['price'],
                'production_mgmt_labor' => $costs['production_mgmt'],
                'subcontractor_cost' => 0,
                'wc_insurance_burden' => $costs['wc_insurance'],
                'material_cost' => $costs['material_cost'],
                'sales_commission' => $costs['sales_commission'],
                'is_manual' => false,
                'status' => JobCostCard::STATUS_COMPLETED,
            ]);
        });

        return $persistedCards->merge($generatedCards);
    }

    /**
     * Create a projected job cost card when an estimate is accepted.
     */
    public function createFromEstimate(Estimate $estimate, Job $job): JobCostCard
    {
        $settings = CompanySetting::getInstance();
        $costs = $this->extractCostsFromEstimate($estimate, $job, $settings);

        $card = JobCostCard::create([
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'company_setting_id' => $settings->id,
            'year' => now()->year,
            'client_name' => $costs['client_name'],
            'price' => $costs['price'],
            'production_mgmt_labor' => $costs['production_mgmt'],
            'subcontractor_cost' => 0,
            'wc_insurance_burden' => $costs['wc_insurance'],
            'material_cost' => $costs['material_cost'],
            'sales_commission' => $costs['sales_commission'],
            'is_manual' => false,
            'status' => JobCostCard::STATUS_PROJECTED,
        ]);

        try {
            app(AllocationEvaluationService::class)->evaluateForCard($card);
        } catch (\Throwable $e) {
            report($e);
        }

        return $card;
    }

    /**
     * Backfill missing job cost cards for jobs that have estimates but no auto cards.
     * Idempotent — safe to call multiple times.
     */
    public function backfillMissingCards(int $year): int
    {
        $settings = CompanySetting::getInstance();

        $jobsMissingCards = Job::query()
            ->whereNotNull('estimate_id')
            ->whereYear('created_at', $year)
            ->whereDoesntHave('costCards', function ($q) use ($settings) {
                $q->where('company_setting_id', $settings->id)
                    ->where('is_manual', false);
            })
            ->with(['deal.customer', 'estimate.areas.surfaces'])
            ->get();

        $count = 0;

        foreach ($jobsMissingCards as $job) {
            $estimate = $job->estimate;
            if (! $estimate) {
                continue;
            }

            $costs = $this->extractCostsFromEstimate($estimate, $job, $settings);

            JobCostCard::create([
                'job_id' => $job->id,
                'estimate_id' => $estimate->id,
                'company_setting_id' => $settings->id,
                'year' => $year,
                'client_name' => $costs['client_name'],
                'price' => $costs['price'],
                'production_mgmt_labor' => $costs['production_mgmt'],
                'subcontractor_cost' => 0,
                'wc_insurance_burden' => $costs['wc_insurance'],
                'material_cost' => $costs['material_cost'],
                'sales_commission' => $costs['sales_commission'],
                'is_manual' => false,
                'status' => $this->mapJobStageToCardStatus($job->stage),
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Update a projected/active card to completed status when a job finishes.
     */
    public function updateCardOnJobCompletion(Job $job): void
    {
        $card = JobCostCard::where('job_id', $job->id)
            ->where('is_manual', false)
            ->first();

        if (! $card) {
            return;
        }

        $card->update(['status' => JobCostCard::STATUS_COMPLETED]);

        try {
            app(AllocationEvaluationService::class)->evaluateForCard($card);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Update a projected card to active status when a job moves to in_progress.
     */
    public function updateCardToActive(Job $job): void
    {
        $card = JobCostCard::where('job_id', $job->id)
            ->where('is_manual', false)
            ->first();

        if (! $card) {
            return;
        }

        $card->update(['status' => JobCostCard::STATUS_ACTIVE]);

        try {
            app(AllocationEvaluationService::class)->evaluateForCard($card);
        } catch (\Throwable $e) {
            report($e);
        }
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
     *     allocation_total: float,
     *     total_cost: float,
     *     gross_profit: float,
     *     gross_profit_percent: float,
     *     net_profit: float,
     *     net_profit_percent: float,
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
            'allocation_total' => 0.0,
            'total_cost' => 0.0,
            'gross_profit' => 0.0,
            'gross_profit_percent' => 0.0,
            'net_profit' => 0.0,
            'net_profit_percent' => 0.0,
            'count' => $cards->count(),
        ];

        foreach ($cards as $card) {
            $totals['price'] += (float) $card->price;
            $totals['production_mgmt_labor'] += (float) $card->production_mgmt_labor;
            $totals['subcontractor_cost'] += (float) $card->subcontractor_cost;
            $totals['wc_insurance_burden'] += (float) $card->wc_insurance_burden;
            $totals['material_cost'] += (float) $card->material_cost;
            $totals['sales_commission'] += (float) $card->sales_commission;
            $totals['allocation_total'] += (float) $card->allocation_total;
            $totals['total_cost'] += $card->total_cost;
            $totals['gross_profit'] += $card->gross_profit;
            $totals['net_profit'] += $card->net_profit;
        }

        // Round all totals
        foreach (['price', 'production_mgmt_labor', 'subcontractor_cost', 'wc_insurance_burden', 'material_cost', 'sales_commission', 'allocation_total', 'total_cost', 'gross_profit', 'net_profit'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }

        // Calculate overall percentages
        if ($totals['price'] > 0) {
            $totals['gross_profit_percent'] = round($totals['gross_profit'] / $totals['price'] * 100, 1);
            $totals['net_profit_percent'] = round($totals['net_profit'] / $totals['price'] * 100, 1);
        }

        return $totals;
    }

    /**
     * Map a job stage to the appropriate cost card status.
     */
    public function mapJobStageToCardStatus(string $stage): string
    {
        return match ($stage) {
            'in_progress' => JobCostCard::STATUS_ACTIVE,
            'completed' => JobCostCard::STATUS_COMPLETED,
            default => JobCostCard::STATUS_PROJECTED,
        };
    }

    /**
     * Extract cost data from an estimate and job for building a cost card.
     *
     * @return array{client_name: string, price: float, material_cost: float, labor_cost: float, production_mgmt: float, sales_commission: float, wc_insurance: float}
     */
    private function extractCostsFromEstimate(?Estimate $estimate, Job $job, CompanySetting $settings): array
    {
        $customer = $job->deal?->customer;
        $clientName = $customer
            ? $customer->full_name
            : ($job->title ?? 'Unknown Client');

        $price = $estimate ? (float) $estimate->subtotal : 0.0;

        $materialCost = 0.0;
        $laborCost = 0.0;

        if ($estimate) {
            foreach ($estimate->areas as $area) {
                $includedSurfaces = $area->surfaces->where('is_included', true);
                $materialCost += (float) $includedSurfaces->sum('material_cost');
                $laborCost += (float) $includedSurfaces->sum('labor_cost');
            }
        }

        return [
            'client_name' => $clientName,
            'price' => $price,
            'material_cost' => $materialCost,
            'labor_cost' => $laborCost,
            'production_mgmt' => round($price * ((float) $settings->production_management_percent / 100), 2),
            'sales_commission' => round($price * ((float) $settings->sales_percent / 100), 2),
            'wc_insurance' => round($laborCost * ((float) $settings->payroll_burden_multiplier - 1), 2),
        ];
    }
}
