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
     * Gathers ~30 business variables from the card's job, estimate,
     * deal, customer, and payment data for condition evaluation.
     *
     * @return array<string, mixed>
     */
    public function buildContext(JobCostCard $card): array
    {
        $job = $card->job;
        $estimate = $card->estimate ?? $job?->estimate;
        $deal = $job?->deal;
        $customer = $deal?->customer;
        $settings = CompanySetting::getInstance();

        // === Scope ===
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

        // === Payment Methods ===
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

        // === Job Sizing ===
        $totalSqft = 0.0;
        $totalLaborHours = 0.0;
        $totalMaterialCost = 0.0;
        $areaCount = 0;
        $maxCoats = 0;
        $hasSpray = false;
        $hasComplexSubstrate = false;
        $requiresPrime = false;
        $usesPremiumPaint = false;
        $substrateCategories = [];
        $hasNestedAreas = false;

        if ($estimate) {
            $areas = $estimate->areas()->with(['surfaces.paintProduct', 'substrate'])->get();
            $areaCount = $areas->count();

            foreach ($areas as $area) {
                $totalSqft += (float) ($area->wall_sqft ?? 0) + (float) ($area->ceiling_sqft ?? 0);

                if ($area->application_method === 'spray') {
                    $hasSpray = true;
                }

                if ($area->is_fixed_price || $area->parent_area_id) {
                    $hasNestedAreas = true;
                }

                // Substrate checks
                if ($area->substrate) {
                    $cat = $area->substrate->category ?? 'standard';
                    if (! in_array($cat, $substrateCategories)) {
                        $substrateCategories[] = $cat;
                    }
                    $multiplier = max(
                        (float) ($area->substrate->prep_time_multiplier ?? 1.0),
                        (float) ($area->substrate->paint_time_multiplier ?? 1.0)
                    );
                    if ($multiplier > 1.3) {
                        $hasComplexSubstrate = true;
                    }
                    if ($area->substrate->requires_prime ?? false) {
                        $requiresPrime = true;
                    }
                }

                foreach ($area->surfaces->where('is_included', true) as $surface) {
                    $totalLaborHours += (float) ($surface->labor_hours ?? 0);
                    $totalMaterialCost += (float) ($surface->material_cost ?? 0);
                    $coats = (int) ($surface->coats ?? 1);
                    if ($coats > $maxCoats) {
                        $maxCoats = $coats;
                    }
                    if ($surface->paintProduct && ($surface->paintProduct->is_premium ?? false)) {
                        $usesPremiumPaint = true;
                    }
                }
            }
        }

        // === Customer & Sales ===
        $leadSource = $deal?->lead_source;
        $hasAgentReferral = $deal?->agent_id !== null;
        $isRepeatCustomer = $customer && $customer->deals()->count() > 1;
        $customerSource = $customer?->source;

        // === Subcontractor ===
        $hasSubcontractor = (float) $card->subcontractor_cost > 0;

        // === Estimate details ===
        $estimatorMode = $estimate?->estimator_mode;
        $depositPercent = (float) ($estimate?->deposit_percent ?? 0);
        $hasOptionsSelected = $estimate && $estimate->options()->where('is_selected', true)->exists();

        // === Crew Size ===
        $crewSize = $job?->required_crew_size ?? $settings->default_required_crew_size ?? 2;

        // === Timing ===
        $dateRef = $job?->scheduled_date ?? $job?->actual_start_date ?? $estimate?->created_at;
        $month = $dateRef ? (int) $dateRef->format('n') : (int) now()->format('n');
        $season = match ((int) ceil($month / 3)) {
            1 => 'winter',
            2 => 'spring',
            3 => 'summer',
            4 => 'fall',
            default => 'summer',
        };

        return [
            // Original fields
            'scope_type' => $scopeType,
            'has_scope' => $hasScope,
            'payment_methods' => $paymentMethods,
            'job_stage' => $job?->stage,
            'job_price' => (float) $card->price,

            // Job Sizing
            'total_sqft' => round($totalSqft, 2),
            'total_labor_hours' => round($totalLaborHours, 2),
            'area_count' => $areaCount,
            'crew_size' => (int) $crewSize,
            'estimator_mode' => $estimatorMode,

            // Customer & Sales
            'lead_source' => $leadSource,
            'has_agent_referral' => $hasAgentReferral,
            'is_repeat_customer' => $isRepeatCustomer,
            'customer_source' => $customerSource,

            // Paint & Materials
            'uses_premium_paint' => $usesPremiumPaint,
            'max_coats' => $maxCoats,
            'total_material_cost' => round($totalMaterialCost, 2),

            // Application & Substrate
            'has_spray_application' => $hasSpray,
            'substrate_categories' => $substrateCategories,
            'has_complex_substrate' => $hasComplexSubstrate,
            'requires_prime' => $requiresPrime,

            // Subcontractor & Complexity
            'has_subcontractor' => $hasSubcontractor,
            'has_nested_areas' => $hasNestedAreas,
            'has_options_selected' => $hasOptionsSelected,
            'deposit_percent' => $depositPercent,

            // Timing
            'month' => $month,
            'season' => $season,
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
