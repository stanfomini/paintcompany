<?php

use App\Models\CompanySetting;
use App\Models\LifeCommitment;
use App\Models\User;
use App\Services\CalendarFeedService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('returns 200 with valid calendar token', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    $this->get(route('calendar.feed', ['token' => $token]))
        ->assertOk();
});

it('returns 404 with invalid calendar token', function () {
    $this->get(route('calendar.feed', ['token' => Str::uuid()->toString()]))
        ->assertNotFound();
});

it('returns text/calendar content type', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->headers->get('Content-Type'))->toContain('text/calendar');
});

it('contains VCALENDAR in response', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())->toContain('VCALENDAR');
});

it('contains VEVENTs for active commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'commitment' => 'Exercise daily',
        'action_type' => 'daily',
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())
        ->toContain('VEVENT')
        ->toContain('Exercise daily');
});

it('excludes inactive commitments from feed', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'commitment' => 'Active commitment',
        'action_type' => 'daily',
        'is_active' => true,
    ]);

    LifeCommitment::factory()->create([
        'commitment' => 'Paused commitment',
        'action_type' => 'daily',
        'is_active' => false,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())
        ->toContain('Active commitment')
        ->not->toContain('Paused commitment');
});

it('generates FREQ=DAILY for daily commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'daily',
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())->toContain('FREQ=DAILY');
});

it('generates correct BYDAY for weekly commitments with single day', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'weekly',
        'recurrence_days' => [3],
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())
        ->toContain('FREQ=WEEKLY')
        ->toContain('BYDAY=WE');
});

it('generates correct BYDAY for weekly commitments with multiple days', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'weekly',
        'recurrence_days' => [2, 4],
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())
        ->toContain('FREQ=WEEKLY')
        ->toContain('BYDAY=TU,TH');
});

it('generates correct BYMONTHDAY for monthly commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'monthly',
        'recurrence_day_of_month' => 15,
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())
        ->toContain('FREQ=MONTHLY')
        ->toContain('BYMONTHDAY=15');
});

it('generates INTERVAL=3 for quarterly commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'quarterly',
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())
        ->toContain('FREQ=MONTHLY')
        ->toContain('INTERVAL=3');
});

it('generates FREQ=YEARLY for annual commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'annual',
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())->toContain('FREQ=YEARLY');
});

it('generates token if none exists', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    expect($user->calendar_token)->toBeNull();

    $token = $user->getOrCreateCalendarToken();

    expect($token)->not->toBeNull();
    expect(Str::isUuid($token))->toBeTrue();
    expect($user->fresh()->calendar_token)->toBe($token);
});

it('returns existing token if already set', function () {
    $existingToken = Str::uuid()->toString();
    $user = User::factory()->internal()->withFullAccess()->create([
        'calendar_token' => $existingToken,
    ]);

    $token = $user->getOrCreateCalendarToken();

    expect($token)->toBe($existingToken);
});

it('generates feed via service directly', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    LifeCommitment::factory()->create([
        'commitment' => 'Team standup',
        'area' => 'Business',
        'action_type' => 'daily',
        'is_active' => true,
    ]);

    $service = new CalendarFeedService;
    $feed = $service->generateFeed($user);

    expect($feed)
        ->toContain('VCALENDAR')
        ->toContain('VEVENT')
        ->toContain('Team standup')
        ->toContain('Area: Business');
});

it('uses stored recurrence time in ICS events', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'daily',
        'recurrence_time' => '06:30',
        'is_active' => true,
        'start_date' => '2026-02-10',
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));
    $content = $response->getContent();

    // The event should start at 06:30, not the default 09:00
    expect($content)->toContain('T063000');
});

it('handles weekly commitment with start_date as CarbonImmutable', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'weekly',
        'recurrence_days' => [1, 5],
        'start_date' => '2026-02-10',
        'is_active' => true,
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));

    expect($response->getContent())
        ->toContain('FREQ=WEEKLY')
        ->toContain('BYDAY=MO,FR');
});

it('generates timezone-aware ICS events using company timezone', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['timezone' => 'America/New_York']);

    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'daily',
        'recurrence_time' => '08:00',
        'is_active' => true,
        'start_date' => '2026-03-01',
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));
    $content = $response->getContent();

    // Should contain VTIMEZONE component and TZID reference, not Z suffix
    expect($content)
        ->toContain('VTIMEZONE')
        ->toContain('America/New_York')
        ->toContain('T080000');
});

it('uses Pacific timezone when company is configured for it', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['timezone' => 'America/Los_Angeles']);

    $user = User::factory()->internal()->withFullAccess()->create();
    $token = $user->getOrCreateCalendarToken();

    LifeCommitment::factory()->create([
        'action_type' => 'daily',
        'recurrence_time' => '09:00',
        'is_active' => true,
        'start_date' => '2026-03-01',
    ]);

    $response = $this->get(route('calendar.feed', ['token' => $token]));
    $content = $response->getContent();

    expect($content)
        ->toContain('America/Los_Angeles')
        ->toContain('T090000');
});
