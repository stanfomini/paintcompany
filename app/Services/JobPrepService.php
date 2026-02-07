<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Models\User;

class JobPrepService
{
    /**
     * Create a prep plan for a job with day containers.
     */
    public function createPlan(Job $job, int $crewSize = 2, float $hoursPerDay = 8): JobPrepPlan
    {
        $estimate = $job->estimate;

        // Calculate total labor and cleanup hours from estimate surfaces
        $totalLaborHours = 0;
        $totalCleanupHours = 0;

        if ($estimate) {
            $areas = $estimate->areas()->topLevel()->with(['surfaces', 'addons'])->get();
            $settings = CompanySetting::getInstance();

            foreach ($areas as $area) {
                $areaLaborHours = (float) $area->surfaces()
                    ->where('is_included', true)
                    ->sum('labor_hours');

                $totalLaborHours += $areaLaborHours;

                // Add addon labor hours
                $totalLaborHours += (float) $area->addons()->sum('labor_hours');

                // Cleanup hours for this area
                $scope = $area->scope_type ?? 'interior';
                $totalCleanupHours += $settings->calculateCleanupHours($areaLaborHours, $scope);
            }
        }

        $plan = JobPrepPlan::create([
            'job_id' => $job->id,
            'crew_size' => $crewSize,
            'hours_per_day' => $hoursPerDay,
            'total_labor_hours' => round($totalLaborHours, 2),
            'total_cleanup_hours' => round($totalCleanupHours, 2),
        ]);

        // Calculate and set total days
        $plan->update(['total_days' => $plan->calculateTotalDays()]);
        $plan->refresh();

        // Create day containers
        for ($i = 1; $i <= $plan->total_days; $i++) {
            JobPrepDay::create([
                'job_prep_plan_id' => $plan->id,
                'day_number' => $i,
                'label' => "Day {$i}",
                'sort_order' => $i - 1,
            ]);
        }

        JobActivity::log($job, 'prep_plan_created', 'Prep plan created with '.$plan->total_days.' day(s)', [
            'crew_size' => $crewSize,
            'hours_per_day' => $hoursPerDay,
            'total_days' => $plan->total_days,
        ]);

        return $plan->load('days');
    }

    /**
     * Generate tasks from estimate surfaces and addons.
     * All tasks start in Day 1.
     */
    public function generateTasksFromEstimate(JobPrepPlan $plan): int
    {
        $job = $plan->job;
        $estimate = $job->estimate;

        if (! $estimate) {
            return 0;
        }

        $firstDay = $plan->days()->orderBy('day_number')->first();

        if (! $firstDay) {
            return 0;
        }

        $taskCount = 0;
        $areas = $estimate->areas()->topLevel()->with(['surfaces.productionRate', 'addons.nestedAreaTemplate'])->get();

        foreach ($areas as $area) {
            // Create tasks from included surfaces
            foreach ($area->surfaces->where('is_included', true) as $surface) {
                JobPrepTask::create([
                    'job_prep_day_id' => $firstDay->id,
                    'job_prep_plan_id' => $plan->id,
                    'estimate_area_id' => $area->id,
                    'estimate_area_surface_id' => $surface->id,
                    'task_type' => JobPrepTask::TYPE_SURFACE,
                    'title' => $area->name.' - '.$surface->name.' ('.number_format((float) $surface->quantity).' '.($surface->unit ?? 'sqft').')',
                    'area_name' => $area->name,
                    'surface_name' => $surface->name,
                    'quantity' => (float) $surface->quantity,
                    'unit' => $surface->unit,
                    'coats' => $surface->coats,
                    'estimated_hours' => (float) $surface->labor_hours,
                    'sort_order' => $taskCount,
                ]);
                $taskCount++;
            }

            // Create tasks from addons
            foreach ($area->addons as $addon) {
                $templateName = $addon->nestedAreaTemplate?->name ?? 'Addon';

                JobPrepTask::create([
                    'job_prep_day_id' => $firstDay->id,
                    'job_prep_plan_id' => $plan->id,
                    'estimate_area_id' => $area->id,
                    'estimate_area_addon_id' => $addon->id,
                    'task_type' => JobPrepTask::TYPE_ADDON,
                    'title' => $templateName.' - '.$area->name,
                    'area_name' => $area->name,
                    'surface_name' => $templateName,
                    'estimated_hours' => (float) ($addon->labor_hours ?? 0),
                    'sort_order' => $taskCount,
                ]);
                $taskCount++;
            }
        }

        return $taskCount;
    }

    /**
     * Recalculate total days and adjust day containers.
     */
    public function recalculateDays(JobPrepPlan $plan): void
    {
        $newTotalDays = $plan->calculateTotalDays();
        $currentDays = $plan->days()->count();

        if ($newTotalDays > $currentDays) {
            // Add new day containers
            for ($i = $currentDays + 1; $i <= $newTotalDays; $i++) {
                JobPrepDay::create([
                    'job_prep_plan_id' => $plan->id,
                    'day_number' => $i,
                    'label' => "Day {$i}",
                    'sort_order' => $i - 1,
                ]);
            }
        } elseif ($newTotalDays < $currentDays) {
            // Move orphaned tasks to the last remaining day
            $lastDay = $plan->days()->where('day_number', '<=', $newTotalDays)->orderByDesc('day_number')->first();

            if ($lastDay) {
                $removedDays = $plan->days()->where('day_number', '>', $newTotalDays)->get();
                foreach ($removedDays as $day) {
                    $day->tasks()->update(['job_prep_day_id' => $lastDay->id]);
                    $day->delete();
                }
            }
        }

        $plan->update(['total_days' => $newTotalDays]);
    }

