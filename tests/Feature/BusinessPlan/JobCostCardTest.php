<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\User;
use App\Services\JobCostCardService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('renders with year selector', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->assertSet('year', now()->year)
        ->assertSee('Job Cost Cards')
        ->assertSee((string) now()->year)
        ->assertStatus(200);
});

it('can add a manual job cost card', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->call('openCreateModal')
        ->assertSet('showModal', true)
        ->set('clientName', 'John Smith')
        ->set('price', '10000')
        ->set('productionMgmtLabor', '700')
        ->set('subcontractorCost', '500')
        ->set('wcInsuranceBurden', '300')
        ->set('materialCost', '1500')
        ->set('salesCommission', '600')
        ->set('notes', 'Test note')
        ->call('save')
        ->assertSet('showModal', false);

    $card = JobCostCard::where('client_name', 'John Smith')->first();
    expect($card)->not->toBeNull()
        ->and((float) $card->price)->toBe(10000.00)
        ->and((float) $card->production_mgmt_labor)->toBe(700.00)
        ->and((float) $card->subcontractor_cost)->toBe(500.00)
        ->and((float) $card->wc_insurance_burden)->toBe(300.00)
        ->and((float) $card->material_cost)->toBe(1500.00)
        ->and((float) $card->sales_commission)->toBe(600.00)
        ->and($card->is_manual)->toBeTrue()
        ->and($card->notes)->toBe('Test note')
        ->and($card->year)->toBe(now()->year);
});

it('can edit a job cost card', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $card = JobCostCard::factory()->create([
        'client_name' => 'Original Name',
        'price' => 5000,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->call('openEditModal', $card->id)
        ->assertSet('showModal', true)
        ->assertSet('clientName', 'Original Name')
        ->set('clientName', 'Updated Name')
        ->set('price', '8000')
        ->call('save')
        ->assertSet('showModal', false);

    $card->refresh();
    expect($card->client_name)->toBe('Updated Name')
        ->and((float) $card->price)->toBe(8000.00);
});

it('can delete a manual job cost card', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $card = JobCostCard::factory()->manual()->create();

    expect(JobCostCard::count())->toBe(1);

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->call('confirmDelete', $card->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteCard')
        ->assertSet('showDeleteModal', false);

    expect(JobCostCard::count())->toBe(0);
});

it('cannot delete a non-manual job cost card', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $card = JobCostCard::factory()->create([
        'is_manual' => false,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->call('confirmDelete', $card->id)
        ->call('deleteCard');

    expect(JobCostCard::count())->toBe(1);
});

it('calculates percentages correctly', function () {
    $card = new JobCostCard([
        'price' => 10000,
        'production_mgmt_labor' => 700,
        'subcontractor_cost' => 500,
        'wc_insurance_burden' => 300,
        'material_cost' => 1500,
        'sales_commission' => 600,
    ]);

    expect($card->total_cost)->toBe(3600.0)
        ->and($card->gross_profit)->toBe(6400.0)
        ->and($card->gross_profit_percent)->toBe(64.0)
        ->and($card->production_mgmt_percent)->toBe(7.0)
        ->and($card->subcontractor_percent)->toBe(5.0)
        ->and($card->wc_insurance_percent)->toBe(3.0)
        ->and($card->material_percent)->toBe(15.0)
        ->and($card->commission_percent)->toBe(6.0);
});

it('handles zero price gracefully for percentages', function () {
    $card = new JobCostCard([
        'price' => 0,
        'production_mgmt_labor' => 100,
        'subcontractor_cost' => 50,
        'wc_insurance_burden' => 30,
        'material_cost' => 200,
        'sales_commission' => 60,
    ]);

    expect($card->gross_profit_percent)->toBe(0.0)
        ->and($card->production_mgmt_percent)->toBe(0.0)
        ->and($card->subcontractor_percent)->toBe(0.0)
        ->and($card->wc_insurance_percent)->toBe(0.0)
        ->and($card->material_percent)->toBe(0.0)
        ->and($card->commission_percent)->toBe(0.0);
});

it('calculates running totals correctly', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();

    JobCostCard::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'price' => 10000,
        'production_mgmt_labor' => 700,
        'subcontractor_cost' => 500,
        'wc_insurance_burden' => 300,
        'material_cost' => 1500,
        'sales_commission' => 600,
        'is_manual' => true,
    ]);

    JobCostCard::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'price' => 5000,
        'production_mgmt_labor' => 350,
        'subcontractor_cost' => 250,
        'wc_insurance_burden' => 150,
        'material_cost' => 750,
        'sales_commission' => 300,
        'is_manual' => true,
    ]);

    $service = new JobCostCardService;
    $totals = $service->getRunningTotals(now()->year);

    expect($totals['price'])->toBe(15000.0)
        ->and($totals['production_mgmt_labor'])->toBe(1050.0)
        ->and($totals['subcontractor_cost'])->toBe(750.0)
        ->and($totals['wc_insurance_burden'])->toBe(450.0)
        ->and($totals['material_cost'])->toBe(2250.0)
        ->and($totals['sales_commission'])->toBe(900.0)
        ->and($totals['total_cost'])->toBe(5400.0)
        ->and($totals['gross_profit'])->toBe(9600.0)
        ->and($totals['gross_profit_percent'])->toBe(64.0)
        ->and($totals['count'])->toBe(2);
});

