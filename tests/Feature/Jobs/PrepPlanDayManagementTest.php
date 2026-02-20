<?php

use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Models\User;
use App\Services\JobDispatchService;
use App\Services\JobPrepService;
use Livewire\Livewire;

beforeEach(function () {
    $this->service = app(JobPrepService::class);
});

// ────────────────────────────────────────────────────────────
// Day Management — Service Tests
// ────────────────────────────────────────────────────────────

describe('addDay', function () {
    it('can add a day to a prep plan', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);

        $newDay = $this->service->addDay($plan);

        expect($newDay->day_number)->toBe(2)
            ->and($newDay->label)->toBe('Day 2')
            ->and($plan->fresh()->total_days)->toBe(2)
            ->and($plan->days()->count())->toBe(2);
    });

    it('revokes approvals when adding a day', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->teamApproved()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $this->service->addDay($plan);

        expect($plan->fresh()->team_approved)->toBeFalse();
    });

    it('logs activity when adding a day', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $this->service->addDay($plan);

        expect(JobActivity::where('job_id', $job->id)->where('activity_type', 'prep_day_added')->exists())->toBeTrue();
    });
});

describe('deleteDay', function () {
    it('can delete an empty day', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 2]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2, 'label' => 'Day 2']);

        $result = $this->service->deleteDay($day2);

        expect($result)->toBeTrue()
            ->and($plan->fresh()->total_days)->toBe(1)
            ->and($plan->days()->count())->toBe(1);
    });

    it('cannot delete a day with tasks', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 2]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);

        JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day2->id,
            'title' => 'A task',
        ]);

        $result = $this->service->deleteDay($day2);

        expect($result)->toBeFalse()
            ->and($plan->days()->count())->toBe(2);
    });

    it('cannot delete the last remaining day', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $result = $this->service->deleteDay($day1);

        expect($result)->toBeFalse()
            ->and($plan->days()->count())->toBe(1);
    });

    it('renumbers days after deletion', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 3]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1, 'label' => 'Day 1']);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2, 'label' => 'Day 2']);
        $day3 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 3, 'label' => 'Day 3']);

        $result = $this->service->deleteDay($day2);

        expect($result)->toBeTrue()
            ->and($plan->fresh()->total_days)->toBe(2);

        $remainingDays = $plan->days()->orderBy('day_number')->get();
        expect($remainingDays)->toHaveCount(2)
            ->and($remainingDays[0]->day_number)->toBe(1)
            ->and($remainingDays[0]->label)->toBe('Day 1')
            ->and($remainingDays[1]->day_number)->toBe(2)
            ->and($remainingDays[1]->label)->toBe('Day 2');
    });

    it('revokes approvals when deleting a day', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->teamApproved()->create(['job_id' => $job->id, 'total_days' => 2]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);

        $this->service->deleteDay($day2);

        expect($plan->fresh()->team_approved)->toBeFalse();
    });
});

// ────────────────────────────────────────────────────────────
// Day Management — Team Page Component Tests
// ────────────────────────────────────────────────────────────

describe('Team Page Day Management', function () {
    it('team can add a day via stage-prep component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->call('addDay');

        expect($plan->fresh()->total_days)->toBe(2)
            ->and($plan->days()->count())->toBe(2);
    });

    it('team can delete an empty day via stage-prep component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 2]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->call('deleteDay', $day2->id);

        expect($plan->fresh()->total_days)->toBe(1)
            ->and($plan->days()->count())->toBe(1);
    });
});

// ────────────────────────────────────────────────────────────
// Day Management — Crew Page Component Tests
// ────────────────────────────────────────────────────────────

describe('Crew Page Day Management', function () {
    it('crew can add a day from crew portal', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('addDay');

        expect($plan->fresh()->total_days)->toBe(2)
            ->and($plan->days()->count())->toBe(2);
    });

    it('crew can delete an empty day from crew portal', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 2]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('deleteDay', $day2->id);

        expect($plan->fresh()->total_days)->toBe(1)
            ->and($plan->days()->count())->toBe(1);
    });

    it('crew cannot add day outside job_prep stage', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['stage' => 'in_progress']);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('addDay');

        // Should still be 1 day since guard prevents adding
        expect($plan->fresh()->total_days)->toBe(1);
    });
});

// ────────────────────────────────────────────────────────────
// Task Removal Requests — Service Tests
// ────────────────────────────────────────────────────────────

