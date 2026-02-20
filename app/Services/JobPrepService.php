<?php

namespace App\Services;

use App\Events\PrepApprovalChanged;
use App\Events\PrepPlanUpdated;
use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobPrepApproval;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepServiceSetting;
use App\Models\JobPrepTask;
use App\Models\ServiceCategory;
use App\Models\User;

class JobPrepService
{
    /**
     * Create a prep plan for a job with per-service configurations.
     *
     * Supports both new signature: createPlan($job, serviceConfigs: [...])
     * and legacy signature: createPlan($job, crewSize: 2, hoursPerDay: 8)
     *
     * @param  array<int, array{service_category_id: int, crew_size: int, hours_per_day: float}>  $serviceConfigs
     */
    public function createPlan(Job $job, array $serviceConfigs = [], int $crewSize = 2, float $hoursPerDay = 8): JobPrepPlan
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

        // Create per-service settings and approvals
        if (! empty($serviceConfigs)) {
            foreach ($serviceConfigs as $config) {
                JobPrepServiceSetting::create([
                    'job_prep_plan_id' => $plan->id,
                    'service_category_id' => $config['service_category_id'],
                    'crew_size' => $config['crew_size'],
                    'hours_per_day' => $config['hours_per_day'],
                    'total_labor_hours' => round($totalLaborHours, 2),
                    'total_cleanup_hours' => round($totalCleanupHours, 2),
                ]);

                // Create approval rows for 3 parties × each service
                foreach ([JobPrepApproval::PARTY_TEAM, JobPrepApproval::PARTY_CREW, JobPrepApproval::PARTY_CUSTOMER] as $party) {
                    JobPrepApproval::create([
                        'job_prep_plan_id' => $plan->id,
                        'service_category_id' => $config['service_category_id'],
                        'party' => $party,
                        'approved' => false,
                    ]);
                }
            }
        }

