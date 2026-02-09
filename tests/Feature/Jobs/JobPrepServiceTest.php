<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaAddon;
use App\Models\EstimateAreaSurface;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Models\User;
use App\Services\JobPrepService;

beforeEach(function () {
    $this->service = app(JobPrepService::class);
});

describe('createPlan', function () {
    it('creates a plan with day containers', function () {
        $job = Job::factory()->jobPrep()->create();

        $plan = $this->service->createPlan($job, crewSize: 2, hoursPerDay: 8);

        expect($plan)->toBeInstanceOf(JobPrepPlan::class)
            ->and($plan->job_id)->toBe($job->id)
            ->and($plan->crew_size)->toBe(2)
            ->and($plan->hours_per_day)->toBe(8.0)
            ->and($plan->total_days)->toBeGreaterThanOrEqual(1)
            ->and($plan->days)->toHaveCount($plan->total_days);
    });

    it('calculates days from estimate surfaces', function () {
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->jobPrep()->create(['estimate_id' => $estimate->id]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
        ]);

        // Create surfaces with known labor hours (total 24 hours)
        EstimateAreaSurface::factory()->wall()->create([
            'area_id' => $area->id,
            'labor_hours' => 16,
            'is_included' => true,
        ]);
        EstimateAreaSurface::factory()->ceiling()->create([
            'area_id' => $area->id,
            'labor_hours' => 8,
            'is_included' => true,
        ]);

        // With 2 crew @ 8hr/day: 24 labor + cleanup = needs multiple days
        $plan = $this->service->createPlan($job, crewSize: 2, hoursPerDay: 8);

        expect($plan->total_labor_hours)->toBe(24.0)
            ->and($plan->total_days)->toBeGreaterThanOrEqual(1);
    });

    it('creates at least 1 day even with no estimate', function () {
        $job = Job::factory()->jobPrep()->create();

        $plan = $this->service->createPlan($job);

        expect($plan->total_days)->toBe(1)
            ->and($plan->days)->toHaveCount(1);
    });

    it('logs an activity when plan is created', function () {
        $job = Job::factory()->jobPrep()->create();

        $this->service->createPlan($job);

        expect(JobActivity::where('job_id', $job->id)->where('activity_type', 'prep_plan_created')->exists())->toBeTrue();
    });
});

describe('generateTasksFromEstimate', function () {
    it('creates tasks from estimate surfaces', function () {
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
            'coats' => 2,
            'labor_hours' => 5.0,
            'is_included' => true,
        ]);

        EstimateAreaSurface::factory()->ceiling()->create([
            'area_id' => $area->id,
            'name' => 'Ceiling',
            'quantity' => 150,
            'unit' => 'sqft',
            'coats' => 1,
            'labor_hours' => 2.0,
            'is_included' => true,
        ]);

        $plan = $this->service->createPlan($job);
        $count = $this->service->generateTasksFromEstimate($plan);

        expect($count)->toBe(2);

        $tasks = $plan->tasks()->get();
        expect($tasks)->toHaveCount(2)
            ->and($tasks->first()->task_type)->toBe(JobPrepTask::TYPE_SURFACE)
            ->and($tasks->first()->area_name)->toBe('Living Room')
            ->and($tasks->first()->surface_name)->toBe('Walls')
            ->and($tasks->first()->quantity)->toBe(400.0)
            ->and($tasks->first()->estimated_hours)->toBe(5.0);
    });

    it('creates tasks from estimate addons', function () {
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->jobPrep()->create(['estimate_id' => $estimate->id]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Bedroom',
        ]);

        EstimateAreaAddon::factory()->create([
            'estimate_area_id' => $area->id,
            'labor_hours' => 1.5,
        ]);

        $plan = $this->service->createPlan($job);
        $count = $this->service->generateTasksFromEstimate($plan);

        expect($count)->toBe(1);

        $task = $plan->tasks()->first();
        expect($task->task_type)->toBe(JobPrepTask::TYPE_ADDON)
            ->and($task->area_name)->toBe('Bedroom')
            ->and($task->estimated_hours)->toBe(1.5);
    });

    it('excludes non-included surfaces', function () {
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->jobPrep()->create(['estimate_id' => $estimate->id]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        EstimateAreaSurface::factory()->wall()->create([
            'area_id' => $area->id,
            'is_included' => true,
            'labor_hours' => 5.0,
        ]);

        EstimateAreaSurface::factory()->ceiling()->create([
            'area_id' => $area->id,
            'is_included' => false,
            'labor_hours' => 3.0,
        ]);

        $plan = $this->service->createPlan($job);
        $count = $this->service->generateTasksFromEstimate($plan);

        expect($count)->toBe(1);
    });

    it('places all tasks in day 1', function () {
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->jobPrep()->create(['estimate_id' => $estimate->id]);

        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        EstimateAreaSurface::factory()->count(3)->create([
            'area_id' => $area->id,
            'is_included' => true,
            'labor_hours' => 4.0,
        ]);

        $plan = $this->service->createPlan($job, crewSize: 1, hoursPerDay: 6);
        $this->service->generateTasksFromEstimate($plan);

        $firstDay = $plan->days()->where('day_number', 1)->first();
        expect($firstDay->tasks()->count())->toBe(3);
    });
});

