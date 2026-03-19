<?php

use App\Automation\DynamicValues\ContactValues;
use App\Automation\DynamicValues\DateTimeValues;
use App\Automation\DynamicValues\DealValues;
use App\Automation\DynamicValues\JobValues;
use App\Automation\DynamicValues\WorkflowValues;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Carbon\Carbon;

// --- ContactValues ---

it('resolves contact values from a deal subject', function () {
    $user = User::factory()->customer()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $resolver = new ContactValues;

    expect($resolver->resolve('name', $run))->toBe('John Doe')
        ->and($resolver->resolve('email', $run))->toBe('john@example.com');
});

it('resolves contact values from a customer subject', function () {
    $user = User::factory()->customer()->create(['name' => 'Jane Smith']);
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $resolver = new ContactValues;
    expect($resolver->resolve('name', $run))->toBe('Jane Smith');
});

it('returns null for unknown contact key', function () {
    $user = User::factory()->internal()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => User::class,
        'subject_id' => $user->id,
    ]);

    $resolver = new ContactValues;
    expect($resolver->resolve('unknown_key', $run))->toBeNull();
});

it('has correct prefix and keys for ContactValues', function () {
    expect(ContactValues::getPrefix())->toBe('contact')
        ->and(ContactValues::getAvailableKeys())->toBe(['name', 'email', 'phone', 'messenger_psid']);
});

// --- DealValues ---

it('resolves deal values from a deal subject', function () {
    $deal = Deal::factory()->create([
        'title' => 'Kitchen Repaint',
        'stage' => 'quoted',
        'estimated_value' => 5000.00,
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $resolver = new DealValues;

    expect($resolver->resolve('title', $run))->toBe('Kitchen Repaint')
        ->and($resolver->resolve('stage', $run))->toBe('quoted')
        ->and($resolver->resolve('stage_label', $run))->toBe('Quoted')
        ->and($resolver->resolve('estimated_value', $run))->toBe('5,000.00');
});

it('returns null deal values when subject is not a deal', function () {
    $user = User::factory()->internal()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => User::class,
        'subject_id' => $user->id,
    ]);

    $resolver = new DealValues;
    expect($resolver->resolve('title', $run))->toBeNull();
});

it('has correct prefix and keys for DealValues', function () {
    expect(DealValues::getPrefix())->toBe('deal')
        ->and(DealValues::getAvailableKeys())->toBe(['title', 'stage', 'estimated_value', 'stage_label']);
});

// --- JobValues ---

it('resolves job values from a job subject', function () {
    $job = Job::factory()->create([
        'title' => 'Exterior Paint Job',
        'stage' => 'in_progress',
        'scheduled_date' => '2026-03-15',
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Job::class,
        'subject_id' => $job->id,
    ]);

    $resolver = new JobValues;

    expect($resolver->resolve('title', $run))->toBe('Exterior Paint Job')
        ->and($resolver->resolve('stage', $run))->toBe('in_progress')
        ->and($resolver->resolve('stage_label', $run))->toBe('In Progress')
        ->and($resolver->resolve('scheduled_date', $run))->toBe('Mar 15, 2026');
});

it('returns null job values when subject is not a job', function () {
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $resolver = new JobValues;
    expect($resolver->resolve('title', $run))->toBeNull();
});

it('has correct prefix and keys for JobValues', function () {
    expect(JobValues::getPrefix())->toBe('job')
        ->and(JobValues::getAvailableKeys())->toBe(['title', 'stage', 'stage_label', 'scheduled_date']);
});

// --- DateTimeValues ---

it('resolves datetime values correctly', function () {
    Carbon::setTestNow('2026-02-14 10:30:00');

    $run = WorkflowRun::factory()->create();
    $resolver = new DateTimeValues;

    expect($resolver->resolve('now', $run))->toBe('Feb 14, 2026 10:30 AM')
        ->and($resolver->resolve('today', $run))->toBe('Feb 14, 2026')
        ->and($resolver->resolve('tomorrow', $run))->toBe('Feb 15, 2026')
        ->and($resolver->resolve('day_of_week', $run))->toBe('Saturday');

    Carbon::setTestNow();
});

it('returns null for unknown datetime key', function () {
    $run = WorkflowRun::factory()->create();
    $resolver = new DateTimeValues;

    expect($resolver->resolve('unknown', $run))->toBeNull();
});

it('has correct prefix and keys for DateTimeValues', function () {
    expect(DateTimeValues::getPrefix())->toBe('datetime')
        ->and(DateTimeValues::getAvailableKeys())->toBe(['now', 'today', 'tomorrow', 'day_of_week']);
});

// --- WorkflowValues ---

it('resolves workflow values from the run', function () {
    $workflow = Workflow::factory()->create(['name' => 'Welcome Sequence']);

    Carbon::setTestNow('2026-02-14 10:00:00');

    $run = WorkflowRun::factory()->create([
        'workflow_id' => $workflow->id,
        'triggered_at' => now(),
    ]);

    $resolver = new WorkflowValues;

    expect($resolver->resolve('name', $run))->toBe('Welcome Sequence')
        ->and($resolver->resolve('triggered_at', $run))->toBe('Feb 14, 2026 10:00 AM')
        ->and($resolver->resolve('run_id', $run))->toBe((string) $run->id);

    Carbon::setTestNow();
});

it('has correct prefix and keys for WorkflowValues', function () {
    expect(WorkflowValues::getPrefix())->toBe('workflow')
        ->and(WorkflowValues::getAvailableKeys())->toBe(['name', 'triggered_at', 'run_id']);
});

// --- PreviewValues ---

it('resolves preview portal_url from a deal subject', function () {
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $preview = \App\Models\ProjectPreview::where('deal_id', $deal->id)->first();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $resolver = new \App\Automation\DynamicValues\PreviewValues;

    expect($resolver->resolve('portal_url', $run))->toBe($preview->getPortalUrl())
        ->and($resolver->resolve('customer_name', $run))->toBe(trim($preview->first_name.' '.$preview->last_name))
        ->and($resolver->resolve('status', $run))->toBe('active');
});

it('returns null for preview values when no preview exists', function () {
    $deal = Deal::factory()->create(['customer_id' => null]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $resolver = new \App\Automation\DynamicValues\PreviewValues;
    expect($resolver->resolve('portal_url', $run))->toBeNull();
});

it('has correct prefix and keys for PreviewValues', function () {
    expect(\App\Automation\DynamicValues\PreviewValues::getPrefix())->toBe('preview')
        ->and(\App\Automation\DynamicValues\PreviewValues::getAvailableKeys())->toBe(['portal_url', 'customer_name', 'status']);
});
