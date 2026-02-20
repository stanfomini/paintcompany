<?php

use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\TerritoryAnalyticsService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('sales_territories.view');
});

// --- Access Control ---

it('requires authentication to access territory analytics', function () {
    $this->get(route('territory-analytics'))
        ->assertRedirect(route('login'));
});

it('requires sales_territories.view permission', function () {
    $userWithoutPermission = User::factory()->internal()->create();

    $this->actingAs($userWithoutPermission)
        ->get(route('territory-analytics'))
        ->assertForbidden();
});

it('allows access with correct permission', function () {
    $this->actingAs($this->user)
        ->get(route('territory-analytics'))
        ->assertSuccessful()
        ->assertSeeLivewire('sales.territory-analytics');
});

// --- Summary KPIs ---

it('displays summary KPIs correctly', function () {
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'status' => 'not_home',
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->interested()->count(2)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->appointmentSet()->create([
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $stats = $service->getSummaryStats([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect((int) $stats->total)->toBe(6)
        ->and((int) $stats->conversions)->toBe(3) // 2 interested + 1 appointment_set
        ->and((int) $stats->appointments)->toBe(1)
        ->and((int) $stats->not_home)->toBe(3)
        ->and((int) $stats->interested)->toBe(2);
});

it('calculates unique addresses correctly', function () {
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(2)->create([
        'address' => '123 Main St',
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->create([
        'address' => '456 Oak Ave',
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $stats = $service->getSummaryStats([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect((int) $stats->total)->toBe(3)
        ->and((int) $stats->unique_addresses)->toBe(2);
});

// --- Filtering ---

it('filters by user', function () {
    $otherUser = User::factory()->internal()->create();
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($otherUser)->forTerritory($territory)->count(5)->create([
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $stats = $service->getSummaryStats(['userId' => $this->user->id]);

    expect((int) $stats->total)->toBe(3);
});

it('filters by territory', function () {
    $territory1 = SalesTerritory::factory()->create();
    $territory2 = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory1)->count(4)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory2)->count(2)->create([
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $stats = $service->getSummaryStats(['territoryId' => $territory1->id]);

    expect((int) $stats->total)->toBe(4);
});

it('filters by status', function () {
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'status' => 'not_home',
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->interested()->count(2)->create([
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $stats = $service->getSummaryStats(['status' => 'interested']);

    expect((int) $stats->total)->toBe(2);
});

it('filters by date range', function () {
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(2)->create([
        'knocked_at' => now()->subMonth(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $stats = $service->getSummaryStats([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect((int) $stats->total)->toBe(3);
});

// --- Conversion Funnel ---

it('calculates conversion funnel correctly', function () {
    $territory = SalesTerritory::factory()->create();
    $deal = Deal::factory()->signed()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(5)->create([
        'status' => 'not_home',
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->interested()->count(3)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->appointmentSet()->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->sold()->create([
        'deal_id' => $deal->id,
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $funnel = $service->getConversionFunnel([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect($funnel[0]['label'])->toBe('Total Knocks')
        ->and($funnel[0]['count'])->toBe(10)
        ->and($funnel[1]['label'])->toBe('Interested')
        ->and($funnel[1]['count'])->toBe(5) // 3 interested + 1 appointment + 1 sold
        ->and($funnel[2]['label'])->toBe('Appointments')
        ->and($funnel[2]['count'])->toBe(2) // 1 appointment + 1 sold
        ->and($funnel[3]['label'])->toBe('Deals Created')
        ->and($funnel[3]['count'])->toBe(1)
        ->and($funnel[4]['label'])->toBe('Signed/Completed')
        ->and($funnel[4]['count'])->toBe(1);
});

// --- Revenue ---

it('calculates revenue stats from linked deals', function () {
    $territory = SalesTerritory::factory()->create();
    $deal1 = Deal::factory()->signed()->create(['estimated_value' => 5000, 'actual_value' => 4800]);
    $deal2 = Deal::factory()->lead()->create(['estimated_value' => 3000]);

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->sold()->create([
        'deal_id' => $deal1->id,
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->interested()->create([
        'deal_id' => $deal2->id,
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $revenue = $service->getRevenueStats([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect((float) $revenue->total_estimated)->toBe(8000.0)
        ->and((int) $revenue->deal_count)->toBe(2)
        ->and((int) $revenue->signed_count)->toBe(1);
});

it('returns zero revenue when no deals linked', function () {
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $revenue = $service->getRevenueStats([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect($revenue->total_estimated)->toBe(0)
        ->and($revenue->deal_count)->toBe(0);
});

// --- User Performance ---

it('returns per-user performance breakdown', function () {
    $user2 = User::factory()->internal()->create();
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(5)->create([
        'status' => 'not_home',
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->interested()->count(2)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($user2)->forTerritory($territory)->count(3)->create([
        'status' => 'not_home',
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $performance = $service->getUserPerformance([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect($performance)->toHaveCount(2);

    $firstUser = $performance->first();
    expect($firstUser->user->id)->toBe($this->user->id)
        ->and($firstUser->total_knocks)->toBe(7)
        ->and($firstUser->conversions)->toBe(2);
});

// --- Territory Performance ---

it('returns per-territory performance breakdown', function () {
    $territory1 = SalesTerritory::factory()->create();
    $territory2 = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory1)->count(4)->create([
        'address' => '123 Main St',
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory1)->interested()->create([
        'address' => '456 Oak Ave',
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory2)->count(2)->create([
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $performance = $service->getTerritoryPerformance([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect($performance)->toHaveCount(2);

    $first = $performance->first();
    expect($first->territory->id)->toBe($territory1->id)
        ->and($first->total_knocks)->toBe(5)
        ->and($first->unique_addresses)->toBe(2)
        ->and($first->conversions)->toBe(1);
});

// --- User Detail Drill-Down ---

it('returns user detail with territories worked', function () {
    $territory1 = SalesTerritory::factory()->create();
    $territory2 = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory1)->count(5)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory2)->count(2)->create([
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $detail = $service->getUserDetail($this->user->id, [
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect((int) $detail['stats']->total)->toBe(7)
        ->and($detail['territories'])->toHaveCount(2)
        ->and($detail['territories']->first()->knock_count)->toBe(5)
        ->and($detail['days_active'])->toBeGreaterThanOrEqual(1)
        ->and($detail['daily_average'])->toBeGreaterThan(0);
});

// --- Territory Detail Drill-Down ---

it('returns territory detail with rep breakdown', function () {
    $user2 = User::factory()->internal()->create();
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(5)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($user2)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);

    // Create repeat visits
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(2)->create([
        'address' => '123 Repeat Ave',
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $detail = $service->getTerritoryDetail($territory->id, [
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect((int) $detail['stats']->total)->toBe(10)
        ->and($detail['reps'])->toHaveCount(2)
        ->and($detail['reps']->first()->user->id)->toBe($this->user->id) // user with most knocks
        ->and($detail['repeat_visits'])->toBeGreaterThanOrEqual(1);
});

// --- Customer List ---

it('paginates customer list with eager loading', function () {
    $territory = SalesTerritory::factory()->create();
    $deal = Deal::factory()->lead()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(30)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->create([
        'deal_id' => $deal->id,
        'knocked_at' => now(),
    ]);

    $service = app(TerritoryAnalyticsService::class);
    $list = $service->getCustomerList([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect($list)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
        ->and($list->total())->toBe(31)
        ->and($list->perPage())->toBe(25)
        ->and($list->count())->toBe(25);
});

// --- Zero State ---

it('handles zero state gracefully', function () {
    $service = app(TerritoryAnalyticsService::class);
    $stats = $service->getSummaryStats([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect((int) $stats->total)->toBe(0)
        ->and((int) $stats->conversions)->toBe(0);

    $funnel = $service->getConversionFunnel([
        'dateFrom' => now()->startOfMonth()->format('Y-m-d'),
        'dateTo' => now()->endOfMonth()->format('Y-m-d'),
    ]);

    expect($funnel[0]['count'])->toBe(0)
        ->and($funnel[0]['pct'])->toBe(100);
});

// --- Livewire Component ---

it('renders overview panel by default', function () {
    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->assertSet('activePanel', 'overview')
        ->assertSee('Territory Analytics')
        ->assertSee('Total Knocks')
        ->assertSee('Conversion Funnel')
        ->assertSee('Rep Performance')
        ->assertSee('Territory Performance');
});

it('drills into user detail', function () {
    $territory = SalesTerritory::factory()->create();
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->call('drillIntoUser', $this->user->id)
        ->assertSet('activePanel', 'user-detail')
        ->assertSet('detailId', $this->user->id)
        ->assertSee($this->user->name)
        ->assertSee('Status Breakdown')
        ->assertSee('Territories Worked');
});

it('drills into territory detail', function () {
    $territory = SalesTerritory::factory()->create(['name' => 'Downtown Zone']);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->call('drillIntoTerritory', $territory->id)
        ->assertSet('activePanel', 'territory-detail')
        ->assertSet('detailId', $territory->id)
        ->assertSee('Downtown Zone')
        ->assertSee('Repeat Visits')
        ->assertSee('Rep Performance');
});

it('shows customers panel', function () {
    $territory = SalesTerritory::factory()->create();
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->create([
        'customer_name' => 'Jane Doe',
        'phone' => '555-1234',
        'email' => 'jane@example.com',
        'knocked_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->call('showCustomers')
        ->assertSet('activePanel', 'customers')
        ->assertSee('Customer Data')
        ->assertSee('Jane Doe')
        ->assertSee('555-1234')
        ->assertSee('jane@example.com');
});

it('navigates back to overview', function () {
    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->call('drillIntoUser', $this->user->id)
        ->assertSet('activePanel', 'user-detail')
        ->call('backToOverview')
        ->assertSet('activePanel', 'overview')
        ->assertSet('detailId', null);
});

it('filters by period', function () {
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(2)->create([
        'knocked_at' => now()->subMonths(2),
    ]);

    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->set('period', 'month')
        ->assertSee('3'); // only current month knocks shown
});

it('filters by user in component', function () {
    $user2 = User::factory()->internal()->create();
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);
    DoorKnock::factory()->forUser($user2)->forTerritory($territory)->count(7)->create([
        'knocked_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->set('filterUser', (string) $this->user->id)
        ->assertSee('3');
});

it('shows daily trend chart', function () {
    $territory = SalesTerritory::factory()->create();

    DoorKnock::factory()->forUser($this->user)->forTerritory($territory)->count(3)->create([
        'knocked_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->assertSee('Knocks per Day');
});

it('shows empty state with no data', function () {
    Livewire::actingAs($this->user)
        ->test('sales.territory-analytics')
        ->assertSee('No data for this period')
        ->assertSee('No knocks recorded');
});
