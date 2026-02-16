<?php

use App\Models\CompanySetting;
use App\Models\LifeCommitment;
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

it('renders the life commitments component', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertOk()
        ->assertSee('Life Commitments');
});

it('shows empty state when no commitments exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('No commitments yet');
});

it('can create a life commitment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->assertSet('showModal', true)
        ->set('area', 'Physical Health')
        ->set('commitment', 'Exercise 4x per week')
        ->set('action_type', 'weekly')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertSee('Physical Health')
        ->assertSee('Exercise 4x per week');

    $this->assertDatabaseHas('life_commitments', [
        'area' => 'Physical Health',
        'commitment' => 'Exercise 4x per week',
        'action_type' => 'weekly',
    ]);
});

it('can edit a life commitment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'area' => 'Family',
        'commitment' => 'Weekly date night',
        'action_type' => 'weekly',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('Family')
        ->call('openEditModal', $commitment->id)
        ->assertSet('showModal', true)
        ->assertSet('area', 'Family')
        ->assertSet('commitment', 'Weekly date night')
        ->set('area', 'Family & Relationships')
        ->set('commitment', 'Bi-weekly date night')
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('life_commitments', [
        'id' => $commitment->id,
        'area' => 'Family & Relationships',
        'commitment' => 'Bi-weekly date night',
    ]);
});

it('can delete a life commitment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'area' => 'Business',
        'commitment' => 'Review KPIs monthly',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('Business')
        ->call('delete', $commitment->id)
        ->assertDontSee('Review KPIs monthly');

    $this->assertDatabaseMissing('life_commitments', [
        'id' => $commitment->id,
    ]);
});

it('validates required fields', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', '')
        ->set('commitment', '')
        ->call('save')
        ->assertHasErrors(['area', 'commitment']);
});

it('defaults action type to ongoing', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSet('action_type', 'ongoing');
});

it('orders commitments by sort_order', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $companySettingId = CompanySetting::getInstance()->id;

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Second',
        'commitment' => 'Second commitment',
        'sort_order' => 2,
    ]);

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'First',
        'commitment' => 'First commitment',
        'sort_order' => 1,
    ]);

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Third',
        'commitment' => 'Third commitment',
        'sort_order' => 3,
    ]);

    $ordered = LifeCommitment::query()
        ->where('company_setting_id', $companySettingId)
        ->ordered()
        ->pluck('area')
        ->toArray();

    expect($ordered)->toBe(['First', 'Second', 'Third']);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSeeInOrder(['First', 'Second', 'Third']);
});

it('requires business_plan.manage permission to create', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->assertForbidden();
});

it('requires business_plan.manage permission to edit', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $commitment = LifeCommitment::factory()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openEditModal', $commitment->id)
        ->assertForbidden();
});

it('requires business_plan.manage permission to delete', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $commitment = LifeCommitment::factory()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('delete', $commitment->id)
        ->assertForbidden();
});

it('can move commitments up and down', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $companySettingId = CompanySetting::getInstance()->id;

    $first = LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'First',
        'sort_order' => 0,
    ]);

    $second = LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Second',
        'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('moveDown', $first->id);

    expect($first->fresh()->sort_order)->toBe(1);
    expect($second->fresh()->sort_order)->toBe(0);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('moveUp', $first->id);

    expect($first->fresh()->sort_order)->toBe(0);
    expect($second->fresh()->sort_order)->toBe(1);
});

it('validates action type against allowed values', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Test')
        ->set('commitment', 'Test commitment')
        ->set('action_type', 'invalid_type')
        ->call('save')
        ->assertHasErrors(['action_type']);
});

it('assigns incremental sort_order to new commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $companySettingId = CompanySetting::getInstance()->id;

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'sort_order' => 5,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'New Area')
        ->set('commitment', 'New commitment')
        ->call('save');

    $latest = LifeCommitment::where('area', 'New Area')->first();
    expect($latest->sort_order)->toBe(6);
});

it('can toggle commitment active status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('toggleActive', $commitment->id);

    expect($commitment->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('toggleActive', $commitment->id);

    expect($commitment->fresh()->is_active)->toBeTrue();
});

