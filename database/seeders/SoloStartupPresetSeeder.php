<?php

namespace Database\Seeders;

use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Models\MarketingTrackingEntry;
use App\Models\OverheadEntry;
use Illuminate\Database\Seeder;

class SoloStartupPresetSeeder extends Seeder
{
    /**
     * Seed realistic Year 1 solo painter startup values.
     * Revenue: $65K, Net Profit: ~$37.8K (58%)
     */
    public function run(): void
    {
        // Ensure dependencies are seeded
        $this->call([
            BudgetCategorySeeder::class,
            ChartOfAccountsSeeder::class,
            BudgetAccountMappingSeeder::class,
        ]);

        $settings = CompanySetting::getInstance();
        $year = 2026;

        $this->seedBudgetLineItems($settings, $year);
        $this->seedOverheadEntries($settings, $year);
        $this->seedMarketingTracking($settings, $year);
    }

    protected function seedBudgetLineItems(CompanySetting $settings, int $year): void
    {
        $plans = [
            // Revenue
            BudgetCategory::SECTION_REVENUE => [
                'cumulative-revenue' => 65000,
            ],

            // Variable Expenses: $14,700
            BudgetCategory::SECTION_VARIABLE_EXPENSE => [
                'production-mgmt-labor' => 0,
                'subcontractors' => 0,
                'wc-insurance' => 2400,
                'materials' => 9750,
                'sales-commissions' => 0,
                'credit-card-processing-fee' => 950,
                'agent-referral-commission' => 0,
                'exterior-equipment-reserve' => 300,
                'interior-protection-supplies' => 1300,
                'spray-equipment-materials' => 0,
            ],

            // Vehicle Expenses: $3,000
            BudgetCategory::SECTION_VEHICLE_EXPENSE => [
                'lease' => 0,
                'fuel' => 2400,
                'insurance' => 0,
                'repair-maintenance' => 600,
                'parking' => 0,
            ],

            // Business Expenses: $6,920
            BudgetCategory::SECTION_BUSINESS_EXPENSE => [
                'depreciation' => 0,
                'management-wages' => 0,
                'office-manager' => 0,
                'accounting' => 500,
                'marketing-manager' => 0,
                'business-development' => 0,
                'production-wages' => 0,
                'payroll-tax' => 0,
                'health-care' => 0,
                'cell-phone' => 1200,
                'bank-charges' => 120,
                'payment-processing' => 0,
                'crm-software' => 1800,
                'office-supplies' => 100,
                'liability-insurance' => 0,
                'professional-fees' => 300,
                'licenses' => 200,
                'meals' => 600,
                'entertainment' => 0,
                'recruitment' => 0,
                'training' => 100,
                'misc-damages' => 0,
                'small-equipment' => 1500,
                'travel' => 0,
                'rent' => 0,
                'repairs' => 0,
                'utilities' => 0,
                'telephone' => 0,
                'charitable-contributions' => 0,
                'penalties' => 0,
                'team-culture' => 0,
                'gifts' => 200,
                'cto' => 0,
                'other' => 300,
            ],
        ];

        foreach ($plans as $section => $categories) {
            foreach ($categories as $slug => $planAmount) {
                $category = BudgetCategory::where('company_setting_id', $settings->id)
                    ->where('slug', $slug)
                    ->first();

                if (! $category) {
                    continue;
                }

                BudgetLineItem::updateOrCreate(
                    [
                        'company_setting_id' => $settings->id,
                        'budget_category_id' => $category->id,
                        'year' => $year,
                    ],
                    [
                        'current_year_plan' => $planAmount,
                        'prior_year_actual' => 0,
                        'current_year_actual' => 0,
                    ]
                );
            }
        }
    }

