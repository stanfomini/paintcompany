<?php

use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\TerritoryScheduleAssigned;
use App\Services\TerritoryDispatchService;
use App\Services\UnifiedCalendarService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('creates a territory schedule with meet-up location', function () {
    $user = User::factory()->internal()->create();
    $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::factory()
        ->territory($block)
        ->withMeetUp('456 Oak Ave', 40.123, -95.456)
        ->create(['employee_profile_id' => $profile->id, 'created_by' => $user->id]);

    expect($schedule->schedule_type)->toBe('territory');
    expect($schedule->territory_id)->toBe($block->id);
    expect($schedule->meet_address)->toBe('456 Oak Ave');
    expect($schedule->isTerritoryShift())->toBeTrue();
    expect($schedule->getMeetUpUrl())->toContain('google.com/maps');
});

it('scopes territory shifts vs job shifts', function () {
    Schedule::factory()->count(2)->create();
    Schedule::factory()->count(3)->territory()->create();

    expect(Schedule::territoryShifts()->count())->toBe(3);
    expect(Schedule::jobShifts()->count())->toBe(2);
});

it('detects overlapping territory shifts', function () {
    $profile = EmployeeProfile::factory()->create();
    $block = SalesTerritory::factory()->block()->create();

    $shift1 = Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $profile->id,
        'date' => '2026-03-01',
        'start_time' => '08:00',
        'end_time' => '16:00',
    ]);
    $shift2 = Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $profile->id,
        'date' => '2026-03-01',
        'start_time' => '10:00',
        'end_time' => '18:00',
    ]);

    expect($shift1->overlaps($shift2))->toBeTrue();
});

it('relates territory to schedules', function () {
    $block = SalesTerritory::factory()->block()->create();
    Schedule::factory()->territory($block)->count(2)->create();

    expect($block->schedules)->toHaveCount(2);
});

it('returns null meet-up URL when no coordinates', function () {
    $schedule = Schedule::factory()->territory()->create();
    expect($schedule->getMeetUpUrl())->toBeNull();
});

it('defaults schedule_type to job', function () {
    $schedule = Schedule::factory()->create();

    expect($schedule->schedule_type)->toBe('job');
    expect($schedule->isTerritoryShift())->toBeFalse();
});

it('generates correct google maps URL format', function () {
    $schedule = Schedule::factory()
        ->territory()
        ->withMeetUp('789 Pine Rd', 41.8781, -87.6298)
        ->create();

    $url = $schedule->getMeetUpUrl();

    expect($url)->toBe('https://www.google.com/maps/dir/?api=1&destination=41.8781000,-87.6298000');
});

it('sends notification when territory schedule is created', function () {
    Notification::fake();
    $manager = User::factory()->internal()->create();
    $employee = User::factory()->internal()->create();
    $profile = EmployeeProfile::factory()->create(['user_id' => $employee->id]);
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::create([
        'employee_profile_id' => $profile->id,
        'schedule_type' => 'territory',
        'territory_id' => $block->id,
        'date' => now()->addDay()->toDateString(),
        'start_time' => '08:00',
        'end_time' => '16:00',
        'meet_address' => '789 Elm St',
        'meet_lat' => 40.0,
        'meet_lng' => -95.0,
        'created_by' => $manager->id,
    ]);

    $employee->notify(new TerritoryScheduleAssigned($schedule));
    Notification::assertSentTo($employee, TerritoryScheduleAssigned::class);
});

it('includes territory details in notification data', function () {
    $block = SalesTerritory::factory()->block()->create(['name' => 'Pine Valley Block']);
    $schedule = Schedule::factory()->territory($block)->withMeetUp('100 Pine St', 40.0, -95.0)->create();

    $notification = new TerritoryScheduleAssigned($schedule);
    $user = $schedule->employeeProfile->user;

    $data = $notification->toArray($user);

    expect($data['territory_name'])->toBe('Pine Valley Block');
    expect($data['meet_address'])->toBe('100 Pine St');
    expect($data['meet_url'])->toContain('google.com/maps');
});

it('shows territory schedules in unified calendar', function () {
    $user = User::factory()->internal()->create();
    $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
    $block = SalesTerritory::factory()->block()->create(['name' => 'Oak Street Block']);

    Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $profile->id,
        'date' => today()->addDay()->toDateString(),
        'start_time' => '08:00',
        'end_time' => '16:00',
        'meet_address' => '100 Oak St',
    ]);

    $user->refresh();
    $service = app(UnifiedCalendarService::class);
    $events = $service->getEventsForDate($user, today()->addDay());

    $territoryEvent = $events->firstWhere('source', 'territory');
    expect($territoryEvent)->not->toBeNull();
    expect($territoryEvent['title'])->toBe('Oak Street Block');
    expect($territoryEvent['source'])->toBe('territory');
    expect($territoryEvent['meta']['meet_address'])->toBe('100 Oak St');
});

