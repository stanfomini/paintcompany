<?php

namespace App\Services;

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\JobCostCardAllocation;

class AllocationEvaluationService
{
    /**
     * Evaluate all allocations for a job cost card and sync results.
     */
    public function evaluateForCard(JobCostCard $card): void
    {
        $settings = CompanySetting::getInstance();
        $context = $this->buildContext($card);

        $allocations = CompanyAllocation::query()
            ->where('company_setting_id', $settings->id)
            ->where('is_active', true)
            ->where('is_informational', false)
            ->ordered()
            ->get();

        // Delete existing card allocations and rebuild
        $card->cardAllocations()->delete();

        $totalAmount = 0.0;
        $laborHours = $this->getLaborHours($card);

        foreach ($allocations as $allocation) {
            if (! $allocation->evaluateConditions($context)) {
                continue;
            }

            $amount = $allocation->calculateAmount((float) $card->price, $laborHours);

            if ($amount <= 0) {
                continue;
            }

            JobCostCardAllocation::create([
                'job_cost_card_id' => $card->id,
                'company_allocation_id' => $allocation->id,
                'name' => $allocation->name,
                'slug' => $allocation->slug,
                'type' => $allocation->type,
                'value' => $allocation->value,
                'amount' => $amount,
                'conditions_snapshot' => $allocation->conditions,
            ]);

            $totalAmount += $amount;
        }

        $card->update(['allocation_total' => round($totalAmount, 2)]);
    }

    /**
     * Build evaluation context from a card's related data.
     *
     * @return array{scope_type: string, has_scope: array, payment_methods: array, job_stage: string|null, job_price: float}
     */
    public function buildContext(JobCostCard $card): array
    {
        $job = $card->job;
        $estimate = $card->estimate ?? $job?->estimate;

        $scopeType = $estimate?->project_type ?? 'interior';

        $hasScope = [];
        if ($estimate) {
            $hasScope = $estimate->areas()
                ->distinct()
                ->pluck('scope_type')
                ->filter()
                ->values()
                ->toArray();
        }
        if (empty($hasScope)) {
            $hasScope = [$scopeType];
        }

        $paymentMethods = [];
        if ($job) {
            $paymentMethods = $job->invoices()
                ->with('payments')
                ->get()
                ->flatMap(fn ($invoice) => $invoice->payments->pluck('method'))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        return [
            'scope_type' => $scopeType,
            'has_scope' => $hasScope,
            'payment_methods' => $paymentMethods,
            'job_stage' => $job?->stage,
            'job_price' => (float) $card->price,
        ];
    }

    /**
     * Recalculate allocations for all cards associated with a job.
     */
    public function recalculateForJob(Job $job): void
    {
        $cards = JobCostCard::where('job_id', $job->id)->get();

        foreach ($cards as $card) {
            $this->evaluateForCard($card);
        }
    }

    /**
     * Batch recalculate allocations for all persisted cards.
     */
    public function recalculateAllCards(?int $year = null): int
    {
        $query = JobCostCard::query()
            ->where('company_setting_id', CompanySetting::getInstance()->id);

        if ($year) {
            $query->where('year', $year);
        }

        $cards = $query->get();
        $count = 0;

        foreach ($cards as $card) {
            $this->evaluateForCard($card);
            $count++;
        }

        return $count;
    }

    /**
     * Get labor hours from the estimate's area surfaces.
     */
    protected function getLaborHours(JobCostCard $card): float
    {
        $estimate = $card->estimate ?? $card->job?->estimate;

        if (! $estimate) {
            return 0.0;
        }

        $hours = 0.0;

        foreach ($estimate->areas as $area) {
            foreach ($area->surfaces->where('is_included', true) as $surface) {
                $hours += (float) ($surface->labor_hours ?? 0);
            }
        }

        return $hours;
    }
}
