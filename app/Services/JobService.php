<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\User;
use App\Notifications\CustomerConfirmedColors;
use App\Notifications\CustomerConfirmedDate;
use App\Notifications\CustomerProposedDate;
use App\Notifications\JobCompleted;
use App\Notifications\JobCreated;
use App\Notifications\JobStageAdvanced;
use Carbon\Carbon;

class JobService
{
    /**
     * Create a Job from an accepted estimate.
     */
    public function createFromEstimate(Estimate $estimate): Job
    {
        $deal = $estimate->deal;
        $settings = CompanySetting::getInstance();

        $job = Job::create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
            'job_number' => Job::generateJobNumber(),
            'stage' => 'estimate_accepted',
            'title' => $deal->title,
            'description' => $estimate->description ?? $deal->description,
            'required_crew_size' => $settings->default_required_crew_size ?? 2,
        ]);

        JobActivity::log($job, 'stage_changed', 'Job created from accepted estimate', [
            'stage' => 'estimate_accepted',
            'estimate_id' => $estimate->id,
        ]);

        // Notify internal users
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new JobCreated($job));
        }

        return $job;
    }

    /**
     * Advance job to next stage.
     */
    public function advanceStage(Job $job): bool
    {
        $oldStage = $job->stage;
        $result = $job->advanceStage();

        if ($result) {
            JobActivity::log($job, 'stage_changed', "Stage changed from {$oldStage} to {$job->stage}", [
                'from' => $oldStage,
                'to' => $job->stage,
            ]);

            $assignedUser = $job->deal?->assignedTo;
            if ($assignedUser) {
                $assignedUser->notify(new JobStageAdvanced($job, $oldStage, $job->stage));
            }
        }

        return $result;
    }

    /**
     * Set the scheduled date.
     */
    public function setScheduledDate(Job $job, Carbon $date): void
    {
        $job->update(['scheduled_date' => $date]);

        JobActivity::log($job, 'date_proposed', 'Scheduled date set to '.$date->format('M j, Y'), [
            'date' => $date->toDateString(),
        ]);
    }

    /**
     * Confirm the date from the company side.
     */
    public function confirmCompanyDate(Job $job): void
    {
        $job->update(['company_date_confirmed' => true]);

        JobActivity::log($job, 'date_confirmed_company', 'Company confirmed the scheduled date');
    }

    /**
     * Confirm the date from the customer side.
     */
    public function confirmCustomerDate(Job $job): void
    {
        $job->update(['customer_date_confirmed' => true]);

        JobActivity::log($job, 'date_confirmed_customer', 'Customer confirmed the scheduled date');

        $assignedUser = $job->deal?->assignedTo;
        if ($assignedUser) {
            $assignedUser->notify(new CustomerConfirmedDate($job));
        }
    }

    /**
     * Set the allowed number of colors.
     */
    public function setAllowedColors(Job $job, int $count): void
    {
        $job->update(['allowed_colors' => $count]);
    }

    /**
     * Confirm colors from the company side.
     */
    public function confirmCompanyColors(Job $job): void
    {
        $job->update(['company_colors_confirmed' => true]);

        JobActivity::log($job, 'colors_confirmed_company', 'Company confirmed color selections');
    }

    /**
     * Confirm colors from the customer side.
     */
    public function confirmCustomerColors(Job $job): void
    {
        $job->update(['customer_colors_confirmed' => true]);

        JobActivity::log($job, 'colors_confirmed_customer', 'Customer confirmed color selections');

        $assignedUser = $job->deal?->assignedTo;
        if ($assignedUser) {
            $assignedUser->notify(new CustomerConfirmedColors($job));
        }
    }

    /**
     * Propose a new scheduled date from the customer side.
     * Sets the date, auto-confirms customer, resets company confirmation,
     * and sends a system message to the deal's chat thread.
     */
    public function proposeCustomerDate(Job $job, Carbon $date): void
    {
        $job->update([
            'scheduled_date' => $date,
            'customer_date_confirmed' => true,
            'company_date_confirmed' => false,
        ]);

        JobActivity::log($job, 'date_proposed', 'Customer proposed date: '.$date->format('M j, Y'), [
            'date' => $date->toDateString(),
            'proposed_by' => 'customer',
        ]);

        $deal = $job->deal;
        if ($deal) {
            $thread = $deal->getOrCreateChatThread();
            $chatService = app(ChatService::class);

            $customer = $deal->customer;
            if ($customer?->user && ! $thread->hasParticipant($customer->user)) {
                $thread->addParticipant($customer->user);
            }

            $chatService->sendSystemMessage(
                $thread,
                'Customer proposed a new start date: '.$date->format('l, F j, Y')
            );

            $assignedUser = $deal->assignedTo;
            if ($assignedUser) {
                $assignedUser->notify(new CustomerProposedDate($job, $date->format('l, F j, Y')));
            }
        }
    }

    /**
     * Check if the job is ready for dispatch (all confirmations in).
     */
    public function isReadyForDispatch(Job $job): bool
    {
        return $job->isReadyForDispatch();
    }

    /**
     * Complete a job.
     */
    public function completeJob(Job $job): void
    {
        $job->update([
            'stage' => 'completed',
            'actual_end_date' => now(),
        ]);

        JobActivity::log($job, 'completed', 'Job marked as completed');

        // Notify assigned user
        $assignedUser = $job->deal?->assignedTo;
        if ($assignedUser) {
            $assignedUser->notify(new JobCompleted($job));
        }

        // Notify customer
        $customerUser = $job->getCustomer()?->user;
        if ($customerUser) {
            $customerUser->notify(new JobCompleted($job));
        }

        // Also complete the deal
        $job->deal?->moveTo('completed');
    }
}
