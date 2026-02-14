<?php

use App\Automation\Waits\TimeDelay;
use App\Automation\Waits\WaitForCondition;
use App\Automation\Waits\WaitUntilDate;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Carbon\Carbon;

// --- TimeDelay ---

it('calculates resume at based on configured hours', function () {
    $run = WorkflowRun::factory()->create();
    $wait = new TimeDelay;

    Carbon::setTestNow('2026-02-14 10:00:00');

    $resumeAt = $wait->getResumeAt($run, ['hours' => 6]);

    expect($resumeAt->format('Y-m-d H:i:s'))->toBe('2026-02-14 16:00:00');

    Carbon::setTestNow();
});

it('defaults to 24 hours when not configured', function () {
    $run = WorkflowRun::factory()->create();
    $wait = new TimeDelay;

    Carbon::setTestNow('2026-02-14 10:00:00');

    $resumeAt = $wait->getResumeAt($run, []);

    expect($resumeAt->format('Y-m-d H:i:s'))->toBe('2026-02-15 10:00:00');

    Carbon::setTestNow();
});

it('resumes when scheduled_for is past', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowRunStep::factory()->create([
        'workflow_run_id' => $run->id,
        'scheduled_for' => now()->subHour(),
    ]);

    $wait = new TimeDelay;
    expect($wait->shouldResume($step, $run, []))->toBeTrue();
});

it('does not resume when scheduled_for is future', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowRunStep::factory()->create([
        'workflow_run_id' => $run->id,
        'scheduled_for' => now()->addHour(),
    ]);

    $wait = new TimeDelay;
    expect($wait->shouldResume($step, $run, []))->toBeFalse();
});

it('has correct metadata for TimeDelay', function () {
    expect(TimeDelay::getName())->toBe('Time Delay')
        ->and(TimeDelay::getCategory())->toBe('Time-Based')
        ->and(TimeDelay::getDescription())->toBeString()
        ->and(TimeDelay::getConfigSchema())->toHaveKey('hours');
});

// --- WaitUntilDate ---

it('calculates resume at from configured date', function () {
    $run = WorkflowRun::factory()->create();
    $wait = new WaitUntilDate;

    $resumeAt = $wait->getResumeAt($run, ['date' => '2026-03-01 09:00:00']);

    expect($resumeAt->format('Y-m-d H:i:s'))->toBe('2026-03-01 09:00:00');
});

it('resumes when configured date is past', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowRunStep::factory()->create([
        'workflow_run_id' => $run->id,
    ]);

    $wait = new WaitUntilDate;
    expect($wait->shouldResume($step, $run, ['date' => '2020-01-01 00:00:00']))->toBeTrue();
});

it('does not resume when configured date is future', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowRunStep::factory()->create([
        'workflow_run_id' => $run->id,
    ]);

    $wait = new WaitUntilDate;
    expect($wait->shouldResume($step, $run, ['date' => '2030-12-31 23:59:59']))->toBeFalse();
});

it('has correct metadata for WaitUntilDate', function () {
    expect(WaitUntilDate::getName())->toBe('Wait Until Date')
        ->and(WaitUntilDate::getCategory())->toBe('Time-Based')
        ->and(WaitUntilDate::getConfigSchema())->toHaveKey('date');
});

// --- WaitForCondition ---

it('returns resume at based on check interval', function () {
    $run = WorkflowRun::factory()->create();
    $wait = new WaitForCondition;

    Carbon::setTestNow('2026-02-14 10:00:00');

    $resumeAt = $wait->getResumeAt($run, ['check_interval_hours' => 2]);

    expect($resumeAt->format('Y-m-d H:i:s'))->toBe('2026-02-14 12:00:00');

    Carbon::setTestNow();
});

it('resumes when timeout is exceeded', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowRunStep::factory()->create([
        'workflow_run_id' => $run->id,
        'started_at' => now()->subHours(200),
    ]);

    $wait = new WaitForCondition;
    $result = $wait->shouldResume($step, $run, [
        'condition_class' => 'SomeNonexistentClass',
        'timeout_hours' => 168,
    ]);

    expect($result)->toBeTrue();
});

it('resumes when no condition class is configured', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowRunStep::factory()->create([
        'workflow_run_id' => $run->id,
        'started_at' => now(),
    ]);

    $wait = new WaitForCondition;
    $result = $wait->shouldResume($step, $run, []);

    expect($result)->toBeTrue();
});

it('has correct metadata for WaitForCondition', function () {
    expect(WaitForCondition::getName())->toBe('Wait for Condition')
        ->and(WaitForCondition::getCategory())->toBe('Condition-Based')
        ->and(WaitForCondition::getConfigSchema())->toHaveKeys(['condition_class', 'condition_config', 'timeout_hours', 'check_interval_hours']);
});
