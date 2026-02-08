<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'financials.view_cost_breakdown', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'jobs.view', 'guard_name' => 'web']);
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('route accessible with correct permissions', function () {
    CompanySetting::clearInstanceCache();

    $user = User::factory()->internal()->withFullAccess()->create();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create(['subtotal' => 5000]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    JobCostCard::factory()->forJob($job)->create([
        'total_cogs' => 2500,
        'labor_hours' => 20,
        'total_hours' => 25,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $job])
        ->assertStatus(200);
});

it('route returns 403 without financials permission', function () {
    CompanySetting::clearInstanceCache();

    $viewPermission = Permission::firstOrCreate(['name' => 'jobs.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewPermission]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create(['subtotal' => 5000]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    JobCostCard::factory()->forJob($job)->create();

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $job])
        ->assertForbidden();
});

it('component renders projected COGS breakdown', function () {
    CompanySetting::clearInstanceCache();

    $user = User::factory()->internal()->withFullAccess()->create();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create(['subtotal' => 8000]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    JobCostCard::factory()->forJob($job)->create([
        'total_cogs' => 3500,
        'labor_cost' => 2000,
        'material_cost' => 800,
        'cleanup_cost' => 300,
        'sundries_cost' => 100,
        'price' => 8000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $job])
        ->assertStatus(200)
        ->assertSee('3,500') // total COGS
        ->assertSee('2,000'); // labor cost
});

it('component shows allocations when present', function () {
    CompanySetting::clearInstanceCache();

    $user = User::factory()->internal()->withFullAccess()->create();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create(['subtotal' => 10000]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $card = JobCostCard::factory()->forJob($job)->create([
        'price' => 10000,
        'production_mgmt_labor' => 700,
        'sales_commission' => 700,
        'allocation_total' => 1400,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $job])
        ->assertStatus(200)
        ->assertSee('700'); // allocation values
});

it('component shows payment summary', function () {
    CompanySetting::clearInstanceCache();

    $user = User::factory()->internal()->withFullAccess()->create();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create(['subtotal' => 6000]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    JobCostCard::factory()->forJob($job)->create([
        'price' => 6000,
        'total_invoiced' => 6000,
        'total_collected' => 4000,
        'balance_due' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $job])
        ->assertStatus(200)
        ->set('activeTab', 'payments')
        ->assertSee('6,000.00') // invoiced
        ->assertSee('4,000.00') // collected
        ->assertSee('2,000.00'); // balance
});

it('variance section appears when actuals present', function () {
    CompanySetting::clearInstanceCache();

    $user = User::factory()->internal()->withFullAccess()->create();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create(['subtotal' => 7000]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    JobCostCard::factory()->forJob($job)->withActuals()->create([
        'price' => 7000,
        'total_cogs' => 3000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $job])
        ->assertStatus(200)
        ->set('activeTab', 'actuals')
        ->assertSee('Actual'); // Should show actuals section
});
