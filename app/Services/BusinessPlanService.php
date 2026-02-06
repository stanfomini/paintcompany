<?php

namespace App\Services;

use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;

class BusinessPlanService
{
    /**
     * Get the structured P&L budget for a given year.
     *
     * @return array{sections: array, computed: array}
     */
    public function getBudgetForYear(int $year): array
    {
        $settings = CompanySetting::getInstance();

        $categories = BudgetCategory::where('company_setting_id', $settings->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $lineItems = BudgetLineItem::where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->get()
            ->keyBy('budget_category_id');

        $sections = [];

        foreach (BudgetCategory::SECTIONS as $section) {
            $sectionCategories = $categories->where('section', $section);
            $rows = [];
            $sectionTotals = ['prior_year_actual' => 0, 'current_year_plan' => 0, 'current_year_actual' => 0];

            foreach ($sectionCategories as $category) {
                $item = $lineItems->get($category->id);
                $prior = (float) ($item?->prior_year_actual ?? 0);
                $plan = (float) ($item?->current_year_plan ?? 0);
                $actual = (float) ($item?->current_year_actual ?? 0);

                $rows[] = [
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'line_item_id' => $item?->id,
                    'prior_year_actual' => $prior,
                    'current_year_plan' => $plan,
                    'current_year_actual' => $actual,
                    'notes' => $item?->notes,
                ];

                $sectionTotals['prior_year_actual'] += $prior;
                $sectionTotals['current_year_plan'] += $plan;
                $sectionTotals['current_year_actual'] += $actual;
            }

            $sections[$section] = [
                'label' => BudgetCategory::SECTION_LABELS[$section] ?? $section,
                'rows' => $rows,
                'totals' => $sectionTotals,
            ];
        }

        $revenue = $sections[BudgetCategory::SECTION_REVENUE]['totals'] ?? ['prior_year_actual' => 0, 'current_year_plan' => 0, 'current_year_actual' => 0];
        $variable = $sections[BudgetCategory::SECTION_VARIABLE_EXPENSE]['totals'] ?? ['prior_year_actual' => 0, 'current_year_plan' => 0, 'current_year_actual' => 0];
        $vehicle = $sections[BudgetCategory::SECTION_VEHICLE_EXPENSE]['totals'] ?? ['prior_year_actual' => 0, 'current_year_plan' => 0, 'current_year_actual' => 0];
        $business = $sections[BudgetCategory::SECTION_BUSINESS_EXPENSE]['totals'] ?? ['prior_year_actual' => 0, 'current_year_plan' => 0, 'current_year_actual' => 0];
        $marketing = $sections[BudgetCategory::SECTION_MARKETING_EXPENSE]['totals'] ?? ['prior_year_actual' => 0, 'current_year_plan' => 0, 'current_year_actual' => 0];

        $grossProfit = [
            'prior_year_actual' => $revenue['prior_year_actual'] - $variable['prior_year_actual'],
            'current_year_plan' => $revenue['current_year_plan'] - $variable['current_year_plan'],
            'current_year_actual' => $revenue['current_year_actual'] - $variable['current_year_actual'],
        ];

        $totalFixed = [
            'prior_year_actual' => $vehicle['prior_year_actual'] + $business['prior_year_actual'] + $marketing['prior_year_actual'],
            'current_year_plan' => $vehicle['current_year_plan'] + $business['current_year_plan'] + $marketing['current_year_plan'],
            'current_year_actual' => $vehicle['current_year_actual'] + $business['current_year_actual'] + $marketing['current_year_actual'],
        ];

        $netProfit = [
            'prior_year_actual' => $grossProfit['prior_year_actual'] - $totalFixed['prior_year_actual'],
            'current_year_plan' => $grossProfit['current_year_plan'] - $totalFixed['current_year_plan'],
            'current_year_actual' => $grossProfit['current_year_actual'] - $totalFixed['current_year_actual'],
        ];

        return [
            'sections' => $sections,
            'computed' => [
                'gross_profit' => $grossProfit,
                'total_fixed' => $totalFixed,
                'net_profit' => $netProfit,
            ],
            'revenue' => $revenue,
        ];
    }

    /**
     * Calculate the percentage of revenue for a given amount.
     */
    public function percentOfRevenue(float $amount, float $revenue): float
    {
        if ($revenue <= 0) {
            return 0;
        }

        return round(($amount / $revenue) * 100, 1);
    }

    /**
     * Export budget data as CSV for a given year.
     */
    public function exportToCsv(int $year): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $this->getBudgetForYear($year);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Section',
                'Category',
                'Prior Year Actual',
                'Current Year Plan',
                'Current Year Actual',
                '% of Revenue (Prior)',
                '% of Revenue (Plan)',
                '% of Revenue (Actual)',
            ]);

            $revPrior = $data['revenue']['prior_year_actual'];
            $revPlan = $data['revenue']['current_year_plan'];
            $revActual = $data['revenue']['current_year_actual'];

            foreach ($data['sections'] as $sectionKey => $section) {
                foreach ($section['rows'] as $row) {
                    fputcsv($handle, [
                        $section['label'],
                        $row['name'],
                        $row['prior_year_actual'],
                        $row['current_year_plan'],
                        $row['current_year_actual'],
                        $this->percentOfRevenue($row['prior_year_actual'], $revPrior).'%',
                        $this->percentOfRevenue($row['current_year_plan'], $revPlan).'%',
                        $this->percentOfRevenue($row['current_year_actual'], $revActual).'%',
                    ]);
                }

                // Section total
                fputcsv($handle, [
                    $section['label'],
                    'TOTAL '.strtoupper($section['label']),
                    $section['totals']['prior_year_actual'],
                    $section['totals']['current_year_plan'],
                    $section['totals']['current_year_actual'],
                    $this->percentOfRevenue($section['totals']['prior_year_actual'], $revPrior).'%',
                    $this->percentOfRevenue($section['totals']['current_year_plan'], $revPlan).'%',
                    $this->percentOfRevenue($section['totals']['current_year_actual'], $revActual).'%',
                ]);
            }

            // Computed rows
            foreach (['gross_profit' => 'GROSS PROFIT', 'total_fixed' => 'TOTAL FIXED EXPENSES', 'net_profit' => 'NET PROFIT'] as $key => $label) {
                $computed = $data['computed'][$key];
                fputcsv($handle, [
                    'Computed',
                    $label,
                    $computed['prior_year_actual'],
                    $computed['current_year_plan'],
                    $computed['current_year_actual'],
                    $this->percentOfRevenue($computed['prior_year_actual'], $revPrior).'%',
                    $this->percentOfRevenue($computed['current_year_plan'], $revPlan).'%',
                    $this->percentOfRevenue($computed['current_year_actual'], $revActual).'%',
                ]);
            }

            fclose($handle);
        }, "budget-{$year}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }
}
