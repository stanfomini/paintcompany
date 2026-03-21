<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\FbLandingPageOffer;
use App\Models\FbLandingPageView;
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

it('renders the fb ads report component', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertOk()
        ->assertSee('Facebook Ads Performance')
        ->assertSet('dateRange', '30d');
});

it('denies access to unauthorized users', function () {
    $user = User::factory()->customer()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertForbidden();
});

it('shows empty state when no offers exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertSee('No Facebook Offers Found');
});

it('displays offer data with variant comparison', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create(['created_by' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id, 'created_by' => $user->id]);

    $offer = FbLandingPageOffer::factory()->create(['title' => 'Test Campaign']);

    FbLandingPageView::factory()->variantA()->forOffer($offer)->count(5)->create();
    FbLandingPageView::factory()->variantB()->forOffer($offer)->count(3)->create();
    FbLandingPageView::factory()->variantB()->forOffer($offer)->count(2)->create([
        'converted' => true,
        'converted_at' => now(),
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertSee('Test Campaign')
        ->assertSee('Variant A (Photo)')
        ->assertSee('Variant B (Video)')
        ->assertSee('Active')
        ->assertDontSee('No Facebook Offers Found');
});

it('calculates variant metrics correctly', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create(['created_by' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id, 'created_by' => $user->id]);

    $offer = FbLandingPageOffer::factory()->create();
    $visitorId = 'same-visitor';

    // Variant A: 3 views, 2 unique visitors, 1 conversion
    FbLandingPageView::factory()->variantA()->forOffer($offer)->create(['visitor_id' => $visitorId]);
    FbLandingPageView::factory()->variantA()->forOffer($offer)->create(['visitor_id' => $visitorId]);
    FbLandingPageView::factory()->variantA()->forOffer($offer)->create([
        'converted' => true,
        'converted_at' => now(),
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
    ]);

    // Variant B: 2 views, 2 unique visitors, 2 conversions
    FbLandingPageView::factory()->variantB()->forOffer($offer)->create([
        'converted' => true,
        'converted_at' => now(),
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
    ]);
    FbLandingPageView::factory()->variantB()->forOffer($offer)->create([
        'converted' => true,
        'converted_at' => now(),
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report');

    $reportData = $component->get('reportData');

    expect($reportData)->toHaveCount(1);

    $offerData = $reportData[0];
    expect($offerData['variants']['a']['total_views'])->toBe(3);
    expect($offerData['variants']['a']['unique_visitors'])->toBe(2);
    expect($offerData['variants']['a']['conversions'])->toBe(1);

    expect($offerData['variants']['b']['total_views'])->toBe(2);
    expect($offerData['variants']['b']['unique_visitors'])->toBe(2);
    expect($offerData['variants']['b']['conversions'])->toBe(2);

    // B has higher conversion rate (100% vs 50%), so B wins
    expect($offerData['winner'])->toBe('b');
});

it('can change date range', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertSet('dateRange', '30d')
        ->call('setDateRange', '7d')
        ->assertSet('dateRange', '7d')
        ->call('setDateRange', '90d')
        ->assertSet('dateRange', '90d');
});

it('switches to custom date range when start date is updated', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertSet('dateRange', '30d')
        ->set('startDate', '2026-01-01')
        ->assertSet('dateRange', 'custom');
});

it('switches to custom date range when end date is updated', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertSet('dateRange', '30d')
        ->set('endDate', '2026-03-20')
        ->assertSet('dateRange', 'custom');
});

it('filters views by date range', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $offer = FbLandingPageOffer::factory()->create();

    // Views within 7 days
    FbLandingPageView::factory()->variantA()->forOffer($offer)->count(3)->create([
        'created_at' => now()->subDays(3),
    ]);

    // Views outside 7 days but within 30 days
    FbLandingPageView::factory()->variantA()->forOffer($offer)->count(5)->create([
        'created_at' => now()->subDays(15),
    ]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->call('setDateRange', '7d');

    $reportData = $component->get('reportData');
    $totalViews = $reportData[0]['summary']['total_views'];

    // Only the 3 views within 7 days should be counted
    expect($totalViews)->toBe(3);
});

it('shows inactive badge for inactive offers', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    FbLandingPageOffer::factory()->inactive()->create(['title' => 'Old Campaign']);

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertSee('Old Campaign')
        ->assertSee('Inactive');
});

it('rejects invalid date range values', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->call('setDateRange', 'invalid')
        ->assertSet('dateRange', '30d');
});

it('shows date range pills', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report')
        ->assertSee('7d')
        ->assertSee('30d')
        ->assertSee('90d')
        ->assertSee('Custom');
});

it('shows global summary cards with correct totals', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $offer1 = FbLandingPageOffer::factory()->create();
    $offer2 = FbLandingPageOffer::factory()->create();

    FbLandingPageView::factory()->variantA()->forOffer($offer1)->count(4)->create();
    FbLandingPageView::factory()->variantB()->forOffer($offer2)->count(6)->create();

    $component = Livewire::actingAs($user)
        ->test('business-plan.fb-ads-report');

    $summary = $component->get('globalSummary');

    expect($summary['total_views'])->toBe(10);
    expect($summary['offers_count'])->toBe(2);
});
