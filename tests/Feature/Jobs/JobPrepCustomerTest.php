<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Prep Plan View', function () {
    it('shows prep plan details in job_prep stage', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->jobPrep()->create(['deal_id' => $deal->id]);

        $plan = JobPrepPlan::factory()->create([
            'job_id' => $job->id,
            'crew_size' => 3,
            'total_days' => 2,
        ]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day1->id,
            'title' => 'Paint Living Room Walls',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Prep Plan')
            ->assertSee('Paint Living Room Walls')
            ->assertSee('3') // crew size
            ->assertSee('2'); // total days
    });

    it('shows placeholder when no plan exists', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->jobPrep()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Preparing Your Project');
    });

    it('can approve prep plan as customer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->jobPrep()->create(['deal_id' => $deal->id]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('approvePrepPlan');

        expect($plan->fresh()->customer_approved)->toBeTrue()
            ->and($plan->fresh()->customer_approved_by)->toBe($customerUser->id);
    });

    it('does not allow double customer approval', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->jobPrep()->create(['deal_id' => $deal->id]);

        $plan = JobPrepPlan::factory()->customerApproved()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('approvePrepPlan');

        // Plan should still be customer_approved but not re-approved
        expect($plan->fresh()->customer_approved)->toBeTrue();
    });

    it('shows approve button when not yet approved', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->jobPrep()->create(['deal_id' => $deal->id]);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Approve Plan');
    });

    it('hides approve button after customer approval', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->jobPrep()->create(['deal_id' => $deal->id]);

        $plan = JobPrepPlan::factory()->customerApproved()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertDontSee('Approve Plan');
    });
});

describe('Customer In-Progress View', function () {
    it('shows task progress during in_progress stage', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'in_progress',
            'actual_start_date' => now(),
        ]);

        $plan = JobPrepPlan::factory()->approved()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'title' => 'Prep Kitchen',
            'status' => 'completed',
        ]);
        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'title' => 'Paint Kitchen',
            'status' => 'pending',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Painting In Progress')
            ->assertSee('Prep Kitchen')
            ->assertSee('Paint Kitchen')
            ->assertSee('50%');
    });

    it('shows progress bar for in_progress stage', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'in_progress',
        ]);

        $plan = JobPrepPlan::factory()->approved()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'status' => 'completed',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('1/1')
            ->assertSee('100%');
    });

    it('denies access to job not owned by customer', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);
        $job = Job::factory()->jobPrep()->create(); // no deal linked to this customer

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertNotFound();
    });
});
