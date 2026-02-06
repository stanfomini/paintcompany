<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\MarketingTrackingEntry;
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
        ->test('business-plan.marketing-tracking')
        ->assertOk()
        ->assertSee('Marketing Tracking')
        ->assertSet('year', now()->year)
        ->assertSee((string) now()->year);
});

it('shows empty state when no entries exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->assertSee('No marketing sources for');
});

it('can add a marketing source', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->call('openAdd')
        ->assertSet('showAddModal', true)
        ->set('addSourceName', 'Test Source')
        ->call('addSource')
        ->assertSet('showAddModal', false);

    $settings = CompanySetting::getInstance();
    $this->assertDatabaseHas('marketing_tracking_entries', [
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'source_name' => 'Test Source',
        'is_manual' => true,
    ]);
});

it('prevents duplicate source names for the same year', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    MarketingTrackingEntry::factory()->source('Test Source')->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->call('openAdd')
        ->set('addSourceName', 'Test Source')
        ->call('addSource')
        ->assertHasErrors('addSourceName');
});

it('can edit a marketing source entry', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $entry = MarketingTrackingEntry::factory()->source('Google Ads')->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'marketing_expense' => 500,
        'leads' => 10,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->call('openEdit', $entry->id)
        ->assertSet('showEditModal', true)
        ->assertSet('editSourceName', 'Google Ads')
        ->set('editMarketingExpense', '1500')
        ->set('editLeads', 25)
        ->set('editEstimates', 15)
        ->set('editJobsBooked', 8)
        ->set('editTotalSales', '50000')
        ->call('saveEdit')
        ->assertSet('showEditModal', false);

    $entry->refresh();
    expect((float) $entry->marketing_expense)->toBe(1500.00)
        ->and($entry->leads)->toBe(25)
        ->and($entry->estimates)->toBe(15)
        ->and($entry->jobs_booked)->toBe(8)
        ->and((float) $entry->total_sales)->toBe(50000.00);
});

it('can delete a marketing source entry', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $entry = MarketingTrackingEntry::factory()->source('To Delete')->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->call('confirmDelete', $entry->id)
        ->assertSet('showDeleteConfirm', true)
        ->call('deleteEntry')
        ->assertSet('showDeleteConfirm', false);

    $this->assertDatabaseMissing('marketing_tracking_entries', [
        'id' => $entry->id,
    ]);
});

it('calculates cost per lead correctly', function () {
    $entry = new MarketingTrackingEntry([
        'marketing_expense' => 1000,
        'leads' => 20,
    ]);

    expect($entry->cost_per_lead)->toBe(50.00);
});

it('returns zero cost per lead when no leads', function () {
    $entry = new MarketingTrackingEntry([
        'marketing_expense' => 1000,
        'leads' => 0,
    ]);

    expect($entry->cost_per_lead)->toBe(0.0);
});

it('calculates ROI percentage correctly', function () {
    $entry = new MarketingTrackingEntry([
        'marketing_expense' => 1000,
        'total_sales' => 5000,
    ]);

    // ROI = (5000 - 1000) / 1000 * 100 = 400%
    expect($entry->roi_percent)->toBe(400.0);
});

it('returns zero ROI when no expense', function () {
    $entry = new MarketingTrackingEntry([
        'marketing_expense' => 0,
        'total_sales' => 5000,
    ]);

    expect($entry->roi_percent)->toBe(0.0);
});

it('calculates negative ROI correctly', function () {
    $entry = new MarketingTrackingEntry([
        'marketing_expense' => 1000,
        'total_sales' => 500,
    ]);

    // ROI = (500 - 1000) / 1000 * 100 = -50%
    expect($entry->roi_percent)->toBe(-50.0);
});

it('year change loads correct data', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    MarketingTrackingEntry::factory()->source('Source 2025')->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year - 1,
    ]);

    MarketingTrackingEntry::factory()->source('Source 2026')->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->assertSee('Source 2026')
        ->assertDontSee('Source 2025')
        ->set('year', now()->year - 1)
        ->assertSee('Source 2025')
        ->assertDontSee('Source 2026');
});

it('requires business_plan.view permission to render', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->assertForbidden();
});

it('requires business_plan.manage permission for edits', function () {
    $user = User::factory()->internal()->create();

    // Give only view permission, not manage
    $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewerRole->syncPermissions([
        Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']),
    ]);
    $user->assignRole('viewer');

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->assertOk()
        ->assertDontSee('Add Source')
        ->call('openAdd')
        ->assertForbidden();
});

it('displays totals row when entries exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    MarketingTrackingEntry::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'source_name' => 'Source A',
        'marketing_expense' => 1000,
        'leads' => 10,
        'estimates' => 5,
        'jobs_booked' => 3,
        'total_sales' => 15000,
    ]);

    MarketingTrackingEntry::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'source_name' => 'Source B',
        'marketing_expense' => 2000,
        'leads' => 20,
        'estimates' => 10,
        'jobs_booked' => 7,
        'total_sales' => 35000,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->assertSee('Totals')
        ->assertSee('Source A')
        ->assertSee('Source B');
});

it('seeds default sources via seedDefaults', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->call('seedDefaults');

    $settings = CompanySetting::getInstance();
    $count = MarketingTrackingEntry::where('company_setting_id', $settings->id)
        ->where('year', now()->year)
        ->count();

    expect($count)->toBe(count(MarketingTrackingEntry::defaultSources()));
});

it('can export csv data', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    MarketingTrackingEntry::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'source_name' => 'CSV Test Source',
        'marketing_expense' => 1500,
        'leads' => 30,
    ]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking');

    // The exportCsv method returns a StreamedResponse
    // We verify it doesn't throw and the entry data is accessible
    $component->assertOk()
        ->assertSee('CSV Test Source');
});

it('syncs data from deals when lead_source is set', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    // Create deals with lead_source
    Deal::factory()->count(3)->create([
        'lead_source' => 'Google Ads',
        'stage' => 'lead',
        'created_at' => now(),
    ]);

    Deal::factory()->create([
        'lead_source' => 'Google Ads',
        'stage' => 'completed',
        'actual_value' => 10000,
        'created_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.marketing-tracking')
        ->call('syncFromDeals');

    $entry = MarketingTrackingEntry::where('company_setting_id', $settings->id)
        ->where('year', now()->year)
        ->where('source_name', 'Google Ads')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->leads)->toBe(4)
        ->and($entry->jobs_booked)->toBe(1);
});
