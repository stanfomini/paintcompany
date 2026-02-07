<?php

use App\Models\Job;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Models\User;
use App\Services\JobDispatchService;
use Livewire\Livewire;

describe('Crew Prep Plan View', function () {
    it('shows prep plan for crew in job_prep stage', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'crew_size' => 3, 'total_days' => 2]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);
        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day1->id,
            'title' => 'Paint Kitchen Walls',
            'estimated_hours' => 3.0,
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Prep Plan')
            ->assertSee('Paint Kitchen Walls');
    });

    it('shows no plan message when plan does not exist', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('prep plan will appear here');
    });

    it('can approve prep plan as crew', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('approvePrepPlan');

        expect($plan->fresh()->crew_approved)->toBeTrue()
            ->and($plan->fresh()->crew_approved_by)->toBe($crew->id);
    });

    it('does not allow double crew approval', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->crewApproved()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('approvePrepPlan');

        expect($plan->fresh()->crew_approved)->toBeTrue();
    });

    it('can add custom task as crew', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->set('showCrewTaskForm', true)
            ->set('crewCustomTaskTitle', 'Move furniture')
            ->set('crewCustomTaskHours', 1.5)
            ->call('addCrewCustomTask');

        expect(JobPrepTask::where('title', 'Move furniture')->exists())->toBeTrue();
    });

    it('resets approvals when crew adds custom task', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->teamApproved()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        expect($plan->team_approved)->toBeTrue();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->set('showCrewTaskForm', true)
            ->set('crewCustomTaskTitle', 'Extra prep work')
            ->call('addCrewCustomTask');

        expect($plan->fresh()->team_approved)->toBeFalse();
    });

    it('shows approve button when crew has not approved', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Approve Prep Plan');
    });

    it('hides approve button after crew approval', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->crewApproved()->create(['job_id' => $job->id]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Crew Approved')
            ->assertDontSee('Approve Prep Plan');
    });
});

describe('Crew Task Completion', function () {
    it('can mark tasks complete during in_progress', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['stage' => 'in_progress']);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->approved()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'title' => 'Prime walls',
            'status' => 'pending',
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('completeTask', $task->id);

        expect($task->fresh()->status)->toBe('completed')
            ->and($task->fresh()->completed_by)->toBe($crew->id);
    });

    it('shows task progress in in_progress stage', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['stage' => 'in_progress']);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->approved()->create(['job_id' => $job->id]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'title' => 'Task A',
            'status' => 'completed',
        ]);
        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'title' => 'Task B',
            'status' => 'pending',
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Task A')
            ->assertSee('Task B');
    });
});
