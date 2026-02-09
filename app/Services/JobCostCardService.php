<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Support\Collection;

class JobCostCardService
{
    public function __construct(
        private ?EstimateCostBreakdownService $breakdownService = null,
    ) {
        $this->breakdownService ??= new EstimateCostBreakdownService;
    }

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
            $data = $this->buildCardDataFromEstimate($job->estimate, $job, $settings);

            return new JobCostCard(array_merge($data, [
                'company_setting_id' => $settings->id,
                'year' => $year,
                'is_manual' => false,
                'status' => JobCostCard::STATUS_COMPLETED,
            ]));
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
     *
     * @return Collection<int, JobCostCard>
     */
    public function generateFromCompletedJobs(int $year): Collection
    {
        $settings = CompanySetting::getInstance();

        $existingJobIds = JobCostCard::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->where('is_manual', false)
            ->whereNotNull('job_id')
            ->pluck('job_id')
            ->toArray();

        $persistedCards = JobCostCard::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->where('is_manual', false)
            ->get();

        $completedJobs = Job::query()
            ->where('stage', 'completed')
            ->whereYear('actual_end_date', $year)
            ->whereNotIn('id', $existingJobIds)
            ->with(['deal.customer', 'estimate.areas.surfaces'])
            ->get();

        $generatedCards = $completedJobs->map(function (Job $job) use ($settings, $year) {
            $data = $this->buildCardDataFromEstimate($job->estimate, $job, $settings);

            return new JobCostCard(array_merge($data, [
                'company_setting_id' => $settings->id,
                'year' => $year,
                'is_manual' => false,
                'status' => JobCostCard::STATUS_COMPLETED,
            ]));
        });

        return $persistedCards->merge($generatedCards);
    }

