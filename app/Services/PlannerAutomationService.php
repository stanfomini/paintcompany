<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class PlannerAutomationService
{
    /**
     * Create an automated task activity with deduplication.
     * Won't create if an incomplete activity with the same key+subject exists.
     */
    private function createAutomatedActivity(
        string $key,
        Model $subject,
        string $type,
        string $title,
        ?CarbonInterface $dueAt,
        ?int $assignedTo,
    ): ?Activity {
        // Check for existing incomplete activity with same key and subject
        $exists = Activity::query()
            ->byAutomationKey($key)
            ->forSubject($subject)
            ->incomplete()
            ->exists();

        if ($exists) {
            return null;
        }

        return Activity::create([
            'user_id' => $assignedTo ?? auth()->id() ?? 1,
            'assigned_to' => $assignedTo ?? auth()->id() ?? 1,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $type,
            'title' => $title,
            'due_at' => $dueAt,
            'is_completed' => false,
            'automation_key' => $key,
        ]);
    }

    /**
     * Resolve who should be assigned job-related tasks.
     * Prefers deal.assigned_to, falls back to deal.created_by.
     */
    private function resolveJobAssignee(Job $job): int
    {
        return $job->deal?->assigned_to
            ?? $job->deal?->created_by
            ?? auth()->id()
            ?? 1;
    }

    /**
     * Auto-complete all open activities matching a key+subject.
     */
    private function autoComplete(string $key, Model $subject): void
    {
        Activity::query()
            ->byAutomationKey($key)
            ->forSubject($subject)
            ->incomplete()
            ->get()
            ->each->markAsCompleted();
    }

    /**
     * Create an informational note (auto-completed on creation).
     * These appear in the feed timeline but don't show as pending work.
     */
    private function logInfoActivity(string $key, Model $subject, string $title, ?int $assignedTo): Activity
    {
        return Activity::create([
            'user_id' => $assignedTo ?? auth()->id() ?? 1,
            'assigned_to' => $assignedTo ?? auth()->id() ?? 1,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => 'note',
            'title' => $title,
            'is_completed' => true,
            'completed_at' => now(),
            'automation_key' => $key,
        ]);
    }

    // ========================================
    // Job Lifecycle
    // ========================================

    /**
     * Job created from accepted estimate.
     * Creates: TASK "Schedule date & colors: {customer}"
     */
    public function onJobCreated(Job $job): void
    {
        $customerName = $job->getCustomer()?->full_name ?? 'Customer';
        $assignee = $this->resolveJobAssignee($job);

        $this->createAutomatedActivity(
            key: 'job_scheduling_needed',
            subject: $job,
            type: 'task',
            title: "Schedule date & colors: {$customerName}",
            dueAt: now()->addDay(),
            assignedTo: $assignee,
        );
    }

    /**
     * Customer proposed a new date.
     * Creates: TASK "Review proposed date: {customer} - {date}"
     */
    public function onCustomerProposedDate(Job $job, string $date): void
    {
        $customerName = $job->getCustomer()?->full_name ?? 'Customer';
        $assignee = $this->resolveJobAssignee($job);

        $this->createAutomatedActivity(
            key: 'review_proposed_date',
            subject: $job,
            type: 'task',
            title: "Review proposed date: {$customerName} - {$date}",
            dueAt: now(),
            assignedTo: $assignee,
        );
    }

    /**
     * Company confirmed the scheduled date.
     * Creates: NOTE + checks if ready for dispatch.
     */
    public function onCompanyDateConfirmed(Job $job): void
    {
        $assignee = $this->resolveJobAssignee($job);

        $this->logInfoActivity('company_confirmed_date', $job, 'Company confirmed scheduled date', $assignee);

        // Auto-complete proposed date review if company is confirming
        $this->autoComplete('review_proposed_date', $job);

        $this->checkReadyForDispatch($job);
    }

    /**
     * Customer confirmed the scheduled date.
     * Creates: NOTE + checks if ready for dispatch.
     */
    public function onCustomerDateConfirmed(Job $job): void
    {
        $assignee = $this->resolveJobAssignee($job);

        $this->logInfoActivity('customer_confirmed_date', $job, 'Customer confirmed scheduled date', $assignee);

        $this->checkReadyForDispatch($job);
    }

    /**
     * Company confirmed color selections.
     * Creates: NOTE + checks if ready for dispatch.
     */
    public function onCompanyColorsConfirmed(Job $job): void
    {
        $assignee = $this->resolveJobAssignee($job);

        $this->logInfoActivity('company_confirmed_colors', $job, 'Company confirmed color selections', $assignee);

        $this->checkReadyForDispatch($job);
    }

    /**
     * Customer confirmed color selections.
     * Creates: NOTE + checks if ready for dispatch.
     */
    public function onCustomerColorsConfirmed(Job $job): void
    {
        $assignee = $this->resolveJobAssignee($job);

        $this->logInfoActivity('customer_confirmed_colors', $job, 'Customer confirmed color selections', $assignee);

        $this->checkReadyForDispatch($job);
    }

    /**
     * Check if job is ready for dispatch after a confirmation event.
     * If all 4 confirmations done: auto-complete scheduling task + create dispatch task.
     */
    private function checkReadyForDispatch(Job $job): void
    {
        if (! $job->fresh()->isReadyForDispatch()) {
            return;
        }

        // Auto-complete the scheduling task
        $this->autoComplete('job_scheduling_needed', $job);

        // Create "Ready to dispatch" task
        $jobTitle = $job->title ?? 'Job #'.$job->job_number;
        $assignee = $this->resolveJobAssignee($job);

        $this->createAutomatedActivity(
            key: 'ready_for_dispatch',
            subject: $job,
            type: 'task',
            title: "Ready to dispatch: {$jobTitle}",
            dueAt: now(),
            assignedTo: $assignee,
        );
    }

    /**
     * Crew dispatched to job.
     * Creates: TASK "Awaiting crew response: {job_title}"
     * Auto-completes: ready_for_dispatch
     */
    public function onCrewDispatched(Job $job, int $count): void
    {
        $jobTitle = $job->title ?? 'Job #'.$job->job_number;
        $assignee = $this->resolveJobAssignee($job);

        // Auto-complete dispatch readiness task
        $this->autoComplete('ready_for_dispatch', $job);

        $this->createAutomatedActivity(
            key: 'await_crew_response',
            subject: $job,
            type: 'task',
            title: "Awaiting crew response: {$jobTitle}",
            dueAt: now()->addDay(),
            assignedTo: $assignee,
        );
    }

    /**
     * Crew accepted the job.
     * Creates: NOTE "{crew_name} accepted job"
     * Auto-completes: await_crew_response
     */
    public function onCrewAccepted(Job $job, User $crew): void
    {
        $jobTitle = $job->title ?? 'Job #'.$job->job_number;
        $assignee = $this->resolveJobAssignee($job);

        $this->logInfoActivity('crew_accepted', $job, "{$crew->name} accepted job: {$jobTitle}", $assignee);

        $this->autoComplete('await_crew_response', $job);
    }

    /**
     * Crew declined the job.
     * Creates: NOTE "{crew_name} declined job"
     */
    public function onCrewRejected(Job $job, User $crew): void
    {
        $jobTitle = $job->title ?? 'Job #'.$job->job_number;
        $assignee = $this->resolveJobAssignee($job);

        $this->logInfoActivity('crew_declined', $job, "{$crew->name} declined job: {$jobTitle}", $assignee);
    }

    /**
     * Job marked as completed.
     * Creates: TASK "Review completed job"
     * Auto-completes: all open job tasks
     */
    public function onJobCompleted(Job $job): void
    {
        $jobTitle = $job->title ?? 'Job #'.$job->job_number;
        $assignee = $this->resolveJobAssignee($job);

        // Clean up all open tasks for this job
        $keysToComplete = [
            'job_scheduling_needed',
            'review_proposed_date',
            'ready_for_dispatch',
            'await_crew_response',
        ];

        foreach ($keysToComplete as $key) {
            $this->autoComplete($key, $job);
        }

        $this->createAutomatedActivity(
            key: 'job_completed_review',
            subject: $job,
            type: 'task',
            title: "Review completed job: {$jobTitle}",
            dueAt: now()->addDay(),
            assignedTo: $assignee,
        );
    }

    // ========================================
    // Estimate Lifecycle
    // ========================================

    /**
     * Estimate sent to customer.
     * Creates: TASK "Follow up on proposal: {customer}" due +3 days
     */
    public function onEstimateSent(Estimate $estimate): void
    {
        $customerName = $estimate->deal?->customer?->full_name ?? 'Customer';
        $assignee = $estimate->deal?->assigned_to
            ?? $estimate->deal?->created_by
            ?? $estimate->created_by
            ?? auth()->id()
            ?? 1;

        $this->createAutomatedActivity(
            key: 'estimate_sent_followup',
            subject: $estimate,
            type: 'task',
            title: "Follow up on proposal: {$customerName}",
            dueAt: now()->addDays(3),
            assignedTo: $assignee,
        );
    }

    /**
     * Customer signed the estimate.
     * Creates: NOTE "Customer signed: {estimate_number}"
     * Auto-completes: estimate_sent_followup
     */
    public function onEstimateSigned(Estimate $estimate): void
    {
        $assignee = $estimate->deal?->assigned_to
            ?? $estimate->deal?->created_by
            ?? $estimate->created_by
            ?? auth()->id()
            ?? 1;

        $this->logInfoActivity(
            'estimate_signed',
            $estimate,
            "Customer signed: {$estimate->estimate_number}",
            $assignee,
        );

        $this->autoComplete('estimate_sent_followup', $estimate);
    }

    /**
     * Customer rejected the estimate.
     * Creates: NOTE "Customer rejected: {estimate_number}"
     * Auto-completes: estimate_sent_followup
     */
    public function onEstimateRejected(Estimate $estimate): void
    {
        $assignee = $estimate->deal?->assigned_to
            ?? $estimate->deal?->created_by
            ?? $estimate->created_by
            ?? auth()->id()
            ?? 1;

        $this->logInfoActivity(
            'estimate_rejected',
            $estimate,
            "Customer rejected: {$estimate->estimate_number}",
            $assignee,
        );

        $this->autoComplete('estimate_sent_followup', $estimate);
    }
}
