<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\JobCostCard;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\MarketingTrackingEntry;
use App\Models\User;
use App\Services\BudgetSyncService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

// --- BudgetSyncService Unit Tests ---

it('syncs revenue actual from paid invoices when no journal entries exist', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $category = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Cumulative Revenue',
        'slug' => 'cumulative-revenue',
    ]);

    Invoice::factory()->paid()->create([
        'company_setting_id' => $settings->id,
        'total' => 5000,
        'paid_at' => now(),
    ]);

    Invoice::factory()->paid()->create([
        'company_setting_id' => $settings->id,
        'total' => 3000,
        'paid_at' => now(),
    ]);

    // Unpaid invoice should not be counted
    Invoice::factory()->sent()->create([
        'company_setting_id' => $settings->id,
        'total' => 10000,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['synced'])->toBe(1)
        ->and($result['categories']['Cumulative Revenue'])->toBe(8000.00);

    $item = BudgetLineItem::where('budget_category_id', $category->id)
        ->where('year', $year)
        ->first();

    expect($item)->not->toBeNull()
        ->and((float) $item->current_year_actual)->toBe(8000.00)
        ->and($item->sync_source)->toBe('journal_entries')
        ->and($item->synced_at)->not->toBeNull();
});

it('syncs revenue from journal entries when they exist', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $category = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Cumulative Revenue',
        'slug' => 'cumulative-revenue',
    ]);

    $revenueAccount = Account::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'code' => '4000',
        'name' => 'Painting Services Revenue',
    ]);

    $entry = JournalEntry::factory()->posted()->create([
        'company_setting_id' => $settings->id,
        'transaction_date' => now(),
        'recognition_date' => now(),
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $revenueAccount->id,
        'debit_amount' => 0,
        'credit_amount' => 12000,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['categories']['Cumulative Revenue'])->toBe(12000.00);
});

it('syncs variable expense from job cost cards by slug mapping', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $materialsCategory = BudgetCategory::factory()->variableExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Materials',
        'slug' => 'materials',
    ]);

    $subCategory = BudgetCategory::factory()->variableExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Subcontractors',
        'slug' => 'subcontractors',
    ]);

    JobCostCard::factory()->forYear($year)->create([
        'company_setting_id' => $settings->id,
        'material_cost' => 1500,
        'subcontractor_cost' => 2000,
    ]);

    JobCostCard::factory()->forYear($year)->create([
        'company_setting_id' => $settings->id,
        'material_cost' => 500,
        'subcontractor_cost' => 1000,
    ]);

    // Different year - should not be counted
    JobCostCard::factory()->forYear($year - 1)->create([
        'company_setting_id' => $settings->id,
        'material_cost' => 9999,
        'subcontractor_cost' => 9999,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['categories']['Materials'])->toBe(2000.00)
        ->and($result['categories']['Subcontractors'])->toBe(3000.00);
});

it('syncs all variable expense slugs correctly', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $slugMap = [
        'production-management-labor' => ['field' => 'production_mgmt_labor', 'amount' => 800],
        'subcontractors' => ['field' => 'subcontractor_cost', 'amount' => 1200],
        'workers-comp-insurance' => ['field' => 'wc_insurance_burden', 'amount' => 300],
        'materials' => ['field' => 'material_cost', 'amount' => 600],
        'sales-commissions' => ['field' => 'sales_commission', 'amount' => 400],
    ];

    foreach ($slugMap as $slug => $config) {
        BudgetCategory::factory()->variableExpense()->create([
            'company_setting_id' => $settings->id,
            'name' => str_replace('-', ' ', ucwords($slug, '-')),
            'slug' => $slug,
        ]);
    }

    $jccData = [
        'company_setting_id' => $settings->id,
        'year' => $year,
        'client_name' => 'Test Client',
        'price' => 10000,
    ];
    foreach ($slugMap as $slug => $config) {
        $jccData[$config['field']] = $config['amount'];
    }
    JobCostCard::create($jccData);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['synced'])->toBe(5);
    foreach ($slugMap as $slug => $config) {
        $catName = str_replace('-', ' ', ucwords($slug, '-'));
        expect($result['categories'][$catName])->toBe((float) $config['amount']);
    }
});

it('syncs vehicle expense from journal entries with mapped accounts', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Vehicle Expenses',
        'slug' => 'vehicle-expenses',
    ]);

    $vehicleAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '6100',
        'name' => 'Vehicle Fuel',
        'budget_category_id' => $category->id,
    ]);

    $entry = JournalEntry::factory()->posted()->create([
        'company_setting_id' => $settings->id,
        'transaction_date' => now(),
        'recognition_date' => now(),
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $vehicleAccount->id,
        'debit_amount' => 750,
        'credit_amount' => 0,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['categories']['Vehicle Expenses'])->toBe(750.00);
});