describe('moveTaskToDay', function () {
    it('moves a task between days', function () {
        $plan = JobPrepPlan::factory()->create(['total_days' => 2]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day1->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        $this->service->moveTaskToDay($task, $day2);

        expect($task->fresh()->job_prep_day_id)->toBe($day2->id);
    });

    it('resets approvals when task is moved', function () {
        $plan = JobPrepPlan::factory()->teamApproved()->create(['total_days' => 2]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day1->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        $this->service->moveTaskToDay($task, $day2);

        expect($plan->fresh()->team_approved)->toBeFalse();
    });
});

describe('addCustomTask', function () {
    it('adds a custom task to a day', function () {
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $task = $this->service->addCustomTask($day, 'Move furniture', 'Move all furniture to center of room');

        expect($task)->toBeInstanceOf(JobPrepTask::class)
            ->and($task->task_type)->toBe(JobPrepTask::TYPE_CUSTOM)
            ->and($task->title)->toBe('Move furniture')
            ->and($task->description)->toBe('Move all furniture to center of room');
    });

    it('resets approvals when custom task is added', function () {
        $plan = JobPrepPlan::factory()->approved()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $this->service->addCustomTask($day, 'Extra task');

        $plan->refresh();
        expect($plan->team_approved)->toBeFalse()
            ->and($plan->crew_approved)->toBeFalse()
            ->and($plan->customer_approved)->toBeFalse();
    });

    it('logs activity when custom task is added', function () {
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        $this->service->addCustomTask($day, 'Move furniture');

        expect(JobActivity::where('job_id', $plan->job_id)->where('activity_type', 'prep_task_added')->exists())->toBeTrue();
    });
});

describe('removeTask', function () {
    it('removes a custom task', function () {
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
        ]);

        $result = $this->service->removeTask($task);

        expect($result)->toBeTrue()
            ->and(JobPrepTask::find($task->id))->toBeNull();
    });

    it('does not remove surface-linked tasks', function () {
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->surface()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        $result = $this->service->removeTask($task);

        expect($result)->toBeFalse()
            ->and(JobPrepTask::find($task->id))->not->toBeNull();
    });
});

