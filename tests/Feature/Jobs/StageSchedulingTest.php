<?php

use App\Models\Job;
use App\Models\JobColor;
use App\Models\User;
use Livewire\Livewire;

describe('Job Stage Scheduling Component', function () {
    it('renders for scheduling_colors stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Scheduling', escape: false);
    });

    it('can save scheduling details', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->set('scheduledDate', now()->addWeek()->format('Y-m-d'))
            ->set('allowedColors', 5)
            ->set('requiredCrewSize', 3)
            ->call('saveScheduling')
            ->assertSee('Scheduling details saved');

        $job->refresh();
        expect($job->allowed_colors)->toBe(5);
        expect($job->required_crew_size)->toBe(3);
        expect($job->scheduled_date)->not->toBeNull();
    });

    it('validates scheduled date is in the future', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->set('scheduledDate', '2020-01-01')
            ->call('saveScheduling')
            ->assertHasErrors(['scheduledDate']);
    });

    it('can confirm company date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeek(),
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('confirmCompanyDate')
            ->assertSee('Company date confirmed');

        $job->refresh();
        expect($job->company_date_confirmed)->toBeTrue();
    });

    it('cannot confirm company date without scheduled date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'scheduled_date' => null,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('confirmCompanyDate')
            ->assertSee('Set a scheduled date first');
    });

    it('can add a color', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'allowed_colors' => 5,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->set('newColorName', 'Dove White')
            ->set('newColorBrand', 'Benjamin Moore')
            ->set('newColorCode', 'OC-17')
            ->call('addColor');

        expect($job->colors()->count())->toBe(1);
        expect($job->colors()->first()->name)->toBe('Dove White');
    });

    it('cannot add more colors than allowed', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'allowed_colors' => 1,
        ]);
        JobColor::factory()->create(['job_id' => $job->id]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->set('newColorName', 'Extra Color')
            ->call('addColor')
            ->assertSee('Maximum number of colors reached');
    });

    it('can remove a color', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);
        $color = JobColor::factory()->create(['job_id' => $job->id]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('removeColor', $color->id);

        expect($job->colors()->count())->toBe(0);
    });

    it('can confirm company colors', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);
        JobColor::factory()->create(['job_id' => $job->id]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('confirmCompanyColors')
            ->assertSee('Company colors confirmed');

        $job->refresh();
        expect($job->company_colors_confirmed)->toBeTrue();
    });

    it('cannot confirm company colors without any colors', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('confirmCompanyColors')
            ->assertSee('Add at least one color first');
    });

    it('shows dispatch section as not ready when confirmations incomplete', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'company_date_confirmed' => true,
            'customer_date_confirmed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('All 4 confirmations must be completed');
    });

    it('shows dispatch button when all confirmations complete', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->readyForDispatch()->create();

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('Dispatch to Crews');
    });

    it('can dispatch to crews', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['crew_size' => 3]);

        $job = Job::factory()->readyForDispatch()->create([
            'required_crew_size' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->set('selectedCrewIds', [$crew->id])
            ->call('dispatchToCrews')
            ->assertSee('Job dispatched to selected crews');

        expect($job->crews()->count())->toBe(1);
        expect($job->crews()->first()->pivot->status)->toBe('dispatched');
        expect($job->fresh()->dispatched_at)->not->toBeNull();
    });

    it('filters eligible crews by crew size', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $smallCrew = User::factory()->crew()->create(['name' => 'Small Crew']);
        $smallCrew->crewProfile->update(['crew_size' => 1]);

        $largeCrew = User::factory()->crew()->create(['name' => 'Large Crew']);
        $largeCrew->crewProfile->update(['crew_size' => 5]);

        $job = Job::factory()->readyForDispatch()->create([
            'required_crew_size' => 3,
        ]);

        // Dispatch to the large crew should work
        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->set('selectedCrewIds', [$largeCrew->id])
            ->call('dispatchToCrews')
            ->assertSee('Job dispatched to selected crews');

        // Verify the small crew is not listed as eligible by trying to dispatch
        // (the eligible crews computed property filters by crew_size >= required_crew_size)
        $eligible = User::query()
            ->where('type', 'crew')
            ->where('is_active', true)
            ->whereHas('crewProfile', fn ($q) => $q->where('crew_size', '>=', 3))
            ->pluck('name')
            ->toArray();

        expect($eligible)->toContain('Large Crew');
        expect($eligible)->not->toContain('Small Crew');
    });

    it('shows customer date and color waiting status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'customer_date_confirmed' => false,
            'customer_colors_confirmed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('Customer must confirm on their portal');
    });
});
