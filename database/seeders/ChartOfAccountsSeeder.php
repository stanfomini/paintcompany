<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = CompanySetting::getInstance();

        $accounts = [
            // === ASSETS (1000s) ===
            ['code' => '1000', 'name' => 'Cash & Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 10],
            ['code' => '1010', 'name' => 'Business Checking', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 11, 'parent_code' => '1000'],
            ['code' => '1020', 'name' => 'Business Savings', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 12, 'parent_code' => '1000'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 20],
            ['code' => '1200', 'name' => 'Undeposited Funds', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 25],
            ['code' => '1300', 'name' => 'Inventory - Paint & Materials', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 30],
            ['code' => '1400', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 35],
            ['code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 40],
            ['code' => '1510', 'name' => 'Vehicles', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 41, 'parent_code' => '1500'],
            ['code' => '1520', 'name' => 'Paint Equipment & Tools', 'type' => 'asset', 'normal_balance' => 'debit', 'sort_order' => 42, 'parent_code' => '1500'],
            ['code' => '1590', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'normal_balance' => 'credit', 'sort_order' => 49],

            // === LIABILITIES (2000s) ===
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 50],
            ['code' => '2100', 'name' => 'Credit Cards Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 55],
            ['code' => '2200', 'name' => 'Customer Deposits', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 60],
            ['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 65],
            ['code' => '2400', 'name' => 'Payroll Liabilities', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 70],
            ['code' => '2410', 'name' => 'Federal Withholding Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 71, 'parent_code' => '2400'],
            ['code' => '2420', 'name' => 'State Withholding Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 72, 'parent_code' => '2400'],
            ['code' => '2430', 'name' => 'FICA Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 73, 'parent_code' => '2400'],
            ['code' => '2500', 'name' => 'Workers Comp Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 75],
            ['code' => '2600', 'name' => 'Warranty Reserve', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 80],
            ['code' => '2700', 'name' => 'Loans Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 85],
            ['code' => '2800', 'name' => 'Income Tax Reserve', 'type' => 'liability', 'normal_balance' => 'credit', 'sort_order' => 86],

            // === EQUITY (3000s) ===
            ['code' => '3000', 'name' => 'Owner\'s Equity', 'type' => 'equity', 'normal_balance' => 'credit', 'sort_order' => 90],
            ['code' => '3100', 'name' => 'Owner\'s Draw', 'type' => 'equity', 'normal_balance' => 'debit', 'sort_order' => 91],
            ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity', 'normal_balance' => 'credit', 'sort_order' => 95],
            ['code' => '3300', 'name' => 'Profit Reserve', 'type' => 'equity', 'normal_balance' => 'credit', 'sort_order' => 96],

            // === REVENUE (4000s) ===
            ['code' => '4000', 'name' => 'Painting Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'sort_order' => 100],
            ['code' => '4010', 'name' => 'Interior Painting Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'sort_order' => 101, 'parent_code' => '4000'],
            ['code' => '4020', 'name' => 'Exterior Painting Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'sort_order' => 102, 'parent_code' => '4000'],
            ['code' => '4100', 'name' => 'Specialty Services Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'sort_order' => 105],
            ['code' => '4200', 'name' => 'Materials Markup Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'sort_order' => 110],
            ['code' => '4900', 'name' => 'Other Income', 'type' => 'revenue', 'normal_balance' => 'credit', 'sort_order' => 115],

            // === COST OF GOODS SOLD (5000s) ===
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 120],
            ['code' => '5010', 'name' => 'Painter Labor (COGS)', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 121, 'parent_code' => '5000'],
            ['code' => '5020', 'name' => 'Payroll Burden (COGS)', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 122, 'parent_code' => '5000'],
            ['code' => '5030', 'name' => 'Paint Materials (COGS)', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 123, 'parent_code' => '5000'],
            ['code' => '5040', 'name' => 'Sundries & Supplies (COGS)', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 124, 'parent_code' => '5000'],
            ['code' => '5050', 'name' => 'Subcontractor Costs', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 125, 'parent_code' => '5000'],
            ['code' => '5060', 'name' => 'Equipment Rental (Job)', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 126, 'parent_code' => '5000'],
            ['code' => '5070', 'name' => 'Cleanup & Disposal (COGS)', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 127, 'parent_code' => '5000'],

            // === OPERATING EXPENSES (6000s-7000s) ===
            ['code' => '6000', 'name' => 'Operating Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 130],
            ['code' => '6010', 'name' => 'Owner/Manager Salary', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 131, 'parent_code' => '6000'],
            ['code' => '6020', 'name' => 'Office Staff Wages', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 132, 'parent_code' => '6000'],
            ['code' => '6100', 'name' => 'Rent / Lease', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 135, 'parent_code' => '6000'],
            ['code' => '6110', 'name' => 'Utilities', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 136, 'parent_code' => '6000'],
            ['code' => '6200', 'name' => 'Insurance - General Liability', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 140, 'parent_code' => '6000'],
            ['code' => '6210', 'name' => 'Insurance - Workers Comp', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 141, 'parent_code' => '6000'],
            ['code' => '6220', 'name' => 'Insurance - Vehicle', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 142, 'parent_code' => '6000'],
            ['code' => '6300', 'name' => 'Marketing & Advertising', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 145, 'parent_code' => '6000'],
            ['code' => '6310', 'name' => 'Lead Generation', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 146, 'parent_code' => '6000'],
            ['code' => '6400', 'name' => 'Vehicle Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 150, 'parent_code' => '6000'],
            ['code' => '6410', 'name' => 'Fuel', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 151, 'parent_code' => '6000'],
            ['code' => '6420', 'name' => 'Vehicle Maintenance', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 152, 'parent_code' => '6000'],
            ['code' => '6500', 'name' => 'Office Supplies & Software', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 155, 'parent_code' => '6000'],
            ['code' => '6510', 'name' => 'Phone & Internet', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 156, 'parent_code' => '6000'],
            ['code' => '6600', 'name' => 'Professional Fees', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 160, 'parent_code' => '6000'],
            ['code' => '6610', 'name' => 'Accounting & Bookkeeping', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 161, 'parent_code' => '6000'],
            ['code' => '6620', 'name' => 'Payment Processing Fees', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 162, 'parent_code' => '6000'],
            ['code' => '6700', 'name' => 'Training & Certifications', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 165, 'parent_code' => '6000'],
            ['code' => '6800', 'name' => 'Licenses & Permits', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 170, 'parent_code' => '6000'],
            ['code' => '6900', 'name' => 'Depreciation Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 175, 'parent_code' => '6000'],
            ['code' => '6950', 'name' => 'Warranty Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 178, 'parent_code' => '6000'],
            ['code' => '6990', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 180, 'parent_code' => '6000'],

            // === SALES EXPENSES (7000s) ===
            ['code' => '7000', 'name' => 'Sales Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 185],
            ['code' => '7010', 'name' => 'Sales Commissions', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 186, 'parent_code' => '7000'],
            ['code' => '7020', 'name' => 'Referral Fees', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 187, 'parent_code' => '7000'],
            ['code' => '7030', 'name' => 'Production Management', 'type' => 'expense', 'normal_balance' => 'debit', 'sort_order' => 188, 'parent_code' => '7000'],
        ];

        // First pass: create all accounts without parent references
        $accountMap = [];
        foreach ($accounts as $accountData) {
            $parentCode = $accountData['parent_code'] ?? null;
            unset($accountData['parent_code']);

            $account = Account::firstOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'code' => $accountData['code'],
                ],
                array_merge($accountData, [
                    'company_setting_id' => $settings->id,
                    'is_system' => true,
                ])
            );

            $accountMap[$accountData['code']] = [
                'id' => $account->id,
                'parent_code' => $parentCode,
            ];
        }

        // Second pass: set parent relationships
        foreach ($accountMap as $code => $data) {
            if ($data['parent_code'] && isset($accountMap[$data['parent_code']])) {
                Account::where('company_setting_id', $settings->id)
                    ->where('code', $code)
                    ->update(['parent_id' => $accountMap[$data['parent_code']]['id']]);
            }
        }
    }
}
