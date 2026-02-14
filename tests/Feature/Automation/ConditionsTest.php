<?php

use App\Automation\Conditions\DealStageIs;
use App\Automation\Conditions\EstimateValueAbove;
use App\Automation\Conditions\FieldValue;
use App\Automation\Conditions\HasNoActivityIn;
use App\Automation\Conditions\IsBusinessHours;
use App\Automation\Conditions\TimeSinceEvent;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// --- FieldValue ---

it('evaluates equals operator correctly', function () {
    $deal = Deal::factory()->stage('quoted')->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'stage', 'operator' => '=', 'value' => 'quoted']))
        ->toBeTrue()
        ->and($condition->evaluate($run, ['field' => 'stage', 'operator' => '=', 'value' => 'lead']))
        ->toBeFalse();
});

it('evaluates not-equals operator correctly', function () {
    $deal = Deal::factory()->stage('quoted')->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'stage', 'operator' => '!=', 'value' => 'lead']))
        ->toBeTrue();
});

it('evaluates contains operator correctly', function () {
    $deal = Deal::factory()->create(['title' => 'Exterior Paint Job']);
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'title', 'operator' => 'contains', 'value' => 'Paint']))
        ->toBeTrue()
        ->and($condition->evaluate($run, ['field' => 'title', 'operator' => 'contains', 'value' => 'Interior']))
        ->toBeFalse();
});

it('evaluates starts_with operator correctly', function () {
    $deal = Deal::factory()->create(['title' => 'Exterior Paint Job']);
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'title', 'operator' => 'starts_with', 'value' => 'Exterior']))
        ->toBeTrue()
        ->and($condition->evaluate($run, ['field' => 'title', 'operator' => 'starts_with', 'value' => 'Interior']))
        ->toBeFalse();
});

it('returns false for FieldValue when subject is null', function () {
    $run = WorkflowRun::factory()->create([
        'subject_type' => null,
        'subject_id' => null,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'stage', 'operator' => '=', 'value' => 'lead']))
        ->toBeFalse();
});

it('has correct metadata for FieldValue', function () {
    expect(FieldValue::getName())->toBe('Field Value')
        ->and(FieldValue::getCategory())->toBe('Field Checks')
        ->and(FieldValue::getConfigSchema())->toHaveKeys(['field', 'operator', 'value']);
});

// --- DealStageIs ---

it('matches when deal stage is in configured stages', function () {
    $deal = Deal::factory()->stage('quoted')->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new DealStageIs;

    expect($condition->evaluate($run, ['stages' => ['quoted', 'signed']]))
        ->toBeTrue();
});

it('does not match when deal stage is not in configured stages', function () {
    $deal = Deal::factory()->stage('lead')->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new DealStageIs;

    expect($condition->evaluate($run, ['stages' => ['quoted', 'signed']]))
        ->toBeFalse();
});

it('resolves deal from context deal_id', function () {
    $deal = Deal::factory()->stage('quoted')->create();
    $estimate = Estimate::factory()->forDeal($deal)->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Estimate::class,
        'subject_id' => $estimate->id,
        'context' => ['deal_id' => $deal->id],
    ]);

    $condition = new DealStageIs;

    expect($condition->evaluate($run, ['stages' => ['quoted']]))
        ->toBeTrue();
});

it('returns false for DealStageIs with empty stages config', function () {
    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new DealStageIs;

    expect($condition->evaluate($run, ['stages' => []]))->toBeFalse();
});

it('has correct metadata for DealStageIs', function () {
    expect(DealStageIs::getName())->toBe('Deal Stage Is')
        ->and(DealStageIs::getCategory())->toBe('Deal Conditions')
        ->and(DealStageIs::getConfigSchema())->toHaveKey('stages');
});

// --- TimeSinceEvent ---

it('passes when elapsed time exceeds threshold', function () {
    $run = WorkflowRun::factory()->create([
        'triggered_at' => Carbon::now()->subHours(5),
    ]);

    $condition = new TimeSinceEvent;

    expect($condition->evaluate($run, ['hours' => 3, 'operator' => '>']))
        ->toBeTrue();
});

it('fails when elapsed time is under threshold', function () {
    $run = WorkflowRun::factory()->create([
        'triggered_at' => Carbon::now()->subHour(),
    ]);

    $condition = new TimeSinceEvent;

    expect($condition->evaluate($run, ['hours' => 3, 'operator' => '>']))
        ->toBeFalse();
});

it('passes less-than operator when within threshold', function () {
    $run = WorkflowRun::factory()->create([
        'triggered_at' => Carbon::now()->subHour(),
    ]);

    $condition = new TimeSinceEvent;

    expect($condition->evaluate($run, ['hours' => 3, 'operator' => '<']))
        ->toBeTrue();
});

