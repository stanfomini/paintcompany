<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

class BudgetAccountMappingSeeder extends Seeder
{
    /**
     * Map budget category slugs to GL account codes.
     * Each entry: 'budget-category-slug' => 'account-code'
     */
    public function run(): void
    {
        $settings = CompanySetting::getInstance();

        // Ensure budget categories and chart of accounts exist
        $this->call([
            BudgetCategorySeeder::class,
            ChartOfAccountsSeeder::class,
        ]);

        // Create any missing sub-accounts needed for budget categories
        $this->createMissingAccounts($settings);

        // Map budget categories to accounts
        foreach (self::defaultMappings() as $slug => $accountCode) {
            $category = BudgetCategory::where('company_setting_id', $settings->id)
                ->where('slug', $slug)
                ->first();

            $account = Account::where('company_setting_id', $settings->id)
                ->where('code', $accountCode)
                ->first();

            if ($category && $account) {
                $account->update(['budget_category_id' => $category->id]);
            }
        }
    }

    /**
     * Create sub-accounts that don't exist yet but are needed for complete mapping.
     */
    protected function createMissingAccounts(CompanySetting $settings): void
    {
        $operatingParent = Account::where('company_setting_id', $settings->id)
            ->where('code', '6000')
            ->first();

        $newAccounts = [
            ['code' => '6430', 'name' => 'Vehicle Parking', 'sort_order' => 153, 'parent_code' => '6000'],
            ['code' => '6520', 'name' => 'Meals & Entertainment', 'sort_order' => 157, 'parent_code' => '6000'],
            ['code' => '6530', 'name' => 'Bank Charges & Fees', 'sort_order' => 158, 'parent_code' => '6000'],
            ['code' => '6540', 'name' => 'Small Equipment & Tools', 'sort_order' => 159, 'parent_code' => '6000'],
            ['code' => '6550', 'name' => 'Gifts & Client Appreciation', 'sort_order' => 160, 'parent_code' => '6000'],
            ['code' => '6710', 'name' => 'Recruitment & Hiring', 'sort_order' => 166, 'parent_code' => '6000'],
            ['code' => '6810', 'name' => 'Travel Expenses', 'sort_order' => 171, 'parent_code' => '6000'],
            ['code' => '6820', 'name' => 'Repairs & Maintenance (Office)', 'sort_order' => 172, 'parent_code' => '6000'],
            ['code' => '6830', 'name' => 'Charitable Contributions', 'sort_order' => 173, 'parent_code' => '6000'],
            ['code' => '6840', 'name' => 'Penalties & Fines', 'sort_order' => 174, 'parent_code' => '6000'],
            ['code' => '6850', 'name' => 'Team Culture & Events', 'sort_order' => 175, 'parent_code' => '6000'],
            ['code' => '6030', 'name' => 'Payroll Tax Expense', 'sort_order' => 133, 'parent_code' => '6000'],
            ['code' => '6040', 'name' => 'Health Care Benefits', 'sort_order' => 134, 'parent_code' => '6000'],
            ['code' => '6050', 'name' => 'Payment Processing Fees', 'sort_order' => 137, 'parent_code' => '6000'],
        ];

        foreach ($newAccounts as $acctData) {
            $parentCode = $acctData['parent_code'];
            unset($acctData['parent_code']);

            $parent = Account::where('company_setting_id', $settings->id)
                ->where('code', $parentCode)
                ->first();

            Account::firstOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'code' => $acctData['code'],
                ],
                array_merge($acctData, [
                    'company_setting_id' => $settings->id,
                    'type' => Account::TYPE_EXPENSE,
                    'normal_balance' => Account::NORMAL_DEBIT,
                    'is_system' => true,
                    'is_active' => true,
                    'parent_id' => $parent?->id,
                ])
            );
        }
    }

    /**
     * Default mappings: budget category slug => GL account code.
     *
     * @return array<string, string>
     */
    public static function defaultMappings(): array
    {
        return [
            // --- Vehicle Expenses ---
            'lease' => '6400',
            'fuel' => '6410',
            'insurance' => '6220',
            'repair-maintenance' => '6420',
            'parking' => '6430',

            // --- Business Expenses ---
            'depreciation' => '6900',
            'management-wages' => '6010',
            'office-manager' => '6020',
            'accounting' => '6610',
            'marketing-manager' => '6300',
            'business-development' => '6310',
            'production-wages' => '7030',
            'payroll-tax' => '6030',
            'health-care' => '6040',
            'cell-phone' => '6510',
            'bank-charges' => '6530',
            'payment-processing' => '6050',
            'crm-software' => '6500',
            'office-supplies' => '6500',
            'liability-insurance' => '6200',
            'professional-fees' => '6600',
            'licenses' => '6800',
            'meals' => '6520',
            'entertainment' => '6520',
            'recruitment' => '6710',
            'training' => '6700',
            'misc-damages' => '6950',
            'small-equipment' => '6540',
            'travel' => '6810',
            'rent' => '6100',
            'repairs' => '6820',
            'utilities' => '6110',
            'telephone' => '6510',
            'charitable-contributions' => '6830',
            'penalties' => '6840',
            'team-culture' => '6850',
            'gifts' => '6550',
            'cto' => '6990',
            'other' => '6990',

            // --- Variable Expenses ---
            'wc-insurance' => '6210',
            'materials' => '5030',
            'production-management-labor' => '5010',
            'production-mgmt-labor' => '5010',
            'subcontractors' => '5050',
            'sales-commissions' => '7010',
            'credit-card-processing-fee' => '6050',
            'agent-referral-commission' => '7020',
            'exterior-equipment-reserve' => '5060',
            'interior-protection-supplies' => '5040',
            'spray-equipment-materials' => '5040',
        ];
    }
}