        // Calculate total days: max across services, or legacy fallback
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
            'service_configs' => $serviceConfigs,
        ]);

        PrepPlanUpdated::dispatch($job, 'plan_created', 'Prep plan created with '.$plan->total_days.' day(s)');

        return $plan->load('days');
    }

    /**
     * Generate tasks from estimate surfaces and addons.
     * All tasks start in Day 1. Tags tasks with painting service_category_id.
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

        $paintingCategory = ServiceCategory::where('slug', 'painting')->first();

        $taskCount = 0;
        $areas = $estimate->areas()->topLevel()->with(['surfaces.productionRate', 'addons.nestedAreaTemplate'])->get();

        foreach ($areas as $area) {
            // Create tasks from included surfaces
            foreach ($area->surfaces->where('is_included', true) as $surface) {
                JobPrepTask::create([
                    'job_prep_day_id' => $firstDay->id,
                    'job_prep_plan_id' => $plan->id,
                    'service_category_id' => $paintingCategory?->id,
                    'estimate_area_id' => $area->id,
                    'estimate_area_surface_id' => $surface->id,
                    'task_type' => JobPrepTask::TYPE_SURFACE,
                    'title' => $area->name.' - '.$surface->name.' ('.number_format((float) $surface->quantity).' '.($surface->unit ?? 'sqft').')',
                    'description' => $surface->description,
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
                    'service_category_id' => $paintingCategory?->id,
                    'estimate_area_id' => $area->id,
                    'estimate_area_addon_id' => $addon->id,
                    'task_type' => JobPrepTask::TYPE_ADDON,
                    'title' => $templateName.' - '.$area->name,
                    'description' => $addon->description ?? null,
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

        PrepPlanUpdated::dispatch($task->plan->job, 'task_moved', "Task moved to Day {$day->day_number}");
    }

    /**
     * Add a custom task to a day, auto-tagged with a service category.
     *
     * Supports both new signature: addCustomTask($day, $title, $serviceCategoryId, $description)
     * and legacy signature: addCustomTask($day, $title, $description, $estimatedHours)
     */
    public function addCustomTask(JobPrepDay $day, string $title, string|int|null $serviceCategoryIdOrDescription = null, string|float|null $descriptionOrHours = null, ?User $addedBy = null): JobPrepTask
    {
        // Detect legacy call: 3rd arg is a string (description) or null, 4th is float (hours)
        if (is_string($serviceCategoryIdOrDescription) || ($serviceCategoryIdOrDescription === null && is_float($descriptionOrHours))) {
            $serviceCategoryId = null;
            $description = is_string($serviceCategoryIdOrDescription) ? $serviceCategoryIdOrDescription : null;
        } else {
            $serviceCategoryId = $serviceCategoryIdOrDescription;
            $description = is_string($descriptionOrHours) ? $descriptionOrHours : null;
        }

        $plan = $day->plan;

        $task = JobPrepTask::create([
            'job_prep_day_id' => $day->id,
            'job_prep_plan_id' => $plan->id,
            'service_category_id' => $serviceCategoryId,
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => $title,
            'description' => $description,
            'sort_order' => $day->tasks()->count(),
            'added_by' => $addedBy?->id,
        ]);

        $this->revokeApprovals($plan);

        JobActivity::log($plan->job, 'prep_task_added', "Custom task added: \"{$title}\"");

        PrepPlanUpdated::dispatch($plan->job, 'task_added', "Custom task added: \"{$title}\"");

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

        PrepPlanUpdated::dispatch($plan->job, 'task_removed', "Custom task removed: \"{$title}\"");

        return true;
    }

    /**
     * Approve the plan for a specific service category and party.
     */
    public function approveSection(JobPrepPlan $plan, User $user, string $party, int $serviceCategoryId): void
    {
        $approval = JobPrepApproval::firstOrCreate(
            [
                'job_prep_plan_id' => $plan->id,
                'service_category_id' => $serviceCategoryId,
                'party' => $party,
            ],
            ['approved' => false]
        );

        $approval->approve($user);

        JobActivity::log($plan->job, "prep_approved_{$party}", ucfirst($party).' approved prep for service category #'.$serviceCategoryId, [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'service_category_id' => $serviceCategoryId,
        ]);

        PrepApprovalChanged::dispatch($plan->job, $party, $serviceCategoryId, true);
    }

    /**
     * Approve the plan as a specific party (backwards-compatible legacy method).
     * Delegates to approveSection with the painting service category, and also
     * updates the legacy columns on the plan for backwards compatibility.
     */
    public function approvePlan(JobPrepPlan $plan, User $user, string $party): void
    {
        $field = match ($party) {
            'team' => ['team_approved', 'team_approved_at', 'team_approved_by'],
            'crew' => ['crew_approved', 'crew_approved_at', 'crew_approved_by'],
            'customer' => ['customer_approved', 'customer_approved_at', 'customer_approved_by'],
            default => throw new \InvalidArgumentException("Invalid party: {$party}"),
        };

        // Always update legacy columns for backwards compatibility
        $plan->update([
            $field[0] => true,
            $field[1] => now(),
            $field[2] => $user->id,
        ]);

        // Also update new-style approval if painting category exists
        $paintingCategory = ServiceCategory::where('slug', 'painting')->first();

        if ($paintingCategory) {
            $this->approveSection($plan, $user, $party, $paintingCategory->id);

            return;
        }

        $activityType = "prep_approved_{$party}";

        JobActivity::log($plan->job, $activityType, ucfirst($party).' approved the prep plan', [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        PrepApprovalChanged::dispatch($plan->job, $party, null, true);
    }

    /**
     * Revoke approvals for a specific service category only, except the specified party.
     */
    public function revokeServiceApprovals(JobPrepPlan $plan, int $serviceCategoryId, ?string $exceptParty = null): void
    {
        $query = $plan->approvals()
            ->where('service_category_id', $serviceCategoryId)
            ->where('approved', true);

        if ($exceptParty) {
            $query->where('party', '!=', $exceptParty);
        }

        $affectedApprovals = $query->get();

        foreach ($affectedApprovals as $approval) {
            $approval->revoke();
        }

        if ($affectedApprovals->isNotEmpty()) {
            JobActivity::log($plan->job, 'prep_approvals_reset', 'Approvals reset for service category #'.$serviceCategoryId, [
                'service_category_id' => $serviceCategoryId,
                'except_party' => $exceptParty,
            ]);

            PrepPlanUpdated::dispatch($plan->job, 'approvals_reset', 'Service approvals reset for category #'.$serviceCategoryId);
        }
    }

    /**
     * Reset all approvals (backwards-compatible).
     * Revokes ALL services' approvals.
     */
    public function revokeApprovals(JobPrepPlan $plan): void
    {
        // Revoke new-style approval rows
        $approvals = $plan->approvals()->where('approved', true)->get();
        foreach ($approvals as $approval) {
            $approval->revoke();
        }

        // Also reset legacy columns
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

        if ($wasApproved || $approvals->isNotEmpty()) {
            JobActivity::log($plan->job, 'prep_approvals_reset', 'Prep plan approvals reset due to plan changes');

            PrepPlanUpdated::dispatch($plan->job, 'approvals_reset', 'All approvals reset');
        }
    }

    /**
     * Propose changes to a service's prep settings.
     * Applies changes, recalculates days, resets other parties' approvals.
     */
    public function proposeChange(JobPrepPlan $plan, int $serviceCategoryId, User $proposer, array $changes): void
    {
        $setting = $plan->serviceSettings()
            ->where('service_category_id', $serviceCategoryId)
            ->first();

        if (! $setting) {
            return;
        }

        $updateData = [];
        if (isset($changes['crew_size'])) {
            $updateData['crew_size'] = $changes['crew_size'];
        }
        if (isset($changes['hours_per_day'])) {
            $updateData['hours_per_day'] = $changes['hours_per_day'];
        }

        if (! empty($updateData)) {
            $setting->update($updateData);
        }

        // Recalculate days for this service
        $this->recalculateServiceDays($plan, $serviceCategoryId);

        // Determine the proposer's party
        $party = match ($proposer->type) {
            'internal' => 'team',
            'crew' => 'crew',
            'customer' => 'customer',
            default => 'team',
        };

        // Reset other parties' approvals for this service
        $this->revokeServiceApprovals($plan, $serviceCategoryId, $party);

        JobActivity::log($plan->job, 'prep_change_proposed', ucfirst($party).' proposed changes to prep plan', [
            'user_id' => $proposer->id,
            'user_name' => $proposer->name,
            'service_category_id' => $serviceCategoryId,
            'changes' => $changes,
        ]);

        PrepPlanUpdated::dispatch($plan->job, 'change_proposed', ucfirst($party).' proposed changes');
    }

    /**
     * Recalculate days for a single service category.
     * Updates plan total_days = max of all services.
     */
    public function recalculateServiceDays(JobPrepPlan $plan, int $serviceCategoryId): void
    {
        $setting = $plan->serviceSettings()
            ->where('service_category_id', $serviceCategoryId)
            ->first();

        if ($setting) {
            $setting->update(['total_days' => $setting->calculateTotalDays()]);
        }

        // Recalculate plan total from max of all services
        $plan->refresh();
        $this->recalculateDays($plan);
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
     * Cycle a task through: pending → in_progress → completed → pending.
     */
    public function cycleTaskStatus(JobPrepTask $task, User $user): string
    {
        $newStatus = match ($task->status) {
            JobPrepTask::STATUS_PENDING => JobPrepTask::STATUS_IN_PROGRESS,
            JobPrepTask::STATUS_IN_PROGRESS => JobPrepTask::STATUS_COMPLETED,
            JobPrepTask::STATUS_COMPLETED => JobPrepTask::STATUS_PENDING,
            default => JobPrepTask::STATUS_IN_PROGRESS,
        };

        match ($newStatus) {
            JobPrepTask::STATUS_IN_PROGRESS => $task->markInProgress(),
            JobPrepTask::STATUS_COMPLETED => $task->markComplete($user),
            JobPrepTask::STATUS_PENDING => $task->revertToPending(),
        };

        $actionLabel = match ($newStatus) {
            JobPrepTask::STATUS_IN_PROGRESS => 'started',
            JobPrepTask::STATUS_COMPLETED => 'completed',
            JobPrepTask::STATUS_PENDING => 'reset to pending',
        };

        JobActivity::log($task->plan->job, 'prep_task_status_changed', "Task {$actionLabel}: \"{$task->title}\"", [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'new_status' => $newStatus,
        ]);

        return $newStatus;
    }

    /**
     * Get progress statistics for a plan.
     *
     * @return array{total: int, completed: int, in_progress: int, percent: float, per_day: array<int, array{day_number: int, total: int, completed: int, in_progress: int, percent: float, hours: float}>}
     */
    public function getProgress(JobPrepPlan $plan): array
    {
        $total = $plan->tasks()->count();
        $completed = $plan->tasks()->where('status', JobPrepTask::STATUS_COMPLETED)->count();
        $inProgress = $plan->tasks()->where('status', JobPrepTask::STATUS_IN_PROGRESS)->count();
        $percent = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

        $perDay = [];
        foreach ($plan->days()->with('tasks')->get() as $day) {
            $dayTotal = $day->tasks->count();
            $dayCompleted = $day->tasks->where('status', JobPrepTask::STATUS_COMPLETED)->count();
            $dayInProgress = $day->tasks->where('status', JobPrepTask::STATUS_IN_PROGRESS)->count();

            $perDay[] = [
                'day_number' => $day->day_number,
                'total' => $dayTotal,
                'completed' => $dayCompleted,
                'in_progress' => $dayInProgress,
                'percent' => $dayTotal > 0 ? round(($dayCompleted / $dayTotal) * 100, 1) : 0.0,
                'hours' => $day->tasks->sum('estimated_hours'),
            ];
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
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

        PrepPlanUpdated::dispatch($plan->job, 'tasks_distributed', 'Tasks auto-distributed across days');
    }

    /**
     * Add a new day to the end of the plan.
     */
    public function addDay(JobPrepPlan $plan): JobPrepDay
    {
        $maxDayNumber = $plan->days()->max('day_number') ?? 0;
        $newDayNumber = $maxDayNumber + 1;

        $day = JobPrepDay::create([
            'job_prep_plan_id' => $plan->id,
            'day_number' => $newDayNumber,
            'label' => "Day {$newDayNumber}",
            'sort_order' => $newDayNumber - 1,
        ]);

        $plan->update(['total_days' => $newDayNumber]);
        $this->revokeApprovals($plan);

        JobActivity::log($plan->job, 'prep_day_added', "Day {$newDayNumber} added to prep plan");
        PrepPlanUpdated::dispatch($plan->job, 'day_added', "Day {$newDayNumber} added");

        return $day;
    }

    /**
     * Delete an empty day and renumber remaining days.
     */
    public function deleteDay(JobPrepDay $day): bool
    {
        $plan = $day->plan;

        // Guard: day must be empty and not the last day
        if ($day->tasks()->count() > 0 || $plan->days()->count() <= 1) {
            return false;
        }

        $dayNumber = $day->day_number;
        $day->delete();

        // Renumber remaining days (two-pass to avoid unique constraint)
        $remainingDays = $plan->days()->orderBy('day_number')->get();

        // Pass 1: set to negative values
        foreach ($remainingDays as $i => $remainingDay) {
            $remainingDay->update(['day_number' => -($i + 1)]);
        }

        // Pass 2: set to correct sequential values
        foreach ($remainingDays as $i => $remainingDay) {
            $remainingDay->update([
                'day_number' => $i + 1,
                'label' => 'Day '.($i + 1),
                'sort_order' => $i,
            ]);
        }

        $plan->update(['total_days' => $remainingDays->count()]);
        $this->revokeApprovals($plan);

        JobActivity::log($plan->job, 'prep_day_deleted', "Day {$dayNumber} deleted from prep plan");
        PrepPlanUpdated::dispatch($plan->job, 'day_deleted', "Day {$dayNumber} deleted");

        return true;
    }

    /**
     * Request removal of a custom task.
     */
    public function requestTaskRemoval(JobPrepTask $task, User $requester, ?string $reason = null): void
    {
        if (! $task->isCustom()) {
            return;
        }

        $task->requestRemoval($requester, $reason);
        $this->revokeApprovals($task->plan);

        JobActivity::log($task->plan->job, 'prep_task_removal_requested', "Removal requested for: \"{$task->title}\"", [
            'task_id' => $task->id,
            'requested_by' => $requester->id,
            'reason' => $reason,
        ]);

        PrepPlanUpdated::dispatch($task->plan->job, 'task_removal_requested', "Task removal requested: \"{$task->title}\"");
    }

    /**
     * Approve a task removal request (deletes the task).
     */
    public function approveTaskRemoval(JobPrepTask $task): bool
    {
        return $this->removeTask($task);
    }

    /**
     * Dismiss a task removal request.
     */
    public function dismissTaskRemoval(JobPrepTask $task): void
    {
        $task->dismissRemovalRequest();

        JobActivity::log($task->plan->job, 'prep_task_removal_dismissed', "Removal request dismissed for: \"{$task->title}\"", [
            'task_id' => $task->id,
        ]);

        PrepPlanUpdated::dispatch($task->plan->job, 'task_removal_dismissed', "Task removal dismissed: \"{$task->title}\"");
    }
}