    /**
     * Create a projected job cost card when an estimate is accepted.
     */
    public function createFromEstimate(Estimate $estimate, Job $job): JobCostCard
    {
        $settings = CompanySetting::getInstance();
        $data = $this->buildCardDataFromEstimate($estimate, $job, $settings);

        $card = JobCostCard::create(array_merge($data, [
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'company_setting_id' => $settings->id,
            'year' => now()->year,
            'is_manual' => false,
            'status' => JobCostCard::STATUS_PROJECTED,
        ]));

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

            $data = $this->buildCardDataFromEstimate($estimate, $job, $settings);

            $card = JobCostCard::create(array_merge($data, [
                'job_id' => $job->id,
                'estimate_id' => $estimate->id,
                'company_setting_id' => $settings->id,
                'year' => $year,
                'is_manual' => false,
                'status' => $this->mapJobStageToCardStatus($job->stage),
            ]));

            try {
                app(AllocationEvaluationService::class)->evaluateForCard($card);
            } catch (\Throwable $e) {
                report($e);
            }

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
     * Sync payment summary from job's invoices onto the cost card.
     */
    public function syncPaymentSummary(Job $job): void
    {
        $card = JobCostCard::where('job_id', $job->id)
            ->where('is_manual', false)
            ->first();

        if (! $card) {
            return;
        }

        $invoices = Invoice::where('job_id', $job->id)
            ->whereNotIn('status', [Invoice::STATUS_VOID])
            ->get();

        $totalInvoiced = $invoices->sum(fn ($inv) => (float) $inv->total);
        $totalCollected = $invoices->sum(fn ($inv) => (float) $inv->amount_paid);
        $balanceDue = round($totalInvoiced - $totalCollected, 2);

        $card->update([
            'total_invoiced' => round($totalInvoiced, 2),
            'total_collected' => round($totalCollected, 2),
            'balance_due' => max(0, $balanceDue),
        ]);

        // Re-evaluate allocations since payment_methods context may have changed
        try {
            app(AllocationEvaluationService::class)->evaluateForCard($card);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Save actual cost tracking data onto a cost card (supports all granular fields).
     */
    public function updateActuals(JobCostCard $card, array $actuals): void
    {
        $granularFields = [
            'actual_material_cost', 'actual_labor_cost', 'actual_subcontractor_cost',
            'actual_other_cost', 'actual_cleanup_cost', 'actual_sundries_cost',
            'actual_material_tax', 'actual_labor_tax', 'actual_addon_cost',
            'actual_items_vendor_cost', 'actual_warranty_reserve', 'actual_crew_bonus',
            'actual_allocation_total',
        ];

        $data = [];
        $hasAny = false;

        foreach ($granularFields as $field) {
            if (array_key_exists($field, $actuals)) {
                $data[$field] = $actuals[$field] !== null && $actuals[$field] !== '' ? (float) $actuals[$field] : null;
                if ($data[$field] !== null) {
                    $hasAny = true;
                }
            }
        }

        if (array_key_exists('actual_notes', $actuals)) {
            $data['actual_notes'] = $actuals['actual_notes'];
        }

        $card->update($data);

        // Recompute total from all granular COGS fields
        $card->refresh();
        if ($hasAny || $card->has_actuals) {
            $card->update([
                'actual_total_cost' => $card->actual_cogs,
            ]);
        }
    }

    /**
     * Save actual amounts for individual allocations.
     *
     * @param  array<int, float|null>  $actuals  allocation_id => actual_amount
     */
    public function updateAllocationActuals(JobCostCard $card, array $actuals): void
    {
        $totalActualAllocation = 0.0;

        foreach ($card->cardAllocations as $allocation) {
            if (array_key_exists($allocation->id, $actuals)) {
                $actualAmount = $actuals[$allocation->id] !== null && $actuals[$allocation->id] !== ''
                    ? (float) $actuals[$allocation->id] : null;
                $allocation->update(['actual_amount' => $actualAmount]);
                $totalActualAllocation += ($actualAmount ?? 0);
            } else {
                $totalActualAllocation += (float) ($allocation->actual_amount ?? 0);
            }
        }

        $card->update([
            'actual_allocation_total' => round($totalActualAllocation, 2),
        ]);
    }

    /**
     * Create a draft journal entry from the card's actual costs.
     * Maps each actual line to the corresponding chart of accounts entry.
     */
    public function createJournalEntryFromActuals(JobCostCard $card, int $createdBy): JournalEntry
    {
        $settings = CompanySetting::getInstance();

        $entryNumber = 'JE-'.now()->year.'-'.str_pad(
            (JournalEntry::where('company_setting_id', $settings->id)->count() + 1),
            5, '0', STR_PAD_LEFT
        );

        $entry = JournalEntry::create([
            'company_setting_id' => $settings->id,
            'entry_number' => $entryNumber,
            'transaction_date' => now(),
            'recognition_date' => now(),
            'description' => "Job Cost Card actuals: {$card->client_name}",
            'reference_type' => 'job_cost_card',
            'reference_id' => $card->id,
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by' => $createdBy,
        ]);

        $revenueCode = $card->scope_type === 'exterior' ? '4020' : '4010';
        $lines = [];
        $totalDebits = 0;

        // Credit: Revenue (full customer price)
        $lines[] = [
            'code' => $revenueCode,
            'debit' => 0,
            'credit' => (float) $card->price,
            'desc' => 'Revenue — '.$card->client_name,
        ];

        // Debit: COGS lines (only when actual > 0)
        $cogsMapping = [
            'actual_labor_cost' => ['code' => '5010', 'desc' => 'Painter Labor'],
            'actual_cleanup_cost' => ['code' => '5070', 'desc' => 'Cleanup & Disposal'],
            'actual_sundries_cost' => ['code' => '5040', 'desc' => 'Sundries & Supplies'],
            'actual_material_cost' => ['code' => '5030', 'desc' => 'Paint Materials'],
            'actual_subcontractor_cost' => ['code' => '5050', 'desc' => 'Subcontractor Costs'],
        ];

        foreach ($cogsMapping as $field => $mapping) {
            $amount = (float) ($card->$field ?? 0);
            if ($amount > 0) {
                $lines[] = [
                    'code' => $mapping['code'],
                    'debit' => $amount,
                    'credit' => 0,
                    'desc' => $mapping['desc'],
                ];
                $totalDebits += $amount;
            }
        }

        // Debit: Sales Tax (material tax + labor tax) → liability account
        $taxTotal = (float) ($card->actual_material_tax ?? 0) + (float) ($card->actual_labor_tax ?? 0);
        if ($taxTotal > 0) {
            $lines[] = [
                'code' => '2300',
                'debit' => $taxTotal,
                'credit' => 0,
                'desc' => 'Sales Tax Payable',
            ];
            $totalDebits += $taxTotal;
        }

        // Debit: Warranty reserve → liability account
        $warrantyAmount = (float) ($card->actual_warranty_reserve ?? 0);
        if ($warrantyAmount > 0) {
            $lines[] = [
                'code' => '2600',
                'debit' => $warrantyAmount,
                'credit' => 0,
                'desc' => 'Warranty Reserve',
            ];
            $totalDebits += $warrantyAmount;
        }

        // Debit: Crew bonus → COGS
        $crewBonusAmount = (float) ($card->actual_crew_bonus ?? 0);
        if ($crewBonusAmount > 0) {
            $lines[] = [
                'code' => '5010',
                'debit' => $crewBonusAmount,
                'credit' => 0,
                'desc' => 'Crew Lead Bonus',
            ];
            $totalDebits += $crewBonusAmount;
        }

        // Debit: Allocation lines (marketing, sales, PM, overhead, etc.)
        $allocationMapping = [
            'marketing' => '6300',
            'sales' => '7010',
            'sales_commission' => '7010',
            'sales-commission' => '7010',
            'production_management' => '7030',
            'production-management' => '7030',
            'office_staff' => '6020',
            'taxes' => '2800',
            'stripe_processing' => '6620',
            'software' => '6500',
            'fixed_office_cost' => '6100',
            'general_overhead' => '6000',
        ];

        $card->load('cardAllocations');
        foreach ($card->cardAllocations as $allocation) {
            $amount = (float) ($allocation->actual_amount ?? $allocation->amount);
            if ($amount > 0) {
                $code = $allocationMapping[$allocation->slug] ?? '6990';
                $lines[] = [
                    'code' => $code,
                    'debit' => $amount,
                    'credit' => 0,
                    'desc' => $allocation->name,
                ];
                $totalDebits += $amount;
            }
        }

        // Profit First: Split remaining profit into tax reserve + profit reserve + retained earnings
        $remainingProfit = round((float) $card->price - $totalDebits, 2);

        if ($remainingProfit > 0) {
            $taxReservePercent = (float) ($settings->tax_reserve_percent ?? 30.00);
            $profitReservePercent = (float) ($settings->profit_reserve_percent ?? 5.00);

            // Income Tax Reserve (liability - set aside for estimated taxes)
            $taxReserve = round($remainingProfit * ($taxReservePercent / 100), 2);
            if ($taxReserve > 0) {
                $lines[] = [
                    'code' => '2800',
                    'debit' => $taxReserve,
                    'credit' => 0,
                    'desc' => "Income Tax Reserve ({$taxReservePercent}% of net profit)",
                ];
                $totalDebits += $taxReserve;
            }

            // Profit Reserve (equity - Profit First "take your profit first")
            $profitReserve = round($remainingProfit * ($profitReservePercent / 100), 2);
            if ($profitReserve > 0) {
                $lines[] = [
                    'code' => '3300',
                    'debit' => $profitReserve,
                    'credit' => 0,
                    'desc' => "Profit Reserve ({$profitReservePercent}% of net profit)",
                ];
                $totalDebits += $profitReserve;
            }

            // Retained Earnings (whatever is left after tax + profit reserves)
            $retainedEarnings = round($remainingProfit - $taxReserve - $profitReserve, 2);
            if ($retainedEarnings > 0) {
                $lines[] = [
                    'code' => '3200',
                    'debit' => $retainedEarnings,
                    'credit' => 0,
                    'desc' => 'Retained Earnings (operating funds)',
                ];
            }
        }

        // Create journal entry lines
        foreach ($lines as $line) {
            $account = Account::where('company_setting_id', $settings->id)
                ->where('code', $line['code'])
                ->first();

            if ($account) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'debit_amount' => round($line['debit'], 2),
                    'credit_amount' => round($line['credit'], 2),
                    'description' => $line['desc'],
                ]);
            }
        }

        // Link journal entry to the card
        $card->update(['journal_entry_id' => $entry->id]);

        return $entry;
    }

