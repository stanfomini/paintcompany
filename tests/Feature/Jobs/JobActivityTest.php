<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\User;
use App\Services\JobDispatchService;
use App\Services\JobService;
use Illuminate\Support\Facades\Notification;

it('can log an activity for a job', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $job = Job::factory()->create();

    $activity = JobActivity::log($job, 'stage_changed', 'Test activity', [
        'from' => 'estimate_accepted',
        'to' => 'scheduling_colors',
    ]);

    expect($activity)->toBeInstanceOf(JobActivity::class)
        ->and($activity->job_id)->toBe($job->id)
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->activity_type)->toBe('stage_changed')
        ->and($activity->description)->toBe('Test activity')
        ->and($activity->metadata)->toBe([
            'from' => 'estimate_accepted',
            'to' => 'scheduling_colors',
        ]);
});

it('logs activity when job is created from estimate', function () {
    Notification::fake();

    User::factory()->internal()->withFullAccess()->create();
    CompanySetting::getInstance();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

    $service = app(JobService::class);
    $job = $service->createFromEstimate($estimate);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'stage_changed')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Job created from accepted estimate')
        ->and($activity->metadata['stage'])->toBe('estimate_accepted')
        ->and($activity->metadata['estimate_id'])->toBe($estimate->id);
});

it('logs activity when stage advances', function () {
    Notification::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $job = Job::factory()->create(['stage' => 'estimate_accepted']);

    $service = app(JobService::class);
    $service->advanceStage($job);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'stage_changed')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['from'])->toBe('estimate_accepted')
        ->and($activity->metadata['to'])->toBe('scheduling_colors');
});

it('logs activity when company confirms date', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $job = Job::factory()->create(['company_date_confirmed' => false]);

    app(JobService::class)->confirmCompanyDate($job);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'date_confirmed_company')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Company confirmed the scheduled date');
});

it('logs activity when customer confirms date', function () {
    Notification::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $job = Job::factory()->create(['customer_date_confirmed' => false]);

    app(JobService::class)->confirmCustomerDate($job);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'date_confirmed_customer')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Customer confirmed the scheduled date');
});

it('logs activity when customer proposes date', function () {
    Notification::fake();

    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $assignedUser = User::factory()->internal()->withFullAccess()->create();
    $deal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $assignedUser->id,
    ]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'stage' => 'scheduling_colors',
    ]);

    $this->actingAs($customerUser);
    app(JobService::class)->proposeCustomerDate($job, \Carbon\Carbon::now()->addWeeks(2));

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'date_proposed')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['proposed_by'])->toBe('customer');
});

it('logs activity when company confirms colors', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $job = Job::factory()->create(['company_colors_confirmed' => false]);

    app(JobService::class)->confirmCompanyColors($job);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'colors_confirmed_company')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Company confirmed color selections');
});

it('logs activity when customer confirms colors', function () {
    Notification::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $job = Job::factory()->create(['customer_colors_confirmed' => false]);

    app(JobService::class)->confirmCustomerColors($job);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'colors_confirmed_customer')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Customer confirmed color selections');
});

it('logs activity when job is completed', function () {
    Notification::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $deal = Deal::factory()->create(['stage' => 'in_progress']);
    $job = Job::factory()->inProgress()->create(['deal_id' => $deal->id]);

    app(JobService::class)->completeJob($job);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'completed')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Job marked as completed');
});

it('logs activity when crew is dispatched', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['crew_size' => 3]);

    $job = Job::factory()->create(['required_crew_size' => 2]);

    $service = new JobDispatchService;
    $service->dispatchToCrews($job, [$crew->id]);

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'crew_dispatched')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['dispatched_count'])->toBe(1)
        ->and($activity->metadata['crew_ids'])->toContain($crew->id);
});

it('logs activity when crew accepts job', function () {
    $crew = User::factory()->crew()->create();
    $job = Job::factory()->create();

    $service = new JobDispatchService;
    $service->dispatchToCrews($job, [$crew->id]);
    $service->acceptJob($job, $crew, 'Ready to go');

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'crew_accepted')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['crew_user_id'])->toBe($crew->id)
        ->and($activity->metadata['crew_name'])->toBe($crew->name);
});

it('logs activity when crew rejects job', function () {
    $crew = User::factory()->crew()->create();
    $job = Job::factory()->create();

    $service = new JobDispatchService;
    $service->dispatchToCrews($job, [$crew->id]);
    $service->rejectJob($job, $crew, 'Too far away');

    $activity = JobActivity::where('job_id', $job->id)
        ->where('activity_type', 'crew_rejected')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['crew_user_id'])->toBe($crew->id)
        ->and($activity->metadata['notes'])->toBe('Too far away');
});

it('has correct relationship to job', function () {
    $job = Job::factory()->create();
    $activity = JobActivity::factory()->forJob($job)->create([
        'activity_type' => 'stage_changed',
    ]);

    expect($activity->job)->toBeInstanceOf(Job::class)
        ->and($activity->job->id)->toBe($job->id);
});

it('can filter by activity type', function () {
    $job = Job::factory()->create();

    JobActivity::factory()->forJob($job)->create(['activity_type' => 'stage_changed']);
    JobActivity::factory()->forJob($job)->create(['activity_type' => 'stage_changed']);
    JobActivity::factory()->forJob($job)->create(['activity_type' => 'date_proposed']);
    JobActivity::factory()->forJob($job)->create(['activity_type' => 'completed']);

    $stageActivities = JobActivity::where('job_id', $job->id)
        ->byType('stage_changed')
        ->get();

    $dateActivities = JobActivity::where('job_id', $job->id)
        ->byType('date_proposed')
        ->get();

    expect($stageActivities)->toHaveCount(2)
        ->and($dateActivities)->toHaveCount(1);
});
