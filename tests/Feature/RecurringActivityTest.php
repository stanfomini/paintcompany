<?php

use App\Models\Activity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('identifies recurring activities', function () {
    $activity = Activity::factory()->recurring('daily')->create([
        'user_id' => $this->user->id,
    ]);

    expect($activity->isRecurring())->toBeTrue();
});

it('identifies non-recurring activities', function () {
    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => null,
    ]);

    expect($activity->isRecurring())->toBeFalse();
});

it('calculates next due date for daily recurrence', function () {
    $monday = Carbon::parse('2026-02-16 10:00:00'); // Monday

    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'daily',
        'due_at' => $monday,
    ]);

    $nextDueDate = $activity->calculateNextDueDate();

    expect($nextDueDate->toDateString())->toBe('2026-02-17'); // Tuesday
    expect($nextDueDate->format('H:i:s'))->toBe('10:00:00');
});

it('calculates next due date for weekday recurrence', function () {
    $friday = Carbon::parse('2026-02-13 14:30:00'); // Friday

    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'weekdays',
        'due_at' => $friday,
    ]);

    $nextDueDate = $activity->calculateNextDueDate();

    expect($nextDueDate->toDateString())->toBe('2026-02-16'); // Monday
    expect($nextDueDate->format('H:i:s'))->toBe('14:30:00');
});

it('calculates next due date for weekly recurrence', function () {
    $startDate = Carbon::parse('2026-02-14 09:00:00');

    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'weekly',
        'due_at' => $startDate,
    ]);

    $nextDueDate = $activity->calculateNextDueDate();

    expect($nextDueDate->toDateString())->toBe('2026-02-21'); // +7 days
    expect($nextDueDate->format('H:i:s'))->toBe('09:00:00');
});

it('calculates next due date for biweekly recurrence', function () {
    $startDate = Carbon::parse('2026-02-14 11:00:00');

    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'biweekly',
        'due_at' => $startDate,
    ]);

    $nextDueDate = $activity->calculateNextDueDate();

    expect($nextDueDate->toDateString())->toBe('2026-02-28'); // +14 days
    expect($nextDueDate->format('H:i:s'))->toBe('11:00:00');
});

it('calculates next due date for monthly recurrence', function () {
    $startDate = Carbon::parse('2026-02-14 16:00:00');

    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'monthly',
        'due_at' => $startDate,
    ]);

    $nextDueDate = $activity->calculateNextDueDate();

    expect($nextDueDate->toDateString())->toBe('2026-03-14'); // +1 month
    expect($nextDueDate->format('H:i:s'))->toBe('16:00:00');
});

it('creates next occurrence when completed', function () {
    $startDate = Carbon::parse('2026-02-14 10:00:00');

    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Daily Check-in',
        'description' => 'Call customer for status update',
        'recurrence_rule' => 'daily',
        'due_at' => $startDate,
        'completed_at' => null,
    ]);

    expect(Activity::count())->toBe(1);

    $activity->markAsCompleted();

    expect(Activity::count())->toBe(2);

    $activity->refresh();
    expect($activity->completed_at)->not->toBeNull();

    $nextOccurrence = Activity::where('id', '!=', $activity->id)->first();
    expect($nextOccurrence)->not->toBeNull();
    expect($nextOccurrence->title)->toBe('Daily Check-in');
    expect($nextOccurrence->description)->toBe('Call customer for status update');
    expect($nextOccurrence->user_id)->toBe($this->user->id);
    expect($nextOccurrence->due_at->toDateString())->toBe('2026-02-15');
    expect($nextOccurrence->completed_at)->toBeNull();
});

it('preserves recurrence rule in next occurrence', function () {
    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'weekly',
        'due_at' => now(),
    ]);

    $activity->markAsCompleted();

    $nextOccurrence = Activity::where('id', '!=', $activity->id)->first();

    expect($nextOccurrence->recurrence_rule)->toBe('weekly');
});

it('sets recurrence_parent_id on child', function () {
    $parentActivity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'daily',
        'due_at' => now(),
    ]);

    $parentActivity->markAsCompleted();

    $childActivity = Activity::where('id', '!=', $parentActivity->id)->first();

    expect($childActivity->recurrence_parent_id)->toBe($parentActivity->id);
});

it('does not create occurrence for non-recurring activity', function () {
    $activity = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => null,
        'due_at' => now(),
    ]);

    expect(Activity::count())->toBe(1);

    $activity->markAsCompleted();

    expect(Activity::count())->toBe(1);

    $activity->refresh();
    expect($activity->completed_at)->not->toBeNull();
});

it('links parent and children via relationships', function () {
    $parent = Activity::factory()->create([
        'user_id' => $this->user->id,
        'recurrence_rule' => 'daily',
        'due_at' => now(),
    ]);

    $parent->markAsCompleted();

    $child = Activity::where('recurrence_parent_id', $parent->id)->first();

    // Test parent relationship
    expect($child->recurrenceParent->id)->toBe($parent->id);

    // Test children relationship
    expect($parent->recurrenceChildren->count())->toBe(1);
    expect($parent->recurrenceChildren->first()->id)->toBe($child->id);
});
