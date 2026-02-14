<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Job;
use App\Models\LifeCommitment;
use App\Models\User;
use App\Services\UnifiedCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->actingAs($this->user);
    $this->service = new UnifiedCalendarService;
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 10, 0, 0)); // Monday
});

afterEach(function () {
    Carbon::setTestNow();
});

it('returns activity events within date range', function () {
    $deal = Deal::factory()->create(['created_by' => $this->user->id]);

    Activity::factory()->task()->create([
        'user_id' => $this->user->id,
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'due_at' => Carbon::now()->addHours(2),
        'title' => 'Test Task',
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->startOfDay(),
        Carbon::now()->endOfDay(),
    );

    expect($events)->toHaveCount(1);
    expect($events->first()['title'])->toBe('Test Task');
    expect($events->first()['source'])->toBe('activity');
    expect($events->first()['type'])->toBe('task');
});

it('excludes activities outside date range', function () {
    Activity::factory()->task()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->addWeek(),
        'title' => 'Future Task',
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->startOfDay(),
        Carbon::now()->endOfDay(),
    );

    expect($events)->toHaveCount(0);
});

it('returns job events within date range', function () {
    $deal = Deal::factory()->create([
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
    ]);

    $targetDate = Carbon::create(2026, 3, 15);

    Job::factory()->create([
        'deal_id' => $deal->id,
        'scheduled_date' => $targetDate->toDateString(),
        'stage' => 'job_prep',
        'title' => 'Kitchen Paint Job',
    ]);

    $from = $targetDate->copy()->startOfDay();
    $to = $targetDate->copy()->endOfDay();

    $events = $this->service->getEvents($this->user, $from, $to);

    expect($events->where('source', 'job'))->toHaveCount(1);
    expect($events->where('source', 'job')->first()['title'])->toContain('Kitchen Paint Job');
});

it('excludes completed jobs', function () {
    $deal = Deal::factory()->create([
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
    ]);

    Job::factory()->completed()->create([
        'deal_id' => $deal->id,
        'scheduled_date' => Carbon::now()->toDateString(),
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->startOfDay(),
        Carbon::now()->endOfDay(),
    );

    expect($events->where('source', 'job'))->toHaveCount(0);
});

it('returns life commitment events on matching days of week', function () {
    // Monday = 1
    LifeCommitment::factory()->create([
        'commitment' => 'Morning Gym',
        'recurrence_days' => [1], // Monday
        'recurrence_time' => '07:00',
        'is_active' => true,
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->startOfDay(), // Monday
        Carbon::now()->endOfDay(),
    );

    expect($events->where('source', 'life_commitment'))->toHaveCount(1);
    expect($events->where('source', 'life_commitment')->first()['title'])->toBe('Morning Gym');
});

it('returns life commitment events on matching day of month', function () {
    LifeCommitment::factory()->create([
        'commitment' => 'Monthly Review',
        'recurrence_day_of_month' => 16, // matches testNow day
        'recurrence_time' => '09:00',
        'is_active' => true,
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->startOfDay(),
        Carbon::now()->endOfDay(),
    );

    expect($events->where('source', 'life_commitment'))->toHaveCount(1);
    expect($events->where('source', 'life_commitment')->first()['title'])->toBe('Monthly Review');
});

it('returns events sorted by start time', function () {
    $deal = Deal::factory()->create(['assigned_to' => $this->user->id]);

    Activity::factory()->create([
        'user_id' => $this->user->id,
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'due_at' => Carbon::now()->setTime(15, 0),
        'title' => 'Afternoon Task',
    ]);

    Activity::factory()->create([
        'user_id' => $this->user->id,
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'due_at' => Carbon::now()->setTime(8, 0),
        'title' => 'Morning Task',
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->startOfDay(),
        Carbon::now()->endOfDay(),
    );

    expect($events->first()['title'])->toBe('Morning Task');
    expect($events->last()['title'])->toBe('Afternoon Task');
});

it('marks overdue activities in meta', function () {
    Activity::factory()->overdue()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->subDays(2),
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->subWeek(),
        Carbon::now(),
    );

    expect($events)->toHaveCount(1);
    expect($events->first()['meta']['is_overdue'])->toBeTrue();
    expect($events->first()['color'])->toBe('#ef4444'); // red for overdue
});

it('colors completed activities gray', function () {
    Activity::factory()->completed()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->subHours(2),
        'completed_at' => Carbon::now()->subHour(),
    ]);

    $events = $this->service->getEvents(
        $this->user,
        Carbon::now()->subDay(),
        Carbon::now(),
    );

    expect($events)->toHaveCount(1);
    expect($events->first()['color'])->toBe('#9ca3af');
});

it('returns events for a specific date', function () {
    Activity::factory()->task()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->setTime(14, 0),
        'title' => 'Today Task',
    ]);

    Activity::factory()->task()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->addDay()->setTime(10, 0),
        'title' => 'Tomorrow Task',
    ]);

    $events = $this->service->getEventsForDate($this->user, Carbon::now());

    expect($events)->toHaveCount(1);
    expect($events->first()['title'])->toBe('Today Task');
});

it('returns events for current week', function () {
    Activity::factory()->task()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->addDays(2)->setTime(10, 0),
        'title' => 'This Week Task',
    ]);

    Activity::factory()->task()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->addWeeks(2)->setTime(10, 0),
        'title' => 'Next Week Task',
    ]);

    $events = $this->service->getEventsForWeek($this->user);

    expect($events->pluck('title')->toArray())->toContain('This Week Task');
    expect($events->pluck('title')->toArray())->not->toContain('Next Week Task');
});

it('returns events for current month', function () {
    Activity::factory()->task()->create([
        'user_id' => $this->user->id,
        'due_at' => Carbon::now()->addDays(5)->setTime(10, 0),
        'title' => 'This Month Task',
    ]);

    $events = $this->service->getEventsForMonth($this->user);

    expect($events->pluck('title')->toArray())->toContain('This Month Task');
});
