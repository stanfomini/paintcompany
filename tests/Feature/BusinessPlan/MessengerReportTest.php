<?php

use App\Models\Customer;
use App\Models\MessengerBotFlow;
use App\Models\MessengerConversation;
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

it('renders the messenger report component', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->assertOk()
        ->assertSee('Messenger Bot Performance')
        ->assertSet('dateRange', '30d');
});

it('denies access to unauthorized users', function () {
    $user = User::factory()->customer()->create();

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->assertForbidden();
});

it('shows empty state when no flows exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->assertSee('No Bot Flows Found');
});

it('displays summary cards with correct totals', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create(['created_by' => $user->id]);

    $flow = MessengerBotFlow::factory()->active()->create();
    $flow2 = MessengerBotFlow::factory()->active()->create();

    // 3 completed (1 with lead), 2 abandoned, 1 in progress
    MessengerConversation::factory()->for($flow, 'flow')->completed()->count(2)->create();
    MessengerConversation::factory()->for($flow, 'flow')->completed()->create(['customer_id' => $customer->id]);
    MessengerConversation::factory()->for($flow, 'flow')->abandoned()->count(2)->create();
    MessengerConversation::factory()->for($flow2, 'flow')->create(); // in_progress

    $component = Livewire::actingAs($user)
        ->test('business-plan.messenger-report');

    $summary = $component->get('globalSummary');

    expect($summary['active_flows'])->toBe(2);
    expect($summary['total'])->toBe(6);
    expect($summary['completed'])->toBe(3);
    expect($summary['completion_rate'])->toBe(50.0);
    expect($summary['leads'])->toBe(1);
});

it('shows per-flow breakdown with metrics', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $flow = MessengerBotFlow::factory()->active()->create(['name' => 'Lead Capture Flow', 'trigger_keyword' => 'PAINT']);

    MessengerConversation::factory()->for($flow, 'flow')->completed()->count(3)->create([
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHour(),
    ]);
    MessengerConversation::factory()->for($flow, 'flow')->abandoned()->count(1)->create();
    MessengerConversation::factory()->for($flow, 'flow')->humanTakeover()->count(1)->create();

    $component = Livewire::actingAs($user)
        ->test('business-plan.messenger-report');

    $reportData = $component->get('reportData');

    expect($reportData)->toHaveCount(1);

    $flowData = $reportData[0];
    expect($flowData['name'])->toBe('Lead Capture Flow');
    expect($flowData['trigger_keyword'])->toBe('PAINT');
    expect($flowData['total'])->toBe(5);
    expect($flowData['completed'])->toBe(3);
    expect($flowData['abandoned'])->toBe(1);
    expect($flowData['human_takeover'])->toBe(1);
    expect($flowData['completion_rate'])->toBe(60.0);

    $component->assertSee('Lead Capture Flow')
        ->assertSee('PAINT')
        ->assertSee('Active');
});

it('calculates entry source breakdown correctly', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $flow = MessengerBotFlow::factory()->active()->create();

    // Keyword: 3 started, 2 completed
    MessengerConversation::factory()->for($flow, 'flow')->completed()->count(2)->create(['entry_source' => 'keyword']);
    MessengerConversation::factory()->for($flow, 'flow')->abandoned()->create(['entry_source' => 'keyword']);

    // Ad: 2 started, 1 completed
    MessengerConversation::factory()->for($flow, 'flow')->completed()->create(['entry_source' => 'ad']);
    MessengerConversation::factory()->for($flow, 'flow')->create(['entry_source' => 'ad']); // in_progress

    // Default: 1 started, 0 completed
    MessengerConversation::factory()->for($flow, 'flow')->abandoned()->create(['entry_source' => 'default']);

    // Null entry_source (legacy) — grouped with default
    MessengerConversation::factory()->for($flow, 'flow')->completed()->create(['entry_source' => null]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.messenger-report');

    $reportData = $component->get('reportData');
    $breakdown = $reportData[0]['source_breakdown'];

    expect($breakdown['keyword']['started'])->toBe(3);
    expect($breakdown['keyword']['completed'])->toBe(2);
    expect($breakdown['keyword']['rate'])->toBe(66.7);

    expect($breakdown['ad']['started'])->toBe(2);
    expect($breakdown['ad']['completed'])->toBe(1);
    expect($breakdown['ad']['rate'])->toBe(50.0);

    // default + null = 2 started, 1 completed
    expect($breakdown['default']['started'])->toBe(2);
    expect($breakdown['default']['completed'])->toBe(1);
    expect($breakdown['default']['rate'])->toBe(50.0);
});

it('can change date range', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->assertSet('dateRange', '30d')
        ->call('setDateRange', '7d')
        ->assertSet('dateRange', '7d')
        ->call('setDateRange', '90d')
        ->assertSet('dateRange', '90d');
});

it('switches to custom date range when start date is updated', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->assertSet('dateRange', '30d')
        ->set('startDate', '2026-01-01')
        ->assertSet('dateRange', 'custom');
});

it('rejects invalid date range values', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->call('setDateRange', 'invalid')
        ->assertSet('dateRange', '30d');
});

it('filters conversations by date range', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $flow = MessengerBotFlow::factory()->active()->create();

    // Within 7 days
    MessengerConversation::factory()->for($flow, 'flow')->completed()->count(2)->create([
        'started_at' => now()->subDays(3),
    ]);

    // Outside 7 days but within 30 days
    MessengerConversation::factory()->for($flow, 'flow')->completed()->count(5)->create([
        'started_at' => now()->subDays(15),
    ]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->call('setDateRange', '7d');

    $summary = $component->get('globalSummary');
    expect($summary['total'])->toBe(2);
    expect($summary['completed'])->toBe(2);
});

it('shows daily trend data', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $flow = MessengerBotFlow::factory()->active()->create();

    $yesterday = now()->subDay();
    MessengerConversation::factory()->for($flow, 'flow')->completed()->count(3)->create([
        'started_at' => $yesterday,
    ]);
    MessengerConversation::factory()->for($flow, 'flow')->abandoned()->create([
        'started_at' => $yesterday,
    ]);

    $component = Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->call('setDateRange', '7d');

    $trend = $component->get('dailyTrend');

    // Find yesterday in the trend
    $yesterdayData = collect($trend)->firstWhere('date', $yesterday->toDateString());
    expect($yesterdayData)->not->toBeNull();
    expect($yesterdayData['started'])->toBe(4);
    expect($yesterdayData['completed'])->toBe(3);
    expect($yesterdayData['abandoned'])->toBe(1);
    expect($yesterdayData['completion_rate'])->toBe(75.0);
});

it('shows date range pills', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->assertSee('7d')
        ->assertSee('30d')
        ->assertSee('90d')
        ->assertSee('Custom');
});

it('handles flows with no conversations gracefully', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    MessengerBotFlow::factory()->active()->create(['name' => 'Empty Flow']);

    Livewire::actingAs($user)
        ->test('business-plan.messenger-report')
        ->assertSee('Empty Flow')
        ->assertSee('No conversations recorded');
});