    /**
     * Calculate the bank distribution for a completed job card.
     * Maps actuals to 6 Relay bank account buckets (read-only).
     *
     * @return array<string, float>
     */
    public function calculateBankDistribution(JobCostCard $card): array
    {
        $price = (float) $card->price;
        $actualCogs = $card->actual_cogs;
        $actualAllocations = (float) ($card->actual_allocation_total ?? 0);

        return [
            'cogs_account' => round($actualCogs, 2),
            'tax_account' => round((float) ($card->actual_material_tax ?? 0) + (float) ($card->actual_labor_tax ?? 0), 2),
            'marketing_account' => round($this->getAllocationActualBySlug($card, 'marketing'), 2),
            'sales_account' => round($this->getAllocationActualBySlug($card, 'sales-commission') + $this->getAllocationActualBySlug($card, 'sales'), 2),
            'operations_account' => round($this->getAllocationActualBySlug($card, 'production-management'), 2),
            'profit_account' => round($price - $actualCogs - $actualAllocations, 2),
        ];
    }

    /**
     * Helper to get actual amount for an allocation by slug.
     */
    private function getAllocationActualBySlug(JobCostCard $card, string $slug): float
    {
        $card->loadMissing('cardAllocations');
        $allocation = $card->cardAllocations->firstWhere('slug', $slug);

        return (float) ($allocation?->actual_amount ?? $allocation?->amount ?? 0);
    }

