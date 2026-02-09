<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\OverheadEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\BudgetSyncService;
use App\Services\JournalEntryService;
use Database\Seeders\BudgetAccountMappingSeeder;
use Database\Seeders\SoloStartupPresetSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'settings.company', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'vendors.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'vendors.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

// --- Per-Category Budget Sync Tests ---

it('syncs vehicle expense per category from mapped accounts', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    // Create two vehicle expense categories
    $fuelCategory = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Fuel',
        'slug' => 'fuel',
    ]);

    $maintenanceCategory = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Repair & Maintenance',
        'slug' => 'repair-maintenance',
    ]);

    // Create accounts mapped to specific categories
    $fuelAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6410',
        'name' => 'Fuel',
        'budget_category_id' => $fuelCategory->id,
    ]);

    $maintenanceAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6420',
        'name' => 'Vehicle Maintenance',
        'budget_category_id' => $maintenanceCategory->id,
    ]);

    // Create journal entries for each account
    $entry = JournalEntry::factory()->posted()->create([
        'company_setting_id' => $settings->id,
        'transaction_date' => now(),
        'recognition_date' => now(),
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $fuelAccount->id,
        'debit_amount' => 500,
        'credit_amount' => 0,
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $maintenanceAccount->id,
        'debit_amount' => 200,
        'credit_amount' => 0,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    // Each category gets its own total (not the combined total)
    expect($result['categories']['Fuel'])->toBe(500.00)
        ->and($result['categories']['Repair & Maintenance'])->toBe(200.00);
});

it('syncs business expense per category from mapped accounts', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $phoneCategory = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Cell Phone',
        'slug' => 'cell-phone',
    ]);

    $accountingCategory = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Accounting',
        'slug' => 'accounting',
    ]);

    $phoneAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6510',
        'name' => 'Phone & Internet',
        'budget_category_id' => $phoneCategory->id,
    ]);

    $accountingAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6610',
        'name' => 'Accounting & Bookkeeping',
        'budget_category_id' => $accountingCategory->id,
    ]);

    $entry = JournalEntry::factory()->posted()->create([
        'company_setting_id' => $settings->id,
        'transaction_date' => now(),
        'recognition_date' => now(),
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $phoneAccount->id,
        'debit_amount' => 100,
        'credit_amount' => 0,
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $accountingAccount->id,
        'debit_amount' => 42,
        'credit_amount' => 0,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['categories']['Cell Phone'])->toBe(100.00)
        ->and($result['categories']['Accounting'])->toBe(42.00);
});

it('returns null for categories without mapped accounts', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    // Category with no mapped account
    BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Unmapped Category',
        'slug' => 'unmapped-category',
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['synced'])->toBe(0);
});

it('supports multiple accounts mapped to same category', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $phoneCategory = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Cell Phone',
        'slug' => 'cell-phone',
    ]);

    // Two accounts mapped to same category
    $phone = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6510',
        'budget_category_id' => $phoneCategory->id,
    ]);

    $internet = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6511',
        'name' => 'Internet Service',
        'budget_category_id' => $phoneCategory->id,
    ]);

    $entry = JournalEntry::factory()->posted()->create([
        'company_setting_id' => $settings->id,
        'transaction_date' => now(),
        'recognition_date' => now(),
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $phone->id,
        'debit_amount' => 100,
        'credit_amount' => 0,
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $internet->id,
        'debit_amount' => 50,
        'credit_amount' => 0,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    // Should sum both accounts
    expect($result['categories']['Cell Phone'])->toBe(150.00);
});

// --- JournalEntryService Overhead Mapping Tests ---

it('maps overhead entry to specific GL account via budget category', function () {
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Cell Phone',
        'slug' => 'cell-phone',
    ]);

    $phoneAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6510',
        'name' => 'Phone & Internet',
        'budget_category_id' => $category->id,
    ]);

    Account::factory()->asset()->create([
        'company_setting_id' => $settings->id,
        'code' => '1000',
        'name' => 'Cash & Bank',
    ]);

    $overheadEntry = OverheadEntry::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
        'year' => now()->year,
        'month' => 1,
        'amount' => 100,
    ]);

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $service = new JournalEntryService;
    $journalEntry = $service->createFromOverheadEntry($overheadEntry);

    expect($journalEntry)->not->toBeNull();

    // The debit line should go to account 6510, not the generic 7000
    $debitLine = $journalEntry->lines()->where('debit_amount', '>', 0)->first();
    expect($debitLine->account_id)->toBe($phoneAccount->id);
});

it('falls back to section default when no mapping exists', function () {
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Parking',
        'slug' => 'parking',
    ]);

    // No mapped account for this category

    $fallbackAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6400',
        'name' => 'Vehicle Expenses',
    ]);

    Account::factory()->asset()->create([
        'company_setting_id' => $settings->id,
        'code' => '1000',
        'name' => 'Cash & Bank',
    ]);

    $overheadEntry = OverheadEntry::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
        'year' => now()->year,
        'month' => 1,
        'amount' => 25,
    ]);

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $service = new JournalEntryService;
    $journalEntry = $service->createFromOverheadEntry($overheadEntry);

    $debitLine = $journalEntry->lines()->where('debit_amount', '>', 0)->first();
    expect($debitLine->account_id)->toBe($fallbackAccount->id);
});

// --- Model Relationship Tests ---

it('account belongs to budget category', function () {
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
    ]);

    $account = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
    ]);

    expect($account->budgetCategory->id)->toBe($category->id);
});