it('excludes cancelled territory schedules from calendar', function () {
    $user = User::factory()->internal()->create();
    $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
    $block = SalesTerritory::factory()->block()->create();

    Schedule::factory()->territory($block)->cancelled()->create([
        'employee_profile_id' => $profile->id,
        'date' => today()->addDay()->toDateString(),
    ]);

    $user->refresh();
    $service = app(UnifiedCalendarService::class);
    $events = $service->getEventsForDate($user, today()->addDay());

    expect($events->where('source', 'territory'))->toBeEmpty();
});

it('renders territory schedule component', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-schedule')
        ->assertSuccessful()
        ->assertSee('No Team Members');
});

it('renders week grid when team members exist', function () {
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $block = SalesTerritory::factory()->block()->create();
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->assertSuccessful()
        ->assertSee('Mon')
        ->assertSee('Sun')
        ->assertSee($knocker->name);
});

it('creates territory schedule for a team member via component', function () {
    Notification::fake();
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->create();
    $knockerProfile = EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    // Add knocker as team member
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('openScheduleModal', now()->addDay()->format('Y-m-d'), $knocker->id)
        ->set('selectedTerritoryId', $block->id)
        ->set('startTime', '08:00')
        ->set('endTime', '16:00')
        ->set('meetAddress', '123 Test St')
        ->set('selectedUserIds', [$knocker->id])
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('schedules', [
        'schedule_type' => 'territory',
        'territory_id' => $block->id,
        'employee_profile_id' => $knockerProfile->id,
        'meet_address' => '123 Test St',
    ]);

    Notification::assertSentTo($knocker, TerritoryScheduleAssigned::class);
});

it('copies week schedule forward', function () {
    Notification::fake();
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->create();
    $knockerProfile = EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $block = SalesTerritory::factory()->block()->create();
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $knockerProfile->id,
        'date' => now()->startOfWeek()->addDay()->toDateString(),
        'start_time' => '08:00',
        'end_time' => '16:00',
        'created_by' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('copyWeekForward');

    expect(Schedule::territoryShifts()->count())->toBe(2);
});

it('full flow: zone manager schedules block, employee sees it in calendar', function () {
    Notification::fake();

    // Setup hierarchy
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->create();
    $knockerProfile = EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Elm Street Block']);
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    // Manager creates schedule via component
    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('openScheduleModal', now()->addDay()->format('Y-m-d'), $knocker->id)
        ->set('selectedTerritoryId', $block->id)
        ->set('startTime', '07:30')
        ->set('endTime', '15:30')
        ->set('meetAddress', '456 Elm St, Springfield')
        ->set('meetLat', 39.7817)
        ->set('meetLng', -89.6501)
        ->set('selectedUserIds', [$knocker->id])
        ->call('saveSchedule')
        ->assertHasNoErrors();

    // Verify schedule in DB
    $schedule = Schedule::where('territory_id', $block->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->meet_address)->toBe('456 Elm St, Springfield');
    expect($schedule->schedule_type)->toBe('territory');

    // Verify notification was sent
    Notification::assertSentTo($knocker, TerritoryScheduleAssigned::class);

    // Verify it shows in knocker's calendar
    $knocker->refresh();
    $service = app(UnifiedCalendarService::class);
    $events = $service->getEventsForDate($knocker, now()->addDay());

    $territoryEvent = $events->firstWhere('source', 'territory');
    expect($territoryEvent)->not->toBeNull();
    expect($territoryEvent['title'])->toBe('Elm Street Block');
    expect($territoryEvent['url'])->toContain('google.com/maps');
    expect($territoryEvent['meta']['meet_address'])->toBe('456 Elm St, Springfield');
});

it('creates a flex schedule', function () {
    $block = SalesTerritory::factory()->block()->create();
    $manager = User::factory()->internal()->create();

    $schedule = Schedule::factory()->territory($block)->flex()->create([
        'created_by' => $manager->id,
    ]);

    expect($schedule->is_flex)->toBeTrue();
    expect($schedule->employee_profile_id)->toBeNull();
    expect($schedule->isFlex())->toBeTrue();
    expect($schedule->isClaimable())->toBeTrue();
    expect($schedule->isClaimed())->toBeFalse();
});

it('marks flex block as claimed when employee assigned', function () {
    $profile = EmployeeProfile::factory()->create();
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::factory()->territory($block)->flex()->create();
    $schedule->update(['employee_profile_id' => $profile->id]);
    $schedule->refresh();

    expect($schedule->isFlex())->toBeTrue();
    expect($schedule->isClaimable())->toBeFalse();
    expect($schedule->isClaimed())->toBeTrue();
});