    /**
     * Refresh a card's COGS decomposition from the breakdown service.
     * Used by backfill command and manual refresh.
     */
    public function refreshBreakdownData(JobCostCard $card): bool
    {
        $estimate = $card->estimate;
        if (! $estimate) {
            return false;
        }

        $estimate->loadMissing(['areas.surfaces.productionRate', 'areas.addons', 'items', 'packages.items.itemable']);

        try {
            $breakdown = $this->breakdownService->calculateBreakdown($estimate);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        $card->update($this->mapBreakdownToCardColumns($breakdown));

        return true;
    }

    /**
     * Get running totals across all cards for a year.
     *
     * @return array<string, mixed>
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
            // New fields
            'total_cogs' => 0.0,
            'labor_hours' => 0.0,
            'total_hours' => 0.0,
            'total_invoiced' => 0.0,
            'total_collected' => 0.0,
            'balance_due' => 0.0,
            // Actual totals
            'actual_total_cost' => 0.0,
            'actual_allocation_total' => 0.0,
            'cards_with_actuals' => 0,
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
            // New aggregates
            $totals['total_cogs'] += (float) $card->total_cogs;
            $totals['labor_hours'] += (float) $card->labor_hours;
            $totals['total_hours'] += (float) $card->total_hours;
            $totals['total_invoiced'] += (float) $card->total_invoiced;
            $totals['total_collected'] += (float) $card->total_collected;
            $totals['balance_due'] += (float) $card->balance_due;
            // Actual aggregates
            if ($card->has_actuals) {
                $totals['actual_total_cost'] += (float) ($card->actual_total_cost ?? 0);
                $totals['actual_allocation_total'] += (float) ($card->actual_allocation_total ?? 0);
                $totals['cards_with_actuals']++;
            }
        }

        // Round all numeric totals
        foreach (['price', 'production_mgmt_labor', 'subcontractor_cost', 'wc_insurance_burden', 'material_cost', 'sales_commission', 'allocation_total', 'total_cost', 'gross_profit', 'net_profit', 'total_cogs', 'labor_hours', 'total_hours', 'total_invoiced', 'total_collected', 'balance_due', 'actual_total_cost', 'actual_allocation_total'] as $key) {
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
     * Build the full card data array from an estimate using the breakdown service.
     *
     * @return array<string, mixed>
     */
    private function buildCardDataFromEstimate(?Estimate $estimate, Job $job, CompanySetting $settings): array
    {
        $customer = $job->deal?->customer;
        $clientName = $customer
            ? $customer->full_name
            : ($job->title ?? 'Unknown Client');

        if (! $estimate) {
            return [
                'client_name' => $clientName,
                'price' => 0,
                'production_mgmt_labor' => 0,
                'subcontractor_cost' => 0,
                'wc_insurance_burden' => 0,
                'material_cost' => 0,
                'sales_commission' => 0,
            ];
        }

        // Legacy column mapping (always computed for backwards compatibility)
        $legacyData = $this->legacyExtractCosts($estimate, $job, $settings, $clientName);

        // Ensure relations are loaded for breakdown calculation
        $estimate->loadMissing(['areas.surfaces.productionRate', 'areas.addons', 'items', 'packages.items.itemable', 'job']);

        try {
            $breakdown = $this->breakdownService->calculateBreakdown($estimate);
        } catch (\Throwable $e) {
            report($e);

            return $legacyData;
        }

        // The card price uses the estimate's stored subtotal (authoritative customer-facing price)
        // while the breakdown decomposition populates the new COGS detail columns
        return array_merge($legacyData, $this->mapBreakdownToCardColumns($breakdown));
    }

    /**
     * Map breakdown service output to card column values.
     *
     * @return array<string, mixed>
     */
    private function mapBreakdownToCardColumns(array $breakdown): array
    {
        $cogs = $breakdown['cogs'] ?? [];
        $totals = $breakdown['totals'] ?? [];
        $grossProfit = $breakdown['gross_profit'] ?? [];
        $crewBonus = $breakdown['crew_bonus'] ?? null;
        $settingsUsed = $breakdown['settings_used'] ?? null;

        return [
            // COGS decomposition
            'labor_cost' => $cogs['labor_cost'] ?? 0,
            'cleanup_cost' => $cogs['cleanup_cost'] ?? 0,
            'sundries_cost' => $cogs['sundries_cost'] ?? 0,
            'material_tax' => $cogs['material_tax'] ?? 0,
            'labor_tax' => $cogs['labor_tax'] ?? 0,
            'addon_cost' => $cogs['addon_cost'] ?? 0,
            'items_vendor_cost' => $cogs['items_vendor_cost'] ?? 0,
            'warranty_reserve' => $cogs['warranty_price'] ?? 0,
            'total_cogs' => $cogs['total'] ?? 0,
            // Hours
            'labor_hours' => $totals['labor_hours'] ?? 0,
            'cleanup_hours' => $totals['cleanup_hours'] ?? 0,
            'total_hours' => $totals['total_hours'] ?? 0,
            // Profit snapshot
            'projected_gross_profit' => $grossProfit['amount'] ?? 0,
            'projected_gross_profit_percent' => $grossProfit['percent'] ?? 0,
            'crew_bonus' => $crewBonus ? ($crewBonus['bonus_amount'] ?? 0) : 0,
            // Metadata
            'scope_type' => $breakdown['scope'] ?? null,
            'settings_snapshot' => $settingsUsed,
        ];
    }

    /**
     * Legacy cost extraction for backwards compatibility.
     *
     * @return array<string, mixed>
     */
    private function legacyExtractCosts(Estimate $estimate, Job $job, CompanySetting $settings, string $clientName): array
    {
        $price = (float) $estimate->subtotal;

        $materialCost = 0.0;
        $laborCost = 0.0;

        foreach ($estimate->areas as $area) {
            $includedSurfaces = $area->surfaces->where('is_included', true);
            $materialCost += (float) $includedSurfaces->sum('material_cost');
            $laborCost += (float) $includedSurfaces->sum('labor_cost');
        }

        return [
            'client_name' => $clientName,
            'price' => $price,
            'material_cost' => $materialCost,
            'production_mgmt_labor' => round($price * ((float) $settings->production_management_percent / 100), 2),
            'sales_commission' => round($price * ((float) $settings->sales_percent / 100), 2),
            'wc_insurance_burden' => round($laborCost * ((float) $settings->payroll_burden_multiplier - 1), 2),
            'subcontractor_cost' => 0,
        ];
    }
}