describe('approvePlan', function () {
    it('approves as team', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $plan = JobPrepPlan::factory()->create();

        $this->service->approvePlan($plan, $user, 'team');

        $plan->refresh();
        expect($plan->team_approved)->toBeTrue()
            ->and($plan->team_approved_by)->toBe($user->id)
            ->and($plan->team_approved_at)->not->toBeNull();
    });

    it('approves as crew', function () {
        $user = User::factory()->crew()->create();
        $plan = JobPrepPlan::factory()->create();

        $this->service->approvePlan($plan, $user, 'crew');

        $plan->refresh();
        expect($plan->crew_approved)->toBeTrue()
            ->and($plan->crew_approved_by)->toBe($user->id);
    });

    it('approves as customer', function () {
        $user = User::factory()->customer()->create();
        $plan = JobPrepPlan::factory()->create();

        $this->service->approvePlan($plan, $user, 'customer');

        $plan->refresh();
        expect($plan->customer_approved)->toBeTrue()
            ->and($plan->customer_approved_by)->toBe($user->id);
    });

    it('throws on invalid party', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $plan = JobPrepPlan::factory()->create();

        expect(fn () => $this->service->approvePlan($plan, $user, 'invalid'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('logs activity on approval', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $plan = JobPrepPlan::factory()->create();

        $this->service->approvePlan($plan, $user, 'team');

        expect(JobActivity::where('job_id', $plan->job_id)->where('activity_type', 'prep_approved_team')->exists())->toBeTrue();
    });
});

describe('revokeApprovals', function () {
    it('resets all approvals', function () {
        $plan = JobPrepPlan::factory()->approved()->create();

        expect($plan->isFullyApproved())->toBeTrue();

        $this->service->revokeApprovals($plan);

        $plan->refresh();
        expect($plan->team_approved)->toBeFalse()
            ->and($plan->crew_approved)->toBeFalse()
            ->and($plan->customer_approved)->toBeFalse()
            ->and($plan->team_approved_at)->toBeNull()
            ->and($plan->crew_approved_at)->toBeNull()
            ->and($plan->customer_approved_at)->toBeNull();
    });
});

describe('startJob', function () {
    it('starts the job and sets actual start date', function () {
        $job = Job::factory()->jobPrep()->create();

        $this->service->startJob($job);

        $job->refresh();
        expect($job->stage)->toBe('in_progress')
            ->and($job->actual_start_date)->not->toBeNull();
    });

    it('logs activity when job starts', function () {
        $job = Job::factory()->jobPrep()->create();

        $this->service->startJob($job);

        expect(JobActivity::where('job_id', $job->id)->where('activity_type', 'job_started')->exists())->toBeTrue();
    });
});

describe('completeTask', function () {
    it('marks a task as completed', function () {
        $user = User::factory()->crew()->create();
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        $this->service->completeTask($task, $user, 'Looks great');

        $task->refresh();
        expect($task->status)->toBe(JobPrepTask::STATUS_COMPLETED)
            ->and($task->completed_by)->toBe($user->id)
            ->and($task->completed_at)->not->toBeNull()
            ->and($task->completion_notes)->toBe('Looks great');
    });
});

describe('cycleTaskStatus', function () {
    it('cycles pending to in_progress', function () {
        $user = User::factory()->crew()->create();
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'status' => JobPrepTask::STATUS_PENDING,
        ]);

        $result = $this->service->cycleTaskStatus($task, $user);

        $task->refresh();
        expect($result)->toBe(JobPrepTask::STATUS_IN_PROGRESS)
            ->and($task->status)->toBe(JobPrepTask::STATUS_IN_PROGRESS)
            ->and($task->completed_by)->toBeNull()
            ->and($task->completed_at)->toBeNull();
    });

    it('cycles in_progress to completed', function () {
        $user = User::factory()->crew()->create();
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->inProgress()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        $result = $this->service->cycleTaskStatus($task, $user);

        $task->refresh();
        expect($result)->toBe(JobPrepTask::STATUS_COMPLETED)
            ->and($task->status)->toBe(JobPrepTask::STATUS_COMPLETED)
            ->and($task->completed_by)->toBe($user->id)
            ->and($task->completed_at)->not->toBeNull();
    });

    it('cycles completed back to pending', function () {
        $user = User::factory()->crew()->create();
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->completed()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'completed_by' => $user->id,
        ]);

        $result = $this->service->cycleTaskStatus($task, $user);

        $task->refresh();
        expect($result)->toBe(JobPrepTask::STATUS_PENDING)
            ->and($task->status)->toBe(JobPrepTask::STATUS_PENDING)
            ->and($task->completed_by)->toBeNull()
            ->and($task->completed_at)->toBeNull();
    });

    it('logs activity for each status change', function () {
        $user = User::factory()->crew()->create();
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'status' => JobPrepTask::STATUS_PENDING,
        ]);

        $this->service->cycleTaskStatus($task, $user);

        $activity = JobActivity::where('job_id', $plan->job_id)
            ->where('activity_type', 'prep_task_status_changed')
            ->latest()
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->description)->toContain('started');
    });

    it('completes a full cycle: pending → in_progress → completed → pending', function () {
        $user = User::factory()->crew()->create();
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'status' => JobPrepTask::STATUS_PENDING,
        ]);

        // pending → in_progress
        $result1 = $this->service->cycleTaskStatus($task, $user);
        expect($result1)->toBe(JobPrepTask::STATUS_IN_PROGRESS);

        // in_progress → completed
        $result2 = $this->service->cycleTaskStatus($task, $user);
        expect($result2)->toBe(JobPrepTask::STATUS_COMPLETED);

        // completed → pending
        $result3 = $this->service->cycleTaskStatus($task, $user);
        expect($result3)->toBe(JobPrepTask::STATUS_PENDING);

        $task->refresh();
        expect($task->status)->toBe(JobPrepTask::STATUS_PENDING)
            ->and($task->completed_by)->toBeNull()
            ->and($task->completed_at)->toBeNull();
    });
});

describe('getProgress', function () {
    it('returns progress statistics', function () {
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        JobPrepTask::factory()->count(3)->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'status' => JobPrepTask::STATUS_PENDING,
            'estimated_hours' => 2.0,
        ]);

        JobPrepTask::factory()->count(2)->completed()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'estimated_hours' => 2.0,
        ]);

        $progress = $this->service->getProgress($plan);

        expect($progress['total'])->toBe(5)
            ->and($progress['completed'])->toBe(2)
            ->and($progress['in_progress'])->toBe(0)
            ->and($progress['percent'])->toBe(40.0)
            ->and($progress['per_day'])->toHaveCount(1)
            ->and($progress['per_day'][0]['total'])->toBe(5)
            ->and($progress['per_day'][0]['completed'])->toBe(2)
            ->and($progress['per_day'][0]['in_progress'])->toBe(0);
    });

    it('tracks in_progress tasks in progress stats', function () {
        $plan = JobPrepPlan::factory()->create();
        $day = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        JobPrepTask::factory()->count(2)->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'status' => JobPrepTask::STATUS_PENDING,
        ]);

        JobPrepTask::factory()->count(1)->inProgress()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        JobPrepTask::factory()->count(1)->completed()->create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        $progress = $this->service->getProgress($plan);

        expect($progress['total'])->toBe(4)
            ->and($progress['completed'])->toBe(1)
            ->and($progress['in_progress'])->toBe(1)
            ->and($progress['per_day'][0]['in_progress'])->toBe(1);
    });
});