it('can create commitment with start_date', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Business')
        ->set('commitment', 'Quarterly review')
        ->set('action_type', 'quarterly')
        ->set('start_date', '2026-03-01')
        ->set('recurrence_day_of_month', 1)
        ->call('save')
        ->assertSet('showModal', false);

    $record = LifeCommitment::where('commitment', 'Quarterly review')->first();
    expect($record)->not->toBeNull();
    expect($record->start_date->format('Y-m-d'))->toBe('2026-03-01');
    expect($record->recurrence_day_of_month)->toBe(1);
});

it('can create weekly commitment with multiple recurrence days', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Physical Health')
        ->set('commitment', 'Go to gym')
        ->set('action_type', 'weekly')
        ->call('toggleDay', 2)
        ->call('toggleDay', 4)
        ->call('save')
        ->assertSet('showModal', false);

    $record = LifeCommitment::where('commitment', 'Go to gym')->first();
    expect($record->recurrence_days)->toBe([2, 4]);
});

it('can toggle days on and off', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('action_type', 'weekly')
        ->call('toggleDay', 1)
        ->assertSet('recurrence_days', [1])
        ->call('toggleDay', 3)
        ->assertSet('recurrence_days', [1, 3])
        ->call('toggleDay', 1)
        ->assertSet('recurrence_days', [3]);
});

it('clears recurrence_days when action type is not weekly', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Business')
        ->set('commitment', 'Daily standup')
        ->set('action_type', 'weekly')
        ->call('toggleDay', 3)
        ->set('action_type', 'daily')
        ->call('save');

    $record = LifeCommitment::where('commitment', 'Daily standup')->first();
    expect($record->recurrence_days)->toBeNull();
});

it('can set recurrence time on a commitment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Physical Health')
        ->set('commitment', 'Morning workout')
        ->set('action_type', 'daily')
        ->set('recurrence_time', '06:00')
        ->call('save')
        ->assertSet('showModal', false);

    $record = LifeCommitment::where('commitment', 'Morning workout')->first();
    expect($record->recurrence_time)->toBe('06:00');
});

it('clears recurrence time for ongoing action type', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Family')
        ->set('commitment', 'Quality time')
        ->set('action_type', 'ongoing')
        ->set('recurrence_time', '18:00')
        ->call('save');

    $record = LifeCommitment::where('commitment', 'Quality time')->first();
    expect($record->recurrence_time)->toBeNull();
});

it('groups commitments by area', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $companySettingId = CompanySetting::getInstance()->id;

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Business',
        'commitment' => 'Business item 1',
        'sort_order' => 0,
    ]);

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Family',
        'commitment' => 'Family item 1',
        'sort_order' => 1,
    ]);

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Business',
        'commitment' => 'Business item 2',
        'sort_order' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('Business')
        ->assertSee('Family')
        ->assertSee('Business item 1')
        ->assertSee('Business item 2')
        ->assertSee('Family item 1');
});

it('can switch to calendar view', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSet('viewMode', 'commitments')
        ->set('viewMode', 'calendar')
        ->assertSet('viewMode', 'calendar')
        ->assertSee('Mon')
        ->assertSee('Tue')
        ->assertSee('Wed');
});

it('can navigate calendar months', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $currentMonth = now()->month;
    $currentYear = now()->year;

    $component = Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->set('viewMode', 'calendar')
        ->assertSet('calendarMonth', $currentMonth)
        ->assertSet('calendarYear', $currentYear)
        ->call('nextMonth');

    $expectedDate = now()->addMonth();
    $component
        ->assertSet('calendarMonth', $expectedDate->month)
        ->assertSet('calendarYear', $expectedDate->year);

    $component->call('previousMonth');
    $component
        ->assertSet('calendarMonth', $currentMonth)
        ->assertSet('calendarYear', $currentYear);
});

it('requires business_plan.manage permission to toggle active', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $commitment = LifeCommitment::factory()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('toggleActive', $commitment->id)
        ->assertForbidden();
});

