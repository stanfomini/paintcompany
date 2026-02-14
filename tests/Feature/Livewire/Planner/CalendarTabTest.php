<?php

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('planner.view');
    $this->actingAs($this->user);
});

it('can switch to calendar view', function () {
    Livewire::test('planner.my-day')
        ->set('view', 'calendar')
        ->assertSet('calendarMode', 'month');
});

it('shows month calendar with day headers', function () {
    Livewire::test('planner.my-day')
        ->set('view', 'calendar')
        ->assertSee('Mon')
        ->assertSee('Tue')
        ->assertSee('Wed')
        ->assertSee('Thu')
        ->assertSee('Fri')
        ->assertSee('Sat')
        ->assertSee('Sun');
});

it('shows events on the calendar', function () {
    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Calendar Test Activity',
        'type' => 'task',
        'due_at' => now()->setDay(15)->setHour(10)->setMinute(0),
    ]);

    Livewire::test('planner.my-day')
        ->set('view', 'calendar')
        ->assertSee('Calendar Test Activity');
});

it('can navigate to next and previous months', function () {
    $component = Livewire::test('planner.my-day')
        ->set('view', 'calendar')
        ->set('calendarDate', '2026-02-14');

    $component->call('calendarNext')
        ->assertSet('calendarDate', '2026-03-14');

    $component->call('calendarPrev')
        ->assertSet('calendarDate', '2026-02-14');

    $component->call('calendarPrev')
        ->assertSet('calendarDate', '2026-01-14');
});

it('can switch calendar modes', function () {
    $component = Livewire::test('planner.my-day')
        ->set('view', 'calendar')
        ->set('calendarDate', '2026-02-14');

    // Month mode
    $component->set('calendarMode', 'month')
        ->assertSee('February 2026');

    // Week mode
    $component->set('calendarMode', 'week')
        ->assertSee('Feb');

    // Day mode
    $component->set('calendarMode', 'day')
        ->assertSee('14');
});

it('can go to today', function () {
    $component = Livewire::test('planner.my-day')
        ->set('view', 'calendar')
        ->set('calendarDate', '2025-01-01')
        ->assertSet('calendarDate', '2025-01-01');

    $component->call('calendarToday')
        ->assertSet('calendarDate', now()->format('Y-m-d'));
});

it('shows color legend', function () {
    Livewire::test('planner.my-day')
        ->set('view', 'calendar')
        ->assertSee('Tasks')
        ->assertSee('Meetings')
        ->assertSee('Calls');
});