    /**
     * Move a task to a different day.
     */
    public function moveTaskToDay(JobPrepTask $task, JobPrepDay $day): void
    {
        $task->update(['job_prep_day_id' => $day->id]);
        $this->revokeApprovals($task->plan);

        JobActivity::log($task->plan->job, 'prep_task_moved', "Task \"{$task->title}\" moved to Day {$day->day_number}");
    }

    /**
     * Add a custom task to a day.
     */
    public function addCustomTask(JobPrepDay $day, string $title, ?string $description = null, ?float $estimatedHours = null): JobPrepTask
    {
        $plan = $day->plan;

        $task = JobPrepTask::create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => $title,
            'description' => $description,
            'estimated_hours' => $estimatedHours,
            'sort_order' => $day->tasks()->count(),
        ]);

        $this->revokeApprovals($plan);

        JobActivity::log($plan->job, 'prep_task_added', "Custom task added: \"{$title}\"");

        return $task;
    }

    /**
     * Remove a task (custom only).
     */
    public function removeTask(JobPrepTask $task): bool
    {
        if (! $task->isCustom()) {
            return false;
        }

        $plan = $task->plan;
        $title = $task->title;
        $task->delete();

        $this->revokeApprovals($plan);

        JobActivity::log($plan->job, 'prep_task_removed', "Custom task removed: \"{$title}\"");

        return true;
    }

    /**
     * Approve the plan as a specific party.
     */
    public function approvePlan(JobPrepPlan $plan, User $user, string $party): void
    {
        $field = match ($party) {
            'team' => ['team_approved', 'team_approved_at', 'team_approved_by'],
            'crew' => ['crew_approved', 'crew_approved_at', 'crew_approved_by'],
            'customer' => ['customer_approved', 'customer_approved_at', 'customer_approved_by'],
            default => throw new \InvalidArgumentException("Invalid party: {$party}"),
        };

        $plan->update([
            $field[0] => true,
            $field[1] => now(),
            $field[2] => $user->id,
        ]);

        $activityType = "prep_approved_{$party}";

        JobActivity::log($plan->job, $activityType, ucfirst($party).' approved the prep plan', [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);
    }

    /**
     * Reset all three approvals.
     */
    public function revokeApprovals(JobPrepPlan $plan): void
    {
        $wasApproved = $plan->team_approved || $plan->crew_approved || $plan->customer_approved;

        $plan->update([
            'team_approved' => false,
            'crew_approved' => false,
            'customer_approved' => false,
            'team_approved_at' => null,
            'crew_approved_at' => null,
            'customer_approved_at' => null,
            'team_approved_by' => null,
            'crew_approved_by' => null,
            'customer_approved_by' => null,
        ]);

        if ($wasApproved) {
            JobActivity::log($plan->job, 'prep_approvals_reset', 'Prep plan approvals reset due to plan changes');
        }
    }

    /**
     * Start the job - set actual_start_date and advance to in_progress.
     */
    public function startJob(Job $job): void
    {
        $job->update([
            'actual_start_date' => now(),
            'stage' => 'in_progress',
        ]);

        JobActivity::log($job, 'job_started', 'Job started');
    }

    /**
     * Complete a task.
     */
    public function completeTask(JobPrepTask $task, User $user, ?string $notes = null): void
    {
        $task->markComplete($user, $notes);

        JobActivity::log($task->plan->job, 'prep_task_completed', "Task completed: \"{$task->title}\"", [
            'task_id' => $task->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Get progress statistics for a plan.
     *
     * @return array{total: int, completed: int, percent: float, per_day: array<int, array{day_number: int, total: int, completed: int, percent: float, hours: float}>}
     */
    public function getProgress(JobPrepPlan $plan): array
    {
        $total = $plan->tasks()->count();
        $completed = $plan->tasks()->where('status', JobPrepTask::STATUS_COMPLETED)->count();
        $percent = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

        $perDay = [];
        foreach ($plan->days()->with('tasks')->get() as $day) {
            $dayTotal = $day->tasks->count();
            $dayCompleted = $day->tasks->where('status', JobPrepTask::STATUS_COMPLETED)->count();

            $perDay[] = [
                'day_number' => $day->day_number,
                'total' => $dayTotal,
                'completed' => $dayCompleted,
                'percent' => $dayTotal > 0 ? round(($dayCompleted / $dayTotal) * 100, 1) : 0.0,
                'hours' => $day->tasks->sum('estimated_hours'),
            ];
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'percent' => $percent,
            'per_day' => $perDay,
        ];
    }

    /**
     * Auto-distribute tasks across days by estimated hours.
     */
    public function autoDistributeTasks(JobPrepPlan $plan): void
    {
        $days = $plan->days()->orderBy('day_number')->get();

        if ($days->count() <= 1) {
            return;
        }

        $tasks = $plan->tasks()->orderByDesc('estimated_hours')->get();
        $targetHoursPerDay = $tasks->sum('estimated_hours') / $days->count();

        // Greedy distribution: assign tasks to the day with the least hours
        $dayHours = $days->mapWithKeys(fn ($day) => [$day->id => 0.0]);

        foreach ($tasks as $task) {
            // Find the day with the least accumulated hours
            $targetDayId = $dayHours->sort()->keys()->first();
            $task->update(['job_prep_day_id' => $targetDayId]);
            $dayHours[$targetDayId] += (float) ($task->estimated_hours ?? 0);
        }

        $this->revokeApprovals($plan);

        JobActivity::log($plan->job, 'prep_plan_changed', 'Tasks auto-distributed across days');
    }
}
