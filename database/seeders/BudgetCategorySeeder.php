<?php

namespace Database\Seeders;

use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use App\Models\MarketingTrackingEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BudgetCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = CompanySetting::getInstance();

        foreach (self::defaultCategories() as $section => $categories) {
            foreach ($categories as $index => $name) {
                BudgetCategory::firstOrCreate(
                    [
                        'company_setting_id' => $settings->id,
                        'slug' => Str::slug($name),
                    ],
                    [
                        'section' => $section,
                        'name' => $name,
                        'sort_order' => $index,
                        'is_system' => true,
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function defaultCategories(): array
    {
        return [
            BudgetCategory::SECTION_REVENUE => [
                'Cumulative Revenue',
            ],
            BudgetCategory::SECTION_VARIABLE_EXPENSE => [
                'Production Mgmt Labor',
                'Subcontractors',
                'W/C & Insurance',
                'Materials',
                'Sales Commissions',
                'Credit Card Processing Fee',
                'Agent Referral Commission',
                'Exterior Equipment Reserve',
                'Interior Protection & Supplies',
                'Spray Equipment & Materials',
            ],
            BudgetCategory::SECTION_VEHICLE_EXPENSE => [
                'Lease',
                'Fuel',
                'Insurance',
                'Repair & Maintenance',
                'Parking',
            ],
            BudgetCategory::SECTION_BUSINESS_EXPENSE => [
                'Depreciation',
                'Management Wages',
                'Office Manager',
                'Accounting',
                'Marketing Manager',
                'Business Development',
                'Production Wages',
                'Payroll Tax',
                'Health Care',
                'Cell Phone',
                'Bank Charges',
                'Payment Processing',
                'CRM & Software',
                'Office Supplies',
                'Liability Insurance',
                'Professional Fees',
                'Licenses',
                'Meals',
                'Entertainment',
                'Recruitment',
                'Training',
                'Misc Damages',
                'Small Equipment',
                'Travel',
                'Rent',
                'Repairs',
                'Utilities',
                'Telephone',
                'Charitable Contributions',
                'Penalties',
                'Team Culture',
                'Gifts',
                'CTO',
                'Other',
            ],
            BudgetCategory::SECTION_MARKETING_EXPENSE => MarketingTrackingEntry::defaultSources(),
        ];
    }
}