it('budget category has many accounts', function () {
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
    ]);

    Account::factory()->expense()->count(3)->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
    ]);

    expect($category->accounts)->toHaveCount(3);
});

it('vendor belongs to budget category', function () {
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
    ]);

    $vendor = Vendor::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
    ]);

    expect($vendor->budgetCategory->id)->toBe($category->id);
});

it('budget category has many vendors', function () {
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->variableExpense()->create([
        'company_setting_id' => $settings->id,
    ]);

    Vendor::factory()->count(2)->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
    ]);

    expect($category->vendors)->toHaveCount(2);
});

// --- Settings UI Tests ---

it('renders budget mappings page for authorized users', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.budget-mappings')
        ->assertSuccessful()
        ->assertSee('Budget Mappings');
});

it('prevents unauthorized access to budget mappings', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.budget-mappings')
        ->assertForbidden();
});

it('can update a budget-account mapping from settings page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Fuel',
        'slug' => 'fuel',
    ]);

    $account = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6410',
        'name' => 'Fuel Expense',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.budget-mappings')
        ->call('updateMapping', $category->id, $account->id);

    expect($account->fresh()->budget_category_id)->toBe($category->id);
});

it('can clear a budget-account mapping', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
    ]);

    $account = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.budget-mappings')
        ->call('updateMapping', $category->id, null);

    expect($account->fresh()->budget_category_id)->toBeNull();
});

// --- Vendor Budget Category Tests ---

it('can set budget category on vendor', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'CRM & Software',
        'slug' => 'crm-software',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openCreateModal')
        ->set('vendorName', 'Apex CRM')
        ->set('vendorCategory', 'service')
        ->set('vendorBudgetCategoryId', (string) $category->id)
        ->call('saveVendor');

    $vendor = Vendor::where('name', 'Apex CRM')->first();
    expect($vendor)->not->toBeNull()
        ->and($vendor->budget_category_id)->toBe($category->id);
});

// --- Seeder Tests ---

it('budget account mapping seeder creates mappings', function () {
    $settings = CompanySetting::getInstance();

    $this->seed(BudgetAccountMappingSeeder::class);

    // Verify that accounts now have budget_category_id set
    $mappedCount = Account::where('company_setting_id', $settings->id)
        ->whereNotNull('budget_category_id')
        ->count();

    expect($mappedCount)->toBeGreaterThan(15);

    // Verify specific mapping: Fuel account → Fuel category
    $fuelAccount = Account::where('company_setting_id', $settings->id)
        ->where('code', '6410')
        ->first();

    expect($fuelAccount)->not->toBeNull()
        ->and($fuelAccount->budgetCategory)->not->toBeNull()
        ->and($fuelAccount->budgetCategory->slug)->toBe('fuel');
});

it('solo startup preset seeder creates budget plan values', function () {
    $settings = CompanySetting::getInstance();

    $this->seed(SoloStartupPresetSeeder::class);

    // Check revenue plan
    $revenueCategory = BudgetCategory::where('company_setting_id', $settings->id)
        ->where('slug', 'cumulative-revenue')
        ->first();

    $revenueLine = BudgetLineItem::where('budget_category_id', $revenueCategory->id)
        ->where('year', 2026)
        ->first();

    expect($revenueLine)->not->toBeNull()
        ->and((float) $revenueLine->current_year_plan)->toBe(65000.00);

    // Check fuel plan
    $fuelCategory = BudgetCategory::where('company_setting_id', $settings->id)
        ->where('slug', 'fuel')
        ->first();

    $fuelLine = BudgetLineItem::where('budget_category_id', $fuelCategory->id)
        ->where('year', 2026)
        ->first();

    expect($fuelLine)->not->toBeNull()
        ->and((float) $fuelLine->current_year_plan)->toBe(2400.00);
});

it('solo startup preset seeder creates overhead entries', function () {
    $settings = CompanySetting::getInstance();

    $this->seed(SoloStartupPresetSeeder::class);

    // Check fuel overhead entries exist for 12 months
    $fuelCategory = BudgetCategory::where('company_setting_id', $settings->id)
        ->where('slug', 'fuel')
        ->first();

    $fuelEntries = OverheadEntry::where('budget_category_id', $fuelCategory->id)
        ->where('year', 2026)
        ->orderBy('month')
        ->get();

    expect($fuelEntries)->toHaveCount(12)
        ->and((float) $fuelEntries->first()->amount)->toBe(150.00)  // Jan
        ->and((float) $fuelEntries->where('month', 5)->first()->amount)->toBe(225.00); // May peak
});

it('solo startup preset seeder creates marketing tracking entries', function () {
    $settings = CompanySetting::getInstance();

    $this->seed(SoloStartupPresetSeeder::class);

    $entries = \App\Models\MarketingTrackingEntry::where('company_setting_id', $settings->id)
        ->where('year', 2026)
        ->get();

    expect($entries)->toHaveCount(7);

    // Verify networking is highest sales
    $networking = $entries->where('source_name', 'Networking & Associations')->first();
    expect($networking)->not->toBeNull()
        ->and((float) $networking->total_sales)->toBe(27000.00)
        ->and($networking->jobs_booked)->toBe(9);

    // Verify totals
    expect((float) $entries->sum('total_sales'))->toBe(65000.00)
        ->and($entries->sum('jobs_booked'))->toBe(23);
});