    protected function seedOverheadEntries(CompanySetting $settings, int $year): void
    {
        // Monthly overhead: slug => [jan, feb, mar, apr, may, jun, jul, aug, sep, oct, nov, dec]
        $monthly = [
            'fuel' => [150, 150, 175, 200, 225, 225, 225, 225, 225, 200, 200, 150],
            'repair-maintenance' => [50, 50, 50, 50, 50, 50, 50, 50, 50, 50, 50, 50],
            'accounting' => [42, 42, 42, 42, 42, 42, 42, 42, 42, 42, 42, 38],
            'cell-phone' => [100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100],
            'bank-charges' => [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
            'crm-software' => [150, 150, 150, 150, 150, 150, 150, 150, 150, 150, 150, 150],
            'professional-fees' => [0, 0, 0, 300, 0, 0, 0, 0, 0, 0, 0, 0],
            'licenses' => [200, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            'meals' => [50, 50, 50, 50, 50, 50, 50, 50, 50, 50, 50, 50],
            'training' => [50, 0, 0, 0, 0, 50, 0, 0, 0, 0, 0, 0],
            'small-equipment' => [750, 250, 100, 50, 50, 50, 50, 50, 50, 25, 25, 0],
            'office-supplies' => [50, 0, 0, 0, 25, 0, 0, 0, 25, 0, 0, 0],
            'gifts' => [0, 0, 0, 25, 0, 25, 0, 25, 0, 25, 50, 50],
            'other' => [25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25, 25],
        ];

        foreach ($monthly as $slug => $months) {
            $category = BudgetCategory::where('company_setting_id', $settings->id)
                ->where('slug', $slug)
                ->first();

            if (! $category) {
                continue;
            }

            foreach ($months as $monthIndex => $amount) {
                if ($amount <= 0) {
                    continue;
                }

                OverheadEntry::updateOrCreate(
                    [
                        'company_setting_id' => $settings->id,
                        'budget_category_id' => $category->id,
                        'year' => $year,
                        'month' => $monthIndex + 1,
                    ],
                    [
                        'amount' => $amount,
                    ]
                );
            }
        }
    }

    protected function seedMarketingTracking(CompanySetting $settings, int $year): void
    {
        $sources = [
            [
                'source_name' => 'Community Marketing',
                'marketing_expense' => 300,
                'leads' => 40,
                'estimates' => 15,
                'jobs_booked' => 6,
                'total_sales' => 16500,
                'notes' => 'Door-to-door, door hangers',
                'sort_order' => 1,
            ],
            [
                'source_name' => 'Networking & Associations',
                'marketing_expense' => 200,
                'leads' => 25,
                'estimates' => 18,
                'jobs_booked' => 9,
                'total_sales' => 27000,
                'notes' => 'Referrals & word-of-mouth',
                'sort_order' => 2,
            ],
            [
                'source_name' => 'Craft Jack',
                'marketing_expense' => 1200,
                'leads' => 18,
                'estimates' => 7,
                'jobs_booked' => 3,
                'total_sales' => 8000,
                'notes' => 'Paid lead service',
                'sort_order' => 3,
            ],
            [
                'source_name' => 'Facebook',
                'marketing_expense' => 600,
                'leads' => 12,
                'estimates' => 5,
                'jobs_booked' => 2,
                'total_sales' => 5500,
                'notes' => 'Meta ads',
                'sort_order' => 4,
            ],
            [
                'source_name' => 'Business Cards',
                'marketing_expense' => 100,
                'leads' => 5,
                'estimates' => 2,
                'jobs_booked' => 1,
                'total_sales' => 2500,
                'notes' => 'Networking handouts',
                'sort_order' => 5,
            ],
            [
                'source_name' => 'Signs',
                'marketing_expense' => 200,
                'leads' => 4,
                'estimates' => 2,
                'jobs_booked' => 1,
                'total_sales' => 3000,
                'notes' => 'Yard signs after jobs',
                'sort_order' => 6,
            ],
            [
                'source_name' => 'Website & SEO',
                'marketing_expense' => 0,
                'leads' => 5,
                'estimates' => 3,
                'jobs_booked' => 1,
                'total_sales' => 2500,
                'notes' => 'Google My Business (free)',
                'sort_order' => 7,
            ],
        ];

        foreach ($sources as $source) {
            MarketingTrackingEntry::updateOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'year' => $year,
                    'source_name' => $source['source_name'],
                ],
                array_merge($source, [
                    'company_setting_id' => $settings->id,
                    'year' => $year,
                    'is_manual' => false,
                ])
            );
        }
    }
}