it('loads edit modal with recurrence fields', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'area' => 'Business',
        'commitment' => 'Weekly review',
        'action_type' => 'weekly',
        'is_active' => true,
        'start_date' => '2026-01-15',
        'recurrence_days' => [3, 5],
        'recurrence_time' => '14:00',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openEditModal', $commitment->id)
        ->assertSet('is_active', true)
        ->assertSet('start_date', '2026-01-15')
        ->assertSet('recurrence_days', [3, 5])
        ->assertSet('recurrence_time', '14:00');
});

it('shows paused commitments with reduced opacity class', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    LifeCommitment::factory()->create([
        'commitment' => 'Paused item',
        'is_active' => false,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('Paused item');
});

it('can create a one-time commitment with date', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Business')
        ->set('commitment', 'Renew business license')
        ->set('action_type', 'one_time')
        ->set('start_date', '2026-04-01')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertSee('Renew business license');

    $record = LifeCommitment::where('commitment', 'Renew business license')->first();
    expect($record)->not->toBeNull()
        ->and($record->action_type)->toBe('one_time')
        ->and($record->start_date->format('Y-m-d'))->toBe('2026-04-01')
        ->and($record->recurrence_days)->toBeNull()
        ->and($record->recurrence_day_of_month)->toBeNull()
        ->and($record->completed_at)->toBeNull();
});

it('requires start_date for one-time commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Business')
        ->set('commitment', 'Renew license')
        ->set('action_type', 'one_time')
        ->set('start_date', null)
        ->call('save')
        ->assertHasErrors(['start_date']);
});

it('can mark a one-time commitment complete', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'area' => 'Business',
        'commitment' => 'Dentist appointment',
        'action_type' => 'one_time',
        'start_date' => '2026-03-05',
        'completed_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('markComplete', $commitment->id);

    expect($commitment->fresh()->completed_at)->not->toBeNull();
});

it('can mark a completed one-time commitment incomplete', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'area' => 'Business',
        'commitment' => 'Dentist appointment',
        'action_type' => 'one_time',
        'start_date' => '2026-03-05',
        'completed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('markIncomplete', $commitment->id);

    expect($commitment->fresh()->completed_at)->toBeNull();
});

it('includes one-time commitment in calendar feed on its date only', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $targetDate = now()->addDays(7);

    LifeCommitment::factory()->create([
        'area' => 'Business',
        'commitment' => 'Tax filing deadline',
        'action_type' => 'one_time',
        'start_date' => $targetDate->format('Y-m-d'),
        'is_active' => true,
    ]);

    $service = new \App\Services\ActivityCalendarFeedService;
    $feed = $service->generateFeed($user);

    // The one-time event should appear in the feed
    expect($feed)->toContain('Tax filing deadline');

    // Should have exactly one VEVENT for this commitment
    $matches = substr_count($feed, 'Tax filing deadline');
    expect($matches)->toBe(1);
});

it('completed one-time tasks remain visible', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    LifeCommitment::factory()->create([
        'area' => 'Business',
        'commitment' => 'File quarterly taxes',
        'action_type' => 'one_time',
        'start_date' => '2026-03-15',
        'completed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('File quarterly taxes')
        ->assertSee('Completed');
});

it('requires business_plan.manage permission to mark complete', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $commitment = LifeCommitment::factory()->create([
        'action_type' => 'one_time',
        'start_date' => '2026-03-05',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('markComplete', $commitment->id)
        ->assertForbidden();
});

it('can create weekly commitment with time and multiple days', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Physical Health')
        ->set('commitment', 'Workout 2x a week')
        ->set('action_type', 'weekly')
        ->call('toggleDay', 2)
        ->call('toggleDay', 4)
        ->set('recurrence_time', '06:30')
        ->set('start_date', '2026-02-10')
        ->call('save')
        ->assertSet('showModal', false);

    $record = LifeCommitment::where('commitment', 'Workout 2x a week')->first();
    expect($record)->not->toBeNull()
        ->and($record->recurrence_days)->toBe([2, 4])
        ->and($record->recurrence_time)->toBe('06:30')
        ->and($record->start_date->format('Y-m-d'))->toBe('2026-02-10');
});