it('changes year and loads correct data', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();

    // Card for current year
    JobCostCard::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'client_name' => 'Current Year Client',
        'is_manual' => true,
    ]);

    // Card for previous year
    JobCostCard::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year - 1,
        'client_name' => 'Last Year Client',
        'is_manual' => true,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->assertSee('Current Year Client')
        ->assertDontSee('Last Year Client')
        ->call('changeYear', now()->year - 1)
        ->assertSet('year', now()->year - 1)
        ->assertSee('Last Year Client')
        ->assertDontSee('Current Year Client');
});

it('requires business_plan.manage for creating entries', function () {
    $viewPermission = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewPermission]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->assertStatus(200)
        ->call('openCreateModal')
        ->assertForbidden();
});

it('requires business_plan.manage for deleting entries', function () {
    $viewPermission = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewPermission]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $card = JobCostCard::factory()->manual()->create();

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->call('confirmDelete', $card->id)
        ->assertForbidden();

    expect(JobCostCard::count())->toBe(1);
});

it('validates required fields when saving', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->call('openCreateModal')
        ->set('clientName', '')
        ->set('price', '')
        ->call('save')
        ->assertHasErrors(['clientName', 'price']);
});

it('service generates cards from completed jobs', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();

    $customer = Customer::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
    ]);

    $deal = Deal::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'subtotal' => 8000,
    ]);

    $job = Job::factory()->completed()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
        'actual_end_date' => now(),
    ]);

    $service = new JobCostCardService;
    $cards = $service->generateFromCompletedJobs(now()->year);

    expect($cards)->toHaveCount(1)
        ->and($cards->first()->client_name)->toBe('Jane Doe')
        ->and((float) $cards->first()->price)->toBe(8000.0)
        ->and($cards->first()->is_manual)->toBeFalse();
});

it('shows empty state when no cards exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->assertSee('No Job Cost Cards');
});

it('exports CSV file', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();

    JobCostCard::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'client_name' => 'CSV Test Client',
        'is_manual' => true,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.job-cost-cards')
        ->call('exportCsv')
        ->assertFileDownloaded('job-cost-cards-'.now()->year.'.csv');
});

it('model belongs to company setting', function () {
    $card = JobCostCard::factory()->create();

    expect($card->companySetting)->toBeInstanceOf(CompanySetting::class);
});

it('model belongs to job when set', function () {
    $job = Job::factory()->create();
    $card = JobCostCard::factory()->forJob($job)->create();

    expect($card->job)->toBeInstanceOf(Job::class)
        ->and($card->job->id)->toBe($job->id);
});