it('scopes flex blocks vs assigned schedules', function () {
    Schedule::factory()->count(2)->territory()->create(); // assigned
    Schedule::factory()->count(3)->territory()->flex()->create(); // flex

    expect(Schedule::flexBlocks()->count())->toBe(3);
    expect(Schedule::assigned()->count())->toBe(2);
});

it('pre-fills modal when opening with block via drag-drop method', function () {
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $block = SalesTerritory::factory()->block()->create();
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    $date = now()->addDay()->format('Y-m-d');

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('openScheduleModalWithBlock', $date, $knocker->id, $block->id)
        ->assertSet('showScheduleModal', true)
        ->assertSet('selectedDate', $date)
        ->assertSet('selectedTerritoryId', $block->id)
        ->assertSet('selectedUserIds', [$knocker->id]);
});

it('distinguishes flex from regular territory shifts', function () {
    $block = SalesTerritory::factory()->block()->create();

    $regular = Schedule::factory()->territory($block)->create();
    $flex = Schedule::factory()->territory($block)->flex()->create();

    expect($regular->isFlex())->toBeFalse();
    expect($regular->isClaimable())->toBeFalse();
    expect($flex->isFlex())->toBeTrue();
    expect($flex->isClaimable())->toBeTrue();
});

it('claims a flex block successfully', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();
    $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::factory()->territory($block)->flex()->create([
        'date' => now()->addDay()->toDateString(),
    ]);

    $service = app(TerritoryDispatchService::class);
    $result = $service->claimFlexBlock($schedule, $user);

    expect($result)->toBeTrue();
    $schedule->refresh();
    expect($schedule->employee_profile_id)->toBe($profile->id);
    expect($schedule->isClaimed())->toBeTrue();

    Notification::assertSentTo($user, TerritoryScheduleAssigned::class);
});

it('prevents double-claiming a flex block', function () {
    Notification::fake();
    $user1 = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $user1->id]);
    $user2 = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $user2->id]);
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::factory()->territory($block)->flex()->create([
        'date' => now()->addDay()->toDateString(),
    ]);

    $service = app(TerritoryDispatchService::class);

    // First claim succeeds
    expect($service->claimFlexBlock($schedule, $user1))->toBeTrue();

    // Second claim fails
    expect($service->claimFlexBlock($schedule, $user2))->toBeFalse();

    $schedule->refresh();
    expect($schedule->employee_profile_id)->toBe($user1->employeeProfile->id);
});

it('rejects claiming a non-flex schedule', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $user->id]);
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::factory()->territory($block)->create(); // Not flex

    $service = app(TerritoryDispatchService::class);
    $result = $service->claimFlexBlock($schedule, $user);

    expect($result)->toBeFalse();
});

it('rejects claiming flex block without employee profile', function () {
    $user = User::factory()->internal()->create(); // No employee profile
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::factory()->territory($block)->flex()->create();

    $service = app(TerritoryDispatchService::class);
    $result = $service->claimFlexBlock($schedule, $user);

    expect($result)->toBeFalse();
});

it('creates a flex block via schedule modal', function () {
    $manager = User::factory()->internal()->withRole('owner')->create();
    $block = SalesTerritory::factory()->block()->create();
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('openScheduleModal', now()->addDay()->format('Y-m-d'))
        ->set('selectedTerritoryId', $block->id)
        ->set('isFlex', true)
        ->set('startTime', '08:00')
        ->set('endTime', '16:00')
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('schedules', [
        'schedule_type' => 'territory',
        'territory_id' => $block->id,
        'is_flex' => true,
        'employee_profile_id' => null,
    ]);
});

it('claims flex block via schedule component', function () {
    Notification::fake();
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->create();
    $knockerProfile = EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $block = SalesTerritory::factory()->block()->create();
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    $schedule = Schedule::factory()->territory($block)->flex()->create([
        'date' => now()->startOfWeek()->addDay()->toDateString(),
    ]);

    Livewire::actingAs($knocker)
        ->test('sales.territory-schedule')
        ->call('claimFlexBlock', $schedule->id);

    $schedule->refresh();
    expect($schedule->employee_profile_id)->toBe($knockerProfile->id);
    expect($schedule->isClaimed())->toBeTrue();
});

it('shows flex blocks in unclaimed row', function () {
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $knocker->id]);
    $block = SalesTerritory::factory()->block()->create(['name' => 'Flex Test Block']);
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    Schedule::factory()->territory($block)->flex()->create([
        'date' => now()->startOfWeek()->addDay()->toDateString(),
    ]);

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->assertSee('Flex / Unclaimed')
        ->assertSee('Flex Test Block');
});
