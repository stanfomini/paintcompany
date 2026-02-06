<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobColor;
use App\Models\User;
use Livewire\Livewire;

describe('Job Show Page', function () {
    it('renders for internal users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertStatus(200)
            ->assertSee($job->title)
            ->assertSee($job->job_number);
    });

    it('denies access to non-internal users', function () {
        $user = User::factory()->customer()->create();
        $job = Job::factory()->create();

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertForbidden();
    });

    it('displays the progress timeline with all stages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('Estimate Accepted')
            ->assertSee('Scheduling & Colors')
            ->assertSee('Job Prep')
            ->assertSee('In Progress')
            ->assertSee('Completed');
    });

    it('shows customer and property info', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Johnson',
        ]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('Alice Johnson');
    });

    it('shows estimate link', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['estimate_number' => 'EST-2026-00005']);
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('EST-2026-00005');
    });

    it('shows confirmation statuses', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'company_date_confirmed' => true,
            'customer_date_confirmed' => false,
            'company_colors_confirmed' => false,
            'customer_colors_confirmed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('Confirmed')
            ->assertSee('Pending');
    });

    it('can advance stage from estimate_accepted', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'estimate_accepted']);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->call('advanceStage');

        $job->refresh();
        expect($job->stage)->toBe('scheduling_colors');
    });

    it('cannot advance from scheduling_colors without confirmations', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'company_date_confirmed' => true,
            'customer_date_confirmed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->call('advanceStage')
            ->assertSee('All confirmations must be completed');

        $job->refresh();
        expect($job->stage)->toBe('scheduling_colors');
    });

    it('can advance from scheduling_colors with all confirmations', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->readyForDispatch()->create();

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->call('advanceStage');

        $job->refresh();
        expect($job->stage)->toBe('job_prep');
    });

    it('does not show advance button for completed jobs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->completed()->create();

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertDontSee('Advance Stage');
    });

    it('shows selected colors', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();
        JobColor::factory()->create([
            'job_id' => $job->id,
            'name' => 'Simply White',
            'brand' => 'Benjamin Moore',
            'code' => 'OC-17',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('Simply White')
            ->assertSee('Benjamin Moore')
            ->assertSee('OC-17');
    });

    it('shows assigned crews', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create(['name' => 'Pro Painters']);
        $job = Job::factory()->create();

        $job->crews()->attach($crew->id, [
            'status' => 'accepted',
            'dispatched_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('Pro Painters')
            ->assertSee('Accepted');
    });

    it('shows deal link', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['title' => 'Master Bedroom Project']);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('Master Bedroom Project');
    });
});

describe('Job Stage: Estimate Accepted', function () {
    it('shows estimate accepted content for stage 1', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'estimate_accepted']);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSee('Estimate Signed')
            ->assertSee('Begin Scheduling', escape: false);
    });
});
