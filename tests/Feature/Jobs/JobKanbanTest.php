<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use Livewire\Livewire;

describe('Job Kanban Board', function () {
    it('renders for internal users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->assertStatus(200)
            ->assertSee('Job Progress');
    });

    it('denies access to non-internal users', function () {
        $user = User::factory()->customer()->create();

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->assertForbidden();
    });

    it('displays jobs in correct stage columns', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        $job1 = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'estimate_accepted',
            'title' => 'Kitchen Paint Job',
        ]);
        $job2 = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'title' => 'Bedroom Paint Job',
        ]);
        $job3 = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'completed',
            'title' => 'Garage Paint Job',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->assertSee('Kitchen Paint Job')
            ->assertSee('Bedroom Paint Job')
            ->assertSee('Garage Paint Job');
    });

    it('shows job numbers on cards', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['job_number' => 'JOB-2026-00001']);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->assertSee('JOB-2026-00001');
    });

    it('can search jobs by title', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job1 = Job::factory()->create(['title' => 'Kitchen Renovation']);
        $job2 = Job::factory()->create(['title' => 'Exterior Painting']);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->set('search', 'Kitchen')
            ->assertSee('Kitchen Renovation')
            ->assertDontSee('Exterior Painting');
    });

    it('can search jobs by job number', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'job_number' => 'JOB-2026-00099',
            'title' => 'Special Job',
        ]);
        Job::factory()->create(['title' => 'Other Job']);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->set('search', '00099')
            ->assertSee('Special Job')
            ->assertDontSee('Other Job');
    });

    it('can move a job to a new stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'estimate_accepted']);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->call('moveToStage', $job->id, 'scheduling_colors', 0);

        $job->refresh();
        expect($job->stage)->toBe('scheduling_colors');
    });

    it('does not move to invalid stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'estimate_accepted']);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->call('moveToStage', $job->id, 'invalid_stage', 0);

        $job->refresh();
        expect($job->stage)->toBe('estimate_accepted');
    });

    it('displays all five stage columns', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->assertSee('Estimate Accepted')
            ->assertSee('Scheduling & Colors')
            ->assertSee('Job Prep')
            ->assertSee('In Progress')
            ->assertSee('Completed');
    });

    it('shows customer name on job cards', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->assertSee('John Smith');
    });

    it('shows ready for dispatch badge when all confirmations done', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        Job::factory()->create([
            'stage' => 'scheduling_colors',
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => true,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.kanban')
            ->assertSee('Ready for Dispatch');
    });
});
