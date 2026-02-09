<?php

use App\Models\Job;
use App\Models\JobColor;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
});

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
            ->call('saveScheduling')
            ->assertSee('Scheduling details saved');

        $job->refresh();
        expect($job->allowed_colors)->toBe(5);
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
            ->assertSee('All 4 confirmations');
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

describe('Multi-Service Dispatch', function () {
    it('shows service requirements with status badges', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->readyForDispatch()->create();
        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'required_crew_size' => 2,
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('Painting')
            ->assertSee('Pending');
    });

    it('can save per-service requirement settings', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);
        $req = JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'required_crew_size' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->set("requirementCrewSizes.{$req->id}", 4)
            ->set("requirementDates.{$req->id}", now()->addWeeks(2)->format('Y-m-d'))
            ->call('saveServiceRequirement', $req->id)
            ->assertSee('Painting requirements saved');

        $req->refresh();
        expect($req->required_crew_size)->toBe(4);
        expect($req->scheduled_date)->not->toBeNull();
    });

    it('can dispatch to crews for a specific service', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'crew_size' => 3,
            'service_category_id' => $this->painting->id,
        ]);

        $job = Job::factory()->readyForDispatch()->create(['required_crew_size' => 2]);
        $req = JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'required_crew_size' => 2,
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('openDispatchModal', $req->id)
            ->assertSet('activeDispatchServiceId', $req->id)
            ->set('selectedCrewIds', [$crew->id])
            ->call('dispatchToCrews')
            ->assertSee('Dispatched to 1 crew(s) for Painting');

        expect($job->crews()->count())->toBe(1);
        expect($job->crews()->first()->pivot->status)->toBe('dispatched');
        expect($job->crews()->first()->pivot->service_category_id)->toBe($this->painting->id);
    });

    it('shows dispatch button per-service when ready', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->readyForDispatch()->create();
        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('Dispatch');
    });

    it('hides dispatch buttons when confirmations not ready', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'company_date_confirmed' => false,
        ]);
        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertDontSee('Dispatch More')
            ->assertSee('All 4 confirmations');
    });

    it('shows dispatched crews under their service', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $crew = User::factory()->crew()->create(['name' => 'Ace Painters']);
        $crew->crewProfile->update(['service_category_id' => $this->painting->id, 'crew_size' => 3]);

        $job = Job::factory()->readyForDispatch()->create();
        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'status' => 'dispatched',
        ]);

        $job->crews()->attach($crew->id, [
            'status' => 'dispatched',
            'dispatched_at' => now(),
            'service_category_id' => $this->painting->id,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('Ace Painters')
            ->assertSee('Dispatched');
    });

    it('can replace an accepted crew', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $crew = User::factory()->crew()->create(['name' => 'Old Crew']);
        $crew->crewProfile->update(['service_category_id' => $this->painting->id, 'crew_size' => 3]);

        $job = Job::factory()->readyForDispatch()->create();
        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'status' => 'accepted',
        ]);

        $job->crews()->attach($crew->id, [
            'status' => 'accepted',
            'dispatched_at' => now(),
            'responded_at' => now(),
            'service_category_id' => $this->painting->id,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('replaceCrew', $crew->id, $this->painting->id)
            ->assertSee('Old Crew replaced for Painting');

        $pivot = $job->crews()->where('crew_user_id', $crew->id)->first()->pivot;
        expect($pivot->status)->toBe('replaced');
    });

    it('shows multiple service sections for multi-service jobs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->readyForDispatch()->create();

        $cleaning = ServiceCategory::firstOrCreate(
            ['slug' => 'cleaning'],
            ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
        );

        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'required_crew_size' => 2,
            'status' => 'pending',
        ]);
        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $cleaning->id,
            'required_crew_size' => 1,
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('Painting')
            ->assertSee('Cleaning');
    });

    it('shows empty state when no service requirements configured', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create(['stage' => 'scheduling_colors']);

        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->assertSee('No service requirements configured');
    });

    it('filters eligible crews by service category in dispatch modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $cleaning = ServiceCategory::firstOrCreate(
            ['slug' => 'cleaning'],
            ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
        );

        $paintCrew = User::factory()->crew()->create(['name' => 'Paint Experts']);
        $paintCrew->crewProfile->update(['service_category_id' => $this->painting->id, 'crew_size' => 3]);

        $cleanCrew = User::factory()->crew()->create(['name' => 'Clean Team']);
        $cleanCrew->crewProfile->update(['service_category_id' => $cleaning->id, 'crew_size' => 2]);

        $job = Job::factory()->readyForDispatch()->create();
        $paintReq = JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $this->painting->id,
            'required_crew_size' => 2,
        ]);

        // Verify via JobDispatchService that filtering works
        $eligible = app(\App\Services\JobDispatchService::class)->getEligibleCrews($job, $this->painting);
        $eligibleNames = $eligible->pluck('name')->toArray();

        expect($eligibleNames)->toContain('Paint Experts');
        expect($eligibleNames)->not->toContain('Clean Team');

        // Also verify the component opens dispatch modal correctly
        Livewire::actingAs($user)
            ->test('jobs.stage-scheduling', ['job' => $job])
            ->call('openDispatchModal', $paintReq->id)
            ->assertSet('activeDispatchServiceId', $paintReq->id)
            ->assertSet('showDispatchModal', true);
    });
});