describe('autoDistributeTasks', function () {
    it('distributes tasks across days evenly', function () {
        $plan = JobPrepPlan::factory()->create(['total_days' => 2]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);

        // Create 4 tasks with varying hours - all in day 1 initially
        JobPrepTask::factory()->create(['job_prep_day_id' => $day1->id, 'job_prep_plan_id' => $plan->id, 'estimated_hours' => 4.0]);
        JobPrepTask::factory()->create(['job_prep_day_id' => $day1->id, 'job_prep_plan_id' => $plan->id, 'estimated_hours' => 3.0]);
        JobPrepTask::factory()->create(['job_prep_day_id' => $day1->id, 'job_prep_plan_id' => $plan->id, 'estimated_hours' => 2.0]);
        JobPrepTask::factory()->create(['job_prep_day_id' => $day1->id, 'job_prep_plan_id' => $plan->id, 'estimated_hours' => 1.0]);

        $this->service->autoDistributeTasks($plan);

        // Tasks should be distributed across both days
        $day1Tasks = JobPrepTask::where('job_prep_day_id', $day1->id)->count();
        $day2Tasks = JobPrepTask::where('job_prep_day_id', $day2->id)->count();

        expect($day1Tasks + $day2Tasks)->toBe(4)
            ->and($day1Tasks)->toBeGreaterThanOrEqual(1)
            ->and($day2Tasks)->toBeGreaterThanOrEqual(1);
    });
});

describe('Job model integration', function () {
    it('has prepPlan relationship', function () {
        $job = Job::factory()->jobPrep()->create();
        $plan = JobPrepPlan::factory()->create(['job_id' => $job->id]);

        expect($job->prepPlan->id)->toBe($plan->id);
    });

    it('isPrepApproved returns false without plan', function () {
        $job = Job::factory()->jobPrep()->create();

        expect($job->isPrepApproved())->toBeFalse();
    });

    it('isPrepApproved returns true when fully approved', function () {
        $job = Job::factory()->jobPrep()->create();
        JobPrepPlan::factory()->approved()->create(['job_id' => $job->id]);

        expect($job->fresh()->isPrepApproved())->toBeTrue();
    });

    it('isPrepApproved returns false when partially approved', function () {
        $job = Job::factory()->jobPrep()->create();
        JobPrepPlan::factory()->teamApproved()->create(['job_id' => $job->id]);

        expect($job->fresh()->isPrepApproved())->toBeFalse();
    });
});

describe('recalculateDays', function () {
    it('adds days when crew size decreases', function () {
        $plan = JobPrepPlan::factory()->create([
            'crew_size' => 4,
            'hours_per_day' => 8,
            'total_labor_hours' => 32,
            'total_cleanup_hours' => 0,
            'total_days' => 1,
        ]);
        JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);

        // Decrease crew size - more days needed
        $plan->update(['crew_size' => 1]);
        $this->service->recalculateDays($plan);

        $plan->refresh();
        expect($plan->total_days)->toBe(4)
            ->and($plan->days()->count())->toBe(4);
    });

    it('removes days and moves orphaned tasks', function () {
        $plan = JobPrepPlan::factory()->create([
            'crew_size' => 1,
            'hours_per_day' => 8,
            'total_labor_hours' => 16,
            'total_cleanup_hours' => 0,
            'total_days' => 2,
        ]);
        $day1 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 1]);
        $day2 = JobPrepDay::factory()->create(['job_prep_plan_id' => $plan->id, 'day_number' => 2]);

        $task = JobPrepTask::factory()->create([
            'job_prep_day_id' => $day2->id,
            'job_prep_plan_id' => $plan->id,
        ]);

        // Increase crew size - fewer days needed
        $plan->update(['crew_size' => 4]);
        $this->service->recalculateDays($plan);

        $plan->refresh();
        expect($plan->total_days)->toBe(1)
            ->and($plan->days()->count())->toBe(1);

        // Task from day 2 should be moved to day 1
        expect($task->fresh()->job_prep_day_id)->toBe($day1->id);
    });
});
