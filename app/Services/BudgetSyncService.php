<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\MarketingTrackingEntry;
use Carbon\Carbon;

class BudgetSyncService
{
    protected CompanySetting $settings;

    protected AccountingService $accountingService;

    public function __construct(?AccountingService $accountingService = null)
    {
        $this->settings = CompanySetting::getInstance();
        $this->accountingService = $accountingService ?? new AccountingService;
    }

    /**
     * Sync all budget actuals for a given year from live data sources.
     *
     * @return array{synced: int, categories: array<string, float>}
     */
    public function syncActualsForYear(int $year): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->endOfDay();

        $categories = BudgetCategory::where('company_setting_id', $this->settings->id)
            ->get();

        $synced = 0;
        $results = [];

        foreach ($categories as $category) {
            $actual = $this->getActualForCategory($category, $year, $from, $to);

            if ($actual !== null) {
                BudgetLineItem::updateOrCreate(
                    [
                        'company_setting_id' => $this->settings->id,
                        'budget_category_id' => $category->id,
                        'year' => $year,
                    ],
                    [
                        'current_year_actual' => $actual,
                        'sync_source' => $this->getSyncSource($category),
                        'synced_at' => now(),
                    ]
                );

                $synced++;
                $results[$category->name] = $actual;
            }
        }

        return [
            'synced' => $synced,
            'categories' => $results,
        ];
    }

    /**
     * Get the actual amount for a budget category from the appropriate data source.
     */
    protected function getActualForCategory(BudgetCategory $category, int $year, Carbon $from, Carbon $to): ?float
    {
        return match ($category->section) {
            BudgetCategory::SECTION_REVENUE => $this->getRevenueActual($category, $from, $to),
            BudgetCategory::SECTION_VARIABLE_EXPENSE => $this->getVariableExpenseActual($category, $year),
            BudgetCategory::SECTION_VEHICLE_EXPENSE,
            BudgetCategory::SECTION_BUSINESS_EXPENSE => $this->getCategoryExpenseActual($category, $from, $to),
            BudgetCategory::SECTION_MARKETING_EXPENSE => $this->getMarketingExpenseActual($year),
            default => null,
        };
    }

    /**
     * Revenue actual: Sum of paid invoices for the year.
     */
    protected function getRevenueActual(BudgetCategory $category, Carbon $from, Carbon $to): float
    {
        // Try journal entries first (revenue accounts in 4000 series)
        $mode = $this->accountingService->getAccountingMode();
        $revenueAccounts = Account::where('company_setting_id', $this->settings->id)
            ->where('type', Account::TYPE_REVENUE)
            ->where('is_active', true)
            ->pluck('id');

        if ($revenueAccounts->isNotEmpty()) {
            $column = $mode === 'accrual' ? 'recognition_date' : 'transaction_date';

            $total = JournalEntryLine::whereIn('account_id', $revenueAccounts)
                ->whereHas('journalEntry', function ($q) use ($column, $from, $to) {
                    $q->where('status', JournalEntry::STATUS_POSTED)
                        ->whereNotNull($column)
                        ->whereBetween($column, [$from, $to]);
                })
                ->sum('credit_amount');

            if ((float) $total > 0) {
                return round((float) $total, 2);
            }
        }

        // Fallback: Sum of paid invoice totals
        return round((float) Invoice::where('company_setting_id', $this->settings->id)
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('total'), 2);
    }

    /**
     * Variable expense actual from job cost card data.
     * Maps category slugs to JCC fields.
     */
    protected function getVariableExpenseActual(BudgetCategory $category, int $year): ?float
    {
        $slug = $category->slug ?? '';

        // Map budget category slugs to job cost card columns
        $jccMapping = [
            'production-management-labor' => 'production_mgmt_labor',
            'production-mgmt-labor' => 'production_mgmt_labor',
            'subcontractors' => 'subcontractor_cost',
            'workers-comp-insurance' => 'wc_insurance_burden',
            'wc-insurance' => 'wc_insurance_burden',
            'materials' => 'material_cost',
            'sales-commissions' => 'sales_commission',
        ];

        $jccColumn = $jccMapping[$slug] ?? null;

        if ($jccColumn) {
            return round((float) \App\Models\JobCostCard::where('company_setting_id', $this->settings->id)
                ->where('year', $year)
                ->sum($jccColumn), 2);
        }

        // Fallback: check if slug matches a dynamic allocation in job_cost_card_allocations
        $allocationTotal = \App\Models\JobCostCardAllocation::query()
            ->where('slug', str_replace('-', '_', $slug))
            ->whereHas('jobCostCard', function ($q) use ($year) {
                $q->where('company_setting_id', $this->settings->id)
                    ->where('year', $year);
            })
            ->sum('amount');

        if ((float) $allocationTotal > 0) {
            return round((float) $allocationTotal, 2);
        }

        return null;
    }

    /**
     * Per-category expense actual from journal entries using the budget-account mapping.
     * Each budget category pulls actuals only from its mapped GL accounts.
     */
    protected function getCategoryExpenseActual(BudgetCategory $category, Carbon $from, Carbon $to): ?float
    {
        $mode = $this->accountingService->getAccountingMode();
        $column = $mode === 'accrual' ? 'recognition_date' : 'transaction_date';

        // Get account IDs mapped to this specific budget category
        $accountIds = Account::where('company_setting_id', $this->settings->id)
            ->where('budget_category_id', $category->id)
            ->where('is_active', true)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return null;
        }

        $total = JournalEntryLine::whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', function ($q) use ($column, $from, $to) {
                $q->where('status', JournalEntry::STATUS_POSTED)
                    ->whereNotNull($column)
                    ->whereBetween($column, [$from, $to]);
            })
            ->sum('debit_amount');

        return round((float) $total, 2);
    }

    /**
     * Marketing expense actual from marketing tracking entries.
     */
    protected function getMarketingExpenseActual(int $year): float
    {
        return round((float) MarketingTrackingEntry::where('company_setting_id', $this->settings->id)
            ->where('year', $year)
            ->sum('marketing_expense'), 2);
    }

    /**
     * Get the sync source label for a category.
     */
    protected function getSyncSource(BudgetCategory $category): string
    {
        return match ($category->section) {
            BudgetCategory::SECTION_REVENUE => 'journal_entries',
            BudgetCategory::SECTION_VARIABLE_EXPENSE => 'job_cost_cards',
            BudgetCategory::SECTION_VEHICLE_EXPENSE => 'journal_entries',
            BudgetCategory::SECTION_BUSINESS_EXPENSE => 'journal_entries',
            BudgetCategory::SECTION_MARKETING_EXPENSE => 'marketing_tracking',
            default => 'manual',
        };
    }
}
