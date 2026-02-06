<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\JobColor;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Jobs Index', function () {
    it('renders for customer users', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.jobs-index')
            ->assertStatus(200)
            ->assertSee('My Jobs');
    });

    it('shows only the customers active jobs', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'title' => 'My Kitchen Paint',
            'stage' => 'scheduling_colors',
        ]);

        // Other customer's job
        $otherCustomer = Customer::factory()->create();
        $otherDeal = Deal::factory()->create(['customer_id' => $otherCustomer->id]);
        Job::factory()->create([
            'deal_id' => $otherDeal->id,
            'title' => 'Not My Job',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.jobs-index')
            ->assertSee('My Kitchen Paint')
            ->assertDontSee('Not My Job');
    });

    it('shows completed jobs separately', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Job::factory()->create([
            'deal_id' => $deal->id,
            'title' => 'Active Job',
            'stage' => 'in_progress',
        ]);
        Job::factory()->completed()->create([
            'deal_id' => $deal->id,
            'title' => 'Done Job',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.jobs-index')
            ->assertSee('Active Job')
            ->assertSee('Done Job')
            ->assertSee('Completed Jobs');
    });

    it('shows empty state when no jobs', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.jobs-index')
            ->assertSee('No active jobs');
    });
});

describe('Customer Job Progress Page', function () {
    it('renders for authenticated customer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'title' => 'Living Room Paint',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Living Room Paint')
            ->assertSee($job->job_number);
    });

    it('cannot view another customers job', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();
        $otherDeal = Deal::factory()->create(['customer_id' => $otherCustomer->id]);
        $otherJob = Job::factory()->create(['deal_id' => $otherDeal->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $otherJob])
            ->assertNotFound();
    });

    it('displays the 5-stage progress timeline', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'job_prep',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Job Progress');
    });

    it('shows estimate accepted content for stage 1', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'estimate_accepted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Your Estimate Has Been Accepted');
    });

    it('shows scheduling interface for stage 2', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeeks(2),
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Schedule Your Start Date')
            ->assertSee('Select Your Colors');
    });

    it('can confirm scheduled date', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeeks(2),
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('confirmDate')
            ->assertSee('Date confirmed');

        $job->refresh();
        expect($job->customer_date_confirmed)->toBeTrue();
    });

    it('cannot confirm date when no date is set', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('confirmDate')
            ->assertSee('No date has been set yet');
    });

    it('can add a color', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'allowed_colors' => 3,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('newColorName', 'Ocean Blue')
            ->set('newColorBrand', 'Sherwin-Williams')
            ->call('addColor');

        expect($job->colors()->count())->toBe(1);
        expect($job->colors()->first()->name)->toBe('Ocean Blue');
        expect($job->colors()->first()->selected_by)->toBe($customerUser->id);
    });

    it('cannot add more colors than allowed', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'allowed_colors' => 1,
        ]);
        JobColor::factory()->create(['job_id' => $job->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('newColorName', 'Extra Color')
            ->call('addColor')
            ->assertSee('Maximum number of colors reached');
    });

    it('can confirm colors', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);
        JobColor::factory()->create(['job_id' => $job->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('confirmColors')
            ->assertSee('Colors confirmed');

        $job->refresh();
        expect($job->customer_colors_confirmed)->toBeTrue();
    });

    it('cannot confirm colors without any colors', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->call('confirmColors')
            ->assertSee('Add at least one color first');
    });

    it('shows job prep content for stage 3', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'job_prep',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Preparing Your Project');
    });

    it('shows in progress content for stage 4', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->inProgress()->create([
            'deal_id' => $deal->id,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Painting In Progress');
    });

    it('shows completed content for stage 5', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->completed()->create([
            'deal_id' => $deal->id,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Project Completed');
    });

    it('shows bidirectional confirmation checklist for date', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeeks(2),
            'company_date_confirmed' => true,
            'customer_date_confirmed' => false,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Company confirmation')
            ->assertSee('Your confirmation')
            ->assertSee('Confirm This Date');
    });
});
