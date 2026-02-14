<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\SlaMetric;
use App\Models\User;
use App\Services\SlaMetricService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SlaMetricService;
    $this->user = User::factory()->internal()->create();
});

it('calculates speed to lead for a user', function () {
    $dealCreatedAt = Carbon::today()->setTime(9, 0);
    $deal = Deal::factory()->create([
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
        'created_at' => $dealCreatedAt,
    ]);

    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'user_id' => $this->user->id,
        'type' => 'call',
        'created_at' => Carbon::today()->setTime(9, 30),
    ]);

    $metric = $this->service->calculateSpeedToLead($this->user, Carbon::today());

    expect($metric)->not->toBeNull()
        ->and($metric->user_id)->toBe($this->user->id)
        ->and($metric->metric_type)->toBe('speed_to_lead')
        ->and($metric->period)->toBe('daily')
        ->and((float) $metric->value)->toBe(30.0)
        ->and($metric->sample_count)->toBe(1);
});

it('returns null speed to lead when no deals', function () {
    $metric = $this->service->calculateSpeedToLead($this->user, Carbon::today());

    expect($metric)->toBeNull();
});

it('calculates follow-up compliance', function () {
    $dueDate = Carbon::today()->setTime(17, 0);

    // Completed on time
    Activity::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'task',
        'due_at' => $dueDate,
        'is_completed' => true,
        'completed_at' => $dueDate->copy()->subHour(),
    ]);

    // Not completed
    Activity::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'call',
        'due_at' => $dueDate,
        'is_completed' => false,
        'completed_at' => null,
    ]);

    $metric = $this->service->calculateFollowUpCompliance($this->user, Carbon::today());

    expect($metric)->not->toBeNull()
        ->and($metric->metric_type)->toBe('follow_up_compliance')
        ->and((float) $metric->value)->toBe(50.0)
        ->and($metric->sample_count)->toBe(2);
});

it('returns null follow-up compliance when no activities due', function () {
    $metric = $this->service->calculateFollowUpCompliance($this->user, Carbon::today());

    expect($metric)->toBeNull();
});

it('calculates response time', function () {
    $createdAt = Carbon::today()->setTime(9, 0);
    $completedAt = Carbon::today()->setTime(9, 45);

    Activity::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'task',
        'is_completed' => true,
        'created_at' => $createdAt,
        'completed_at' => $completedAt,
    ]);

    $metric = $this->service->calculateResponseTime($this->user, Carbon::today());

    expect($metric)->not->toBeNull()
        ->and($metric->metric_type)->toBe('response_time')
        ->and((float) $metric->value)->toBe(45.0)
        ->and($metric->sample_count)->toBe(1);
});

it('calculates estimate turnaround', function () {
    $dealCreatedAt = Carbon::today()->setTime(9, 0);
    $deal = Deal::factory()->create([
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
        'created_at' => $dealCreatedAt,
    ]);

    Estimate::factory()->create([
        'deal_id' => $deal->id,
        'sent_at' => Carbon::today()->setTime(13, 0),
    ]);

    $metric = $this->service->calculateEstimateTurnaround($this->user, Carbon::today());

    expect($metric)->not->toBeNull()
        ->and($metric->metric_type)->toBe('estimate_turnaround')
        ->and((float) $metric->value)->toBe(4.0)
        ->and($metric->sample_count)->toBe(1);
});

it('calculates daily metrics for all internal users', function () {
    $user2 = User::factory()->internal()->create();

    $deal1 = Deal::factory()->create([
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
        'created_at' => Carbon::today()->setTime(9, 0),
    ]);

    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal1->id,
        'user_id' => $this->user->id,
        'type' => 'call',
        'created_at' => Carbon::today()->setTime(9, 30),
    ]);

    $deal2 = Deal::factory()->create([
        'assigned_to' => $user2->id,
        'created_by' => $user2->id,
        'created_at' => Carbon::today()->setTime(10, 0),
    ]);

    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal2->id,
        'user_id' => $user2->id,
        'type' => 'email',
        'created_at' => Carbon::today()->setTime(10, 15),
    ]);

    $this->service->calculateDaily(Carbon::today());

    $metrics = SlaMetric::where('period_date', Carbon::today())->get();

    // At least metrics for both users (speed_to_lead)
    expect($metrics->where('user_id', $this->user->id)->isNotEmpty())->toBeTrue()
        ->and($metrics->where('user_id', $user2->id)->isNotEmpty())->toBeTrue();
});

it('returns dashboard metrics with activity counts', function () {
    // Overdue activity
    Activity::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'task',
        'due_at' => Carbon::yesterday(),
        'is_completed' => false,
    ]);

    // Due today (end of day so not yet overdue)
    Activity::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'task',
        'due_at' => Carbon::today()->setTime(23, 59),
        'is_completed' => false,
    ]);

    // Completed today
    Activity::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'task',
        'is_completed' => true,
        'completed_at' => Carbon::now(),
    ]);

    $metrics = $this->service->getDashboardMetrics($this->user, 7);

    expect($metrics)->toBeArray()
        ->and($metrics['overdue_count'])->toBe(1)
        ->and($metrics['due_today_count'])->toBe(1)
        ->and($metrics['completed_today_count'])->toBe(1);
});

it('returns weighted average in dashboard metrics', function () {
    SlaMetric::factory()->create([
        'user_id' => $this->user->id,
        'metric_type' => 'speed_to_lead',
        'period' => 'daily',
        'period_date' => Carbon::today()->subDays(1),
        'value' => 30.0,
        'sample_count' => 5,
    ]);

    SlaMetric::factory()->create([
        'user_id' => $this->user->id,
        'metric_type' => 'speed_to_lead',
        'period' => 'daily',
        'period_date' => Carbon::today()->subDays(2),
        'value' => 40.0,
        'sample_count' => 3,
    ]);

    SlaMetric::factory()->create([
        'user_id' => $this->user->id,
        'metric_type' => 'speed_to_lead',
        'period' => 'daily',
        'period_date' => Carbon::today()->subDays(3),
        'value' => 50.0,
        'sample_count' => 2,
    ]);

    // Weighted average = (150 + 120 + 100) / (5 + 3 + 2) = 37.0
    $metrics = $this->service->getDashboardMetrics($this->user, 7);

    expect($metrics)->toBeArray()
        ->and($metrics['speed_to_lead'])->toBe(37.0);
});