describe('requestTaskRemoval', function () {
    it('can request removal of a custom task', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $user = User::factory()->crew()->create();

        $task = JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
        ]);

        $this->service->requestTaskRemoval($task, $user, 'Not needed anymore');

        $task->refresh();
        expect($task->removal_requested_by)->toBe($user->id)
            ->and($task->removal_requested_at)->not->toBeNull()
            ->and($task->removal_reason)->toBe('Not needed anymore')
            ->and($task->hasRemovalRequest())->toBeTrue();
    });

    it('cannot request removal of a non-custom task', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $user = User::factory()->crew()->create();

        $task = JobPrepTask::factory()->surface()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
        ]);

        $this->service->requestTaskRemoval($task, $user);

        $task->refresh();
        expect($task->hasRemovalRequest())->toBeFalse();
    });

    it('revokes approvals when requesting task removal', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->teamApproved()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $user = User::factory()->crew()->create();

        $task = JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
        ]);

        $this->service->requestTaskRemoval($task, $user);

        expect($plan->fresh()->team_approved)->toBeFalse();
    });
});

describe('approveTaskRemoval', function () {
    it('can approve a task removal request', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $user = User::factory()->crew()->create();

        $task = JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
        ]);

        $task->requestRemoval($user, 'Not needed');

        $result = $this->service->approveTaskRemoval($task);

        expect($result)->toBeTrue()
            ->and(JobPrepTask::find($task->id))->toBeNull();
    });
});

describe('dismissTaskRemoval', function () {
    it('can dismiss a task removal request', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $user = User::factory()->crew()->create();

        $task = JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
        ]);

        $task->requestRemoval($user, 'Remove please');

        $this->service->dismissTaskRemoval($task);

        $task->refresh();
        expect($task->hasRemovalRequest())->toBeFalse()
            ->and($task->removal_requested_by)->toBeNull()
            ->and($task->removal_requested_at)->toBeNull()
            ->and($task->removal_reason)->toBeNull();
    });
});

// ────────────────────────────────────────────────────────────
// Task Removal Requests — Crew Component Tests
// ────────────────────────────────────────────────────────────

describe('Crew Task Removal', function () {
    it('crew can request removal of team-created task', function () {
        $crew = User::factory()->crew()->create();
        $teamUser = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        // Team-created task (added_by is internal user)
        $task = JobPrepTask::factory()->addedBy($teamUser)->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => 'Team Task',
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('requestTaskRemoval', $task->id);

        $task->refresh();
        expect($task->removal_requested_by)->toBe($crew->id)
            ->and($task->hasRemovalRequest())->toBeTrue();
    });

    it('crew can directly delete own custom task', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        // Crew-created task
        $task = JobPrepTask::factory()->addedBy($crew)->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => 'Crew Custom Task',
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('removeTask', $task->id);

        expect(JobPrepTask::find($task->id))->toBeNull();
    });

    it('crew cannot directly delete team-created task', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        // Task with no added_by (team-created)
        $task = JobPrepTask::factory()->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => 'Team Created Task',
            'added_by' => null,
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('removeTask', $task->id);

        // Task should still exist
        expect(JobPrepTask::find($task->id))->not->toBeNull();
    });
});

// ────────────────────────────────────────────────────────────
// Task Removal Requests — Team Component Tests
// ────────────────────────────────────────────────────────────

describe('Team Task Removal', function () {
    it('team can see crew removal request and approve it', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $task = JobPrepTask::factory()->addedBy($user)->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => 'Task to Remove',
            'removal_requested_by' => $crew->id,
            'removal_requested_at' => now(),
            'removal_reason' => 'Not needed',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->assertSee('requests removal')
            ->call('approveTaskRemoval', $task->id);

        expect(JobPrepTask::find($task->id))->toBeNull();
    });

    it('team can see crew removal request and dismiss it', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $task = JobPrepTask::factory()->addedBy($user)->create([
            'job_prep_plan_id' => $plan->id,
            'job_prep_day_id' => $day->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => 'Task to Keep',
            'removal_requested_by' => $crew->id,
            'removal_requested_at' => now(),
            'removal_reason' => 'Not needed',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.stage-prep', ['job' => $job])
            ->assertSee('requests removal')
            ->call('dismissTaskRemoval', $task->id);

        $task->refresh();
        expect($task->hasRemovalRequest())->toBeFalse();
    });
});

// ────────────────────────────────────────────────────────────
// addedBy tracking
// ────────────────────────────────────────────────────────────

describe('addedBy Tracking', function () {
    it('added_by is set when crew creates custom task', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->jobPrep()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $task = $this->service->addCustomTask($day, 'Crew Task', null, null, $crew);

        expect($task->added_by)->toBe($crew->id);
    });

    it('added_by is set when team creates custom task', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->jobPrep()->create();

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $task = $this->service->addCustomTask($day, 'Team Task', null, null, $user);

        expect($task->added_by)->toBe($user->id);
    });

    it('added_by is null when no user provided', function () {
        $job = Job::factory()->jobPrep()->create();

        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id, 'total_days' => 1]);
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $task = $this->service->addCustomTask($day, 'Legacy Task');

        expect($task->added_by)->toBeNull();
    });
});