it('syncs business expense from journal entries with mapped accounts', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $category = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Business Expenses',
        'slug' => 'business-expenses',
    ]);

    $bizAccount = Account::factory()->expense()->create([
        'company_setting_id' => $settings->id,
        'code' => '7100',
        'name' => 'Office Manager',
        'budget_category_id' => $category->id,
    ]);

    $entry = JournalEntry::factory()->posted()->create([
        'company_setting_id' => $settings->id,
        'transaction_date' => now(),
        'recognition_date' => now(),
    ]);

    JournalEntryLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $bizAccount->id,
        'debit_amount' => 3200,
        'credit_amount' => 0,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['categories']['Business Expenses'])->toBe(3200.00);
});

it('syncs marketing expense from marketing tracking entries', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $category = BudgetCategory::factory()->marketingExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Marketing Expenses',
        'slug' => 'marketing-expenses',
    ]);

    MarketingTrackingEntry::factory()->forYear($year)->create([
        'company_setting_id' => $settings->id,
        'marketing_expense' => 1500,
    ]);

    MarketingTrackingEntry::factory()->forYear($year)->create([
        'company_setting_id' => $settings->id,
        'marketing_expense' => 2500,
    ]);

    // Different year
    MarketingTrackingEntry::factory()->forYear($year - 1)->create([
        'company_setting_id' => $settings->id,
        'marketing_expense' => 9999,
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    expect($result['categories']['Marketing Expenses'])->toBe(4000.00);
});

it('preserves existing plan values when syncing actuals', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $category = BudgetCategory::factory()->marketingExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Marketing',
        'slug' => 'marketing',
    ]);

    // Pre-existing line item with plan values
    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
        'year' => $year,
        'current_year_plan' => 50000,
        'prior_year_actual' => 45000,
        'current_year_actual' => 0,
    ]);

    MarketingTrackingEntry::factory()->forYear($year)->create([
        'company_setting_id' => $settings->id,
        'marketing_expense' => 5000,
    ]);

    $service = new BudgetSyncService;
    $service->syncActualsForYear($year);

    $item = BudgetLineItem::where('budget_category_id', $category->id)
        ->where('year', $year)
        ->first();

    // Plan and prior year should be preserved
    expect((float) $item->current_year_plan)->toBe(50000.00)
        ->and((float) $item->prior_year_actual)->toBe(45000.00)
        ->and((float) $item->current_year_actual)->toBe(5000.00)
        ->and($item->sync_source)->toBe('marketing_tracking');
});

it('returns zero synced when no categories exist', function () {
    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear(now()->year);

    expect($result['synced'])->toBe(0)
        ->and($result['categories'])->toBeEmpty();
});

it('does not sync categories with unmapped slugs', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    // Variable expense with an unmapped slug
    BudgetCategory::factory()->variableExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Other Expense',
        'slug' => 'other-variable-expense',
    ]);

    $service = new BudgetSyncService;
    $result = $service->syncActualsForYear($year);

    // Variable expense with unrecognized slug returns null, so not synced
    expect($result['synced'])->toBe(0);
});

// --- Livewire UI Tests ---

it('shows sync actuals button for users with manage permission', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertSee('Sync Actuals');
});

it('does not show sync button for view-only users', function () {
    $user = User::factory()->internal()->create();
    $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewerRole->syncPermissions([
        Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']),
    ]);
    $user->assignRole('viewer');

    $settings = CompanySetting::getInstance();

    BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertDontSee('Sync Actuals');
});

it('can call syncActuals and shows success message', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    BudgetCategory::factory()->marketingExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Marketing',
        'slug' => 'marketing',
    ]);

    MarketingTrackingEntry::factory()->forYear(now()->year)->create([
        'company_setting_id' => $settings->id,
        'marketing_expense' => 1000,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->call('syncActuals')
        ->assertSee('Synced 1 categories from live data.');
});

it('requires manage permission to sync actuals', function () {
    $user = User::factory()->internal()->create();
    $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewerRole->syncPermissions([
        Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']),
    ]);
    $user->assignRole('viewer');

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->call('syncActuals')
        ->assertForbidden();
});

it('shows accounting mode badge', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertSee('Cash Basis');
});

it('marks manual edits with manual sync source', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->call('openEdit', $category->id)
        ->set('editCurrentYearActual', '25000')
        ->set('editCurrentYearPlan', '50000')
        ->set('editPriorYearActual', '40000')
        ->call('saveEdit');

    $item = BudgetLineItem::where('budget_category_id', $category->id)
        ->where('year', now()->year)
        ->first();

    expect($item->sync_source)->toBe('manual')
        ->and($item->synced_at)->toBeNull();
});
