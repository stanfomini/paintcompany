<?php

use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\BusinessPlanService;
use Database\Seeders\BudgetCategorySeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('renders the budget component with year selector', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertOk()
        ->assertSee('Budget')
        ->assertSet('year', now()->year)
        ->assertSee((string) now()->year);
});

it('shows empty state when no categories exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertSee('No budget categories configured');
});

it('can seed default budget categories', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->call('seedDefaults');

    $settings = CompanySetting::getInstance();
    $count = BudgetCategory::where('company_setting_id', $settings->id)->count();

    $expectedCount = collect(BudgetCategorySeeder::defaultCategories())->flatten()->count();
    expect($count)->toBe($expectedCount);
});

it('displays section headers when categories exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Cumulative Revenue',
        'slug' => 'cumulative-revenue',
    ]);

    BudgetCategory::factory()->variableExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Materials',
        'slug' => 'materials',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertSee('Revenue')
        ->assertSee('Variable Expenses')
        ->assertSee('Cumulative Revenue')
        ->assertSee('Materials');
});

it('can open edit modal and save line item values', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Cumulative Revenue',
        'slug' => 'cumulative-revenue',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->call('openEdit', $category->id)
        ->assertSet('showEditModal', true)
        ->assertSet('editCategoryName', 'Cumulative Revenue')
        ->set('editPriorYearActual', '500000')
        ->set('editCurrentYearPlan', '600000')
        ->set('editCurrentYearActual', '550000')
        ->set('editNotes', 'On track')
        ->call('saveEdit')
        ->assertSet('showEditModal', false);

    $item = BudgetLineItem::where('company_setting_id', $settings->id)
        ->where('budget_category_id', $category->id)
        ->where('year', now()->year)
        ->first();

    expect($item)->not->toBeNull()
        ->and((float) $item->prior_year_actual)->toBe(500000.00)
        ->and((float) $item->current_year_plan)->toBe(600000.00)
        ->and((float) $item->current_year_actual)->toBe(550000.00)
        ->and($item->notes)->toBe('On track');
});

it('calculates gross profit correctly', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $revenue = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue-total',
    ]);

    $variable = BudgetCategory::factory()->variableExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Materials',
        'slug' => 'materials',
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $revenue->id,
        'year' => $year,
        'current_year_plan' => 100000,
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $variable->id,
        'year' => $year,
        'current_year_plan' => 40000,
    ]);

    $service = new BusinessPlanService;
    $data = $service->getBudgetForYear($year);

    expect($data['computed']['gross_profit']['current_year_plan'])->toBe(60000.0);
});

it('calculates net profit correctly', function () {
    $settings = CompanySetting::getInstance();
    $year = now()->year;

    $revenue = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue-total',
    ]);

    $variable = BudgetCategory::factory()->variableExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Materials',
        'slug' => 'materials',
    ]);

    $business = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Rent',
        'slug' => 'rent',
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $revenue->id,
        'year' => $year,
        'current_year_plan' => 100000,
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $variable->id,
        'year' => $year,
        'current_year_plan' => 40000,
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $business->id,
        'year' => $year,
        'current_year_plan' => 20000,
    ]);

    $service = new BusinessPlanService;
    $data = $service->getBudgetForYear($year);

    // Gross = 100000 - 40000 = 60000
    // Fixed = 20000 (business only)
    // Net = 60000 - 20000 = 40000
    expect($data['computed']['net_profit']['current_year_plan'])->toBe(40000.0);
});

it('displays computed rows in the table', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $revenue = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue-total',
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $revenue->id,
        'year' => now()->year,
        'current_year_plan' => 100000,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertSee('GROSS PROFIT')
        ->assertSee('TOTAL FIXED EXPENSES')
        ->assertSee('NET PROFIT');
});

it('requires business_plan.view permission to render', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertForbidden();
});

it('requires business_plan.manage permission for edits', function () {
    $user = User::factory()->internal()->create();

    $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewerRole->syncPermissions([
        Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']),
    ]);
    $user->assignRole('viewer');

    $settings = CompanySetting::getInstance();
    $category = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue-test',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertOk()
        ->assertDontSee('Export CSV')
        ->call('openEdit', $category->id)
        ->assertForbidden();
});

it('year change loads correct data', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Revenue',
        'slug' => 'revenue-year-test',
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
        'year' => now()->year,
        'current_year_plan' => 100000,
    ]);

    BudgetLineItem::factory()->create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
        'year' => now()->year - 1,
        'current_year_plan' => 80000,
    ]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.budget')
        ->assertSee('$100,000.00');

    $component->set('year', now()->year - 1)
        ->assertSee('$80,000.00');
});

it('percent of revenue shows correct values', function () {
    $service = new BusinessPlanService;

    expect($service->percentOfRevenue(25000, 100000))->toBe(25.0)
        ->and($service->percentOfRevenue(0, 100000))->toBe(0.0)
        ->and($service->percentOfRevenue(100, 0))->toBe(0.0);
});
