<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Job;
use App\Models\JobPrepApproval;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepServiceSetting;
use App\Models\JobPrepTask;
use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Livewire;

describe('Stage Prep Component', function () {
    it('renders for internal users with job in job_prep stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Stage 3: Job Prep')
            ->assertSee('Crew')
            ->assertSee('Schedule')
            ->assertSee('Generate Plan');
    });

    it('can create a plan via createPlan', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->jobPrep()->create(['estimate_id' => $estimate->id]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
        ]);

        EstimateAreaSurface::factory()->wall()->create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'quantity' => 400,
            'unit' => 'sqft',
            'labor_hours' => 5.0,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->call('createPlan')
            ->assertSee('Plan Approval')
            ->assertSee('Day 1');

        expect($job->fresh()->prepPlan)->not->toBeNull();
    });

    it('shows task cards after plan creation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->jobPrep()->create(['estimate_id' => $estimate->id]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
        ]);

        EstimateAreaSurface::factory()->wall()->create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'quantity' => 300,
            'unit' => 'sqft',
            'labor_hours' => 4.0,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->call('createPlan')
            ->assertSee('Kitchen');
    });

    it('can add custom tasks', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        // Create plan first
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->set('showCustomTaskForm', true)
            ->set('customTaskTitle', 'Tape all trim')
            ->call('addCustomTask')
            ->assertSee('Tape all trim');
    });

    it('can remove custom tasks', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => 'Remove me',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->assertSee('Remove me')
            ->call('removeTask', $task->id)
            ->assertDontSee('Remove me');
    });

    it('can approve as team', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'painting'],
            ['name' => 'Painting', 'description' => 'Interior and exterior painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
        );

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        JobPrepServiceSetting::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $category->id,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->call('approveSection', 'team', $category->id);

        $approval = JobPrepApproval::where('job_prep_plan_id', $plan->id)
            ->where('service_category_id', $category->id)
            ->where('party', 'team')
            ->first();

        expect($approval)->not->toBeNull();
        expect($approval->approved)->toBeTrue();
    });

    it('can start job when fully approved', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        JobPrepPlan::factory()->approved()->create(['job_id' => $job->id]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->assertSee('Start Job')
            ->call('startJob');

        expect($job->fresh()->stage)->toBe('in_progress');
    });

    it('does not show start job button without full approval', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        JobPrepPlan::factory()->teamApproved()->create(['job_id' => $job->id]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->assertDontSee('Start Job');
    });
});

describe('Stage Advance Guard', function () {
    it('cannot advance from job_prep without all approvals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        // Plan exists but not approved
        JobPrepPlan::factory()->create(['job_id' => $job->id]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->call('advanceStage')
            ->assertSee('All three parties must approve');

        expect($job->fresh()->stage)->toBe('job_prep');
    });

    it('can advance from job_prep with all approvals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        JobPrepPlan::factory()->approved()->create(['job_id' => $job->id]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->call('advanceStage');

        expect($job->fresh()->stage)->toBe('in_progress');
    });
});
