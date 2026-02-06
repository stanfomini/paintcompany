<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Notifications\CustomerConfirmedColors;
use App\Notifications\CustomerConfirmedDate;
use App\Notifications\CustomerProposedDate;
use App\Notifications\JobCompleted;
use App\Notifications\JobStageAdvanced;
use App\Services\JobService;
use Illuminate\Support\Facades\Notification;

it('notifies assigned user when customer proposes date', function () {
    Notification::fake();

    $assignedUser = User::factory()->internal()->withFullAccess()->create();
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $deal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $assignedUser->id,
    ]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'stage' => 'scheduling_colors',
    ]);

    $this->actingAs($customerUser);

    $proposedDate = \Carbon\Carbon::now()->addWeeks(2);
    app(JobService::class)->proposeCustomerDate($job, $proposedDate);

    Notification::assertSentTo($assignedUser, CustomerProposedDate::class, function ($notification) use ($job) {
        return $notification->job->id === $job->id;
    });
});

it('notifies assigned user when customer confirms date', function () {
    Notification::fake();

    $assignedUser = User::factory()->internal()->withFullAccess()->create();
    $deal = Deal::factory()->create(['assigned_to' => $assignedUser->id]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'customer_date_confirmed' => false,
    ]);

    app(JobService::class)->confirmCustomerDate($job);

    Notification::assertSentTo($assignedUser, CustomerConfirmedDate::class, function ($notification) use ($job) {
        return $notification->job->id === $job->id;
    });
});

it('notifies assigned user when customer confirms colors', function () {
    Notification::fake();

    $assignedUser = User::factory()->internal()->withFullAccess()->create();
    $deal = Deal::factory()->create(['assigned_to' => $assignedUser->id]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'customer_colors_confirmed' => false,
    ]);

    app(JobService::class)->confirmCustomerColors($job);

    Notification::assertSentTo($assignedUser, CustomerConfirmedColors::class, function ($notification) use ($job) {
        return $notification->job->id === $job->id;
    });
});

it('notifies assigned user when stage advances', function () {
    Notification::fake();

    $assignedUser = User::factory()->internal()->withFullAccess()->create();
    $deal = Deal::factory()->create(['assigned_to' => $assignedUser->id]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'stage' => 'estimate_accepted',
    ]);

    app(JobService::class)->advanceStage($job);

    Notification::assertSentTo($assignedUser, JobStageAdvanced::class, function ($notification) {
        return $notification->fromStage === 'estimate_accepted'
            && $notification->toStage === 'scheduling_colors';
    });
});

it('notifies assigned user and customer when job completed', function () {
    Notification::fake();

    $assignedUser = User::factory()->internal()->withFullAccess()->create();
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $deal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $assignedUser->id,
        'stage' => 'in_progress',
    ]);
    $job = Job::factory()->inProgress()->create(['deal_id' => $deal->id]);

    app(JobService::class)->completeJob($job);

    Notification::assertSentTo($assignedUser, JobCompleted::class);
    Notification::assertSentTo($customerUser, JobCompleted::class);
});

it('does not notify when no assigned user on deal', function () {
    Notification::fake();

    $deal = Deal::factory()->create(['assigned_to' => null]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'customer_date_confirmed' => false,
    ]);

    app(JobService::class)->confirmCustomerDate($job);

    Notification::assertNothingSent();
});

it('does not error when confirming date on job with deal having no assigned user', function () {
    Notification::fake();

    $deal = Deal::factory()->create(['assigned_to' => null]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'company_colors_confirmed' => false,
    ]);

    // Should not throw an error even with no assigned user
    app(JobService::class)->confirmCompanyColors($job);

    Notification::assertNothingSent();

    expect($job->fresh()->company_colors_confirmed)->toBeTrue();
});
