<?php

use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use App\Models\OverheadEntry;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('renders the component with year selector', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->assertOk()
        ->assertSee('Overhead Tracking')
        ->assertSet('year', now()->year)
        ->assertSee((string) now()->year);
});

it('shows empty state when no overhead categories exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->assertSee('No overhead categories');
});

it('displays month rows and category columns', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Vehicle Lease',
        'slug' => 'vehicle-lease',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->assertSee('Vehicle Lease')
        ->assertSee('January')
        ->assertSee('December')
        ->assertSee('Annual Total');
});

it('can edit an overhead cell amount', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Management Wages',
        'slug' => 'management-wages',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->call('openEdit', $category->id, 3)
        ->assertSet('showEditModal', true)
        ->assertSet('editingCategoryId', $category->id)
        ->assertSet('editingMonth', 3)
        ->set('editAmount', '1500.00')
        ->set('editNotes', 'March wages')
        ->call('saveEdit')
        ->assertSet('showEditModal', false);

    $this->assertDatabaseHas('overhead_entries', [
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
        'year' => now()->year,
        'month' => 3,
        'amount' => '1500.00',
        'notes' => 'March wages',
    ]);
});

it('updates existing entry when editing same cell again', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Vehicle Fuel',
        'slug' => 'vehicle-fuel',
    ]);

    OverheadEntry::factory()->forMonth(1)->forCategory($category)->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'amount' => 200,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->call('openEdit', $category->id, 1)
        ->assertSet('editAmount', '200')
        ->set('editAmount', '350.00')
        ->call('saveEdit');

    expect(OverheadEntry::where('budget_category_id', $category->id)
        ->where('month', 1)
        ->where('year', now()->year)
        ->count()
    )->toBe(1);

    $entry = OverheadEntry::where('budget_category_id', $category->id)
        ->where('month', 1)
        ->where('year', now()->year)
        ->first();

    expect((float) $entry->amount)->toBe(350.00);
});

it('only shows vehicle_expense and business_expense categories', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Vehicle Lease',
        'slug' => 'vehicle-lease',
    ]);

    BudgetCategory::factory()->businessExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Office Rent',
        'slug' => 'office-rent',
    ]);

    BudgetCategory::factory()->revenue()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Painting Revenue',
        'slug' => 'painting-revenue',
    ]);

    BudgetCategory::factory()->marketingExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Google Ads',
        'slug' => 'google-ads',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->assertSee('Vehicle Lease')
        ->assertSee('Office Rent')
        ->assertDontSee('Painting Revenue')
        ->assertDontSee('Google Ads');
});

it('requires business_plan.view permission to render', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
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

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Vehicle Lease',
        'slug' => 'viewer-vehicle-lease',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->assertOk()
        ->assertDontSee('Export CSV')
        ->call('openEdit', $category->id, 1)
        ->assertForbidden();
});

it('year change loads correct data', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->vehicleExpense()->create([
        'company_setting_id' => $settings->id,
        'name' => 'Vehicle Lease',
        'slug' => 'year-vehicle-lease',
    ]);

    OverheadEntry::factory()->forMonth(1)->forCategory($category)->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'amount' => 500,
    ]);

    OverheadEntry::factory()->forMonth(1)->forCategory($category)->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year - 1,
        'amount' => 300,
    ]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.overhead-tracking')
        ->assertSee('$500.00');

    $component->set('year', now()->year - 1)
        ->assertSee('$300.00');
});