it('returns false for TimeSinceEvent with null triggered_at', function () {
    $run = WorkflowRun::factory()->create([
        'triggered_at' => null,
    ]);

    $condition = new TimeSinceEvent;

    expect($condition->evaluate($run, ['hours' => 1, 'operator' => '>']))
        ->toBeFalse();
});

it('has correct metadata for TimeSinceEvent', function () {
    expect(TimeSinceEvent::getName())->toBe('Time Since Event')
        ->and(TimeSinceEvent::getCategory())->toBe('Time Conditions')
        ->and(TimeSinceEvent::getConfigSchema())->toHaveKeys(['hours', 'operator']);
});

// --- HasNoActivityIn ---

it('returns true when subject has no recent activity', function () {
    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new HasNoActivityIn;

    expect($condition->evaluate($run, ['days' => 7]))->toBeTrue();
});

it('returns false when subject has recent activity', function () {
    $deal = Deal::factory()->create();
    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'created_at' => Carbon::now()->subDays(2),
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new HasNoActivityIn;

    expect($condition->evaluate($run, ['days' => 7]))->toBeFalse();
});

it('returns true when activity is older than threshold', function () {
    $deal = Deal::factory()->create();
    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'created_at' => Carbon::now()->subDays(10),
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new HasNoActivityIn;

    expect($condition->evaluate($run, ['days' => 7]))->toBeTrue();
});

it('has correct metadata for HasNoActivityIn', function () {
    expect(HasNoActivityIn::getName())->toBe('Has No Activity In')
        ->and(HasNoActivityIn::getCategory())->toBe('Activity Conditions')
        ->and(HasNoActivityIn::getConfigSchema())->toHaveKey('days');
});

// --- EstimateValueAbove ---

it('returns true when estimate total exceeds threshold', function () {
    $estimate = Estimate::factory()->create(['total' => 5000.00]);
    $run = WorkflowRun::factory()->create([
        'subject_type' => Estimate::class,
        'subject_id' => $estimate->id,
    ]);

    $condition = new EstimateValueAbove;

    expect($condition->evaluate($run, ['threshold' => 3000]))->toBeTrue();
});

it('returns false when estimate total is below threshold', function () {
    $estimate = Estimate::factory()->create(['total' => 1000.00]);
    $run = WorkflowRun::factory()->create([
        'subject_type' => Estimate::class,
        'subject_id' => $estimate->id,
    ]);

    $condition = new EstimateValueAbove;

    expect($condition->evaluate($run, ['threshold' => 3000]))->toBeFalse();
});

it('resolves estimate from deal subject', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create(['total' => 8000.00]);
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new EstimateValueAbove;

    expect($condition->evaluate($run, ['threshold' => 5000]))->toBeTrue();
});

it('resolves estimate from context estimate_id', function () {
    $estimate = Estimate::factory()->create(['total' => 8000.00]);
    $run = WorkflowRun::factory()->create([
        'context' => ['estimate_id' => $estimate->id],
    ]);

    $condition = new EstimateValueAbove;

    expect($condition->evaluate($run, ['threshold' => 5000]))->toBeTrue();
});

it('has correct metadata for EstimateValueAbove', function () {
    expect(EstimateValueAbove::getName())->toBe('Estimate Value Above')
        ->and(EstimateValueAbove::getCategory())->toBe('Estimate Conditions')
        ->and(EstimateValueAbove::getConfigSchema())->toHaveKey('threshold');
});

// --- IsBusinessHours ---

it('returns true during business hours', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 10, 0, 0)); // Monday 10am

    $run = WorkflowRun::factory()->create();
    $condition = new IsBusinessHours;

    expect($condition->evaluate($run, []))->toBeTrue();

    Carbon::setTestNow();
});

it('returns false outside business hours', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 20, 0, 0)); // Monday 8pm

    $run = WorkflowRun::factory()->create();
    $condition = new IsBusinessHours;

    expect($condition->evaluate($run, []))->toBeFalse();

    Carbon::setTestNow();
});

it('returns false on weekends with default config', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 10, 0, 0)); // Saturday 10am

    $run = WorkflowRun::factory()->create();
    $condition = new IsBusinessHours;

    expect($condition->evaluate($run, []))->toBeFalse();

    Carbon::setTestNow();
});

it('respects custom hours and days', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 14, 10, 0, 0)); // Saturday 10am

    $run = WorkflowRun::factory()->create();
    $condition = new IsBusinessHours;

    expect($condition->evaluate($run, [
        'start_hour' => 8,
        'end_hour' => 12,
        'days' => [0, 6], // Sunday, Saturday
    ]))->toBeTrue();

    Carbon::setTestNow();
});

it('has correct metadata for IsBusinessHours', function () {
    expect(IsBusinessHours::getName())->toBe('Is Business Hours')
        ->and(IsBusinessHours::getCategory())->toBe('Business Rules')
        ->and(IsBusinessHours::getConfigSchema())->toHaveKeys(['start_hour', 'end_hour', 'days']);
});
