<?php

namespace App\Services;

use App\Events\JobColorUpdated;
use App\Events\JobDateUpdated;
use App\Events\JobStageChanged;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Notifications\CustomerConfirmedColors;
use App\Notifications\CustomerConfirmedDate;
use App\Notifications\CustomerProposedDate;
use App\Notifications\JobCompleted;
use App\Notifications\JobCreated;
use App\Notifications\JobStageAdvanced;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class JobService
{
    private PlannerAutomationService $plannerAutomation;

    public function __construct(?PlannerAutomationService $plannerAutomation = null)
    {
        $this->plannerAutomation = $plannerAutomation ?? app(PlannerAutomationService::class);
    }

    /**
     * Create a Job from an accepted estimate.
     * Auto-creates painting JobServiceRequirement and additional requirements
     * for estimate options that have a service_category_id.
     */
    public function createFromEstimate(Estimate $estimate): Job
    {
        $deal = $estimate->deal;
        $settings = CompanySetting::getInstance();

        $job = Job::create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
            'sales_person_id' => $deal->assigned_to,
            'job_number' => Job::generateJobNumber(),
            'stage' => 'estimate_accepted',
            'title' => $deal->title,
            'description' => $estimate->description ?? $deal->description,
            'required_crew_size' => $settings->default_required_crew_size ?? 2,
        ]);

        // Auto-create painting service requirement
        $paintingCategory = ServiceCategory::where('slug', 'painting')->first();
        if ($paintingCategory) {
            JobServiceRequirement::create([
                'job_id' => $job->id,
                'service_category_id' => $paintingCategory->id,
                'required_crew_size' => $settings->default_required_crew_size ?? 2,
                'status' => JobServiceRequirement::STATUS_PENDING,
            ]);
        }

        // Check estimate options for additional service categories
        $optionServiceIds = $estimate->options()
            ->whereNotNull('service_category_id')
            ->where('is_selected', true)
            ->pluck('service_category_id')
            ->unique();

        foreach ($optionServiceIds as $serviceCategoryId) {
            // Skip if it's the painting category (already created)
            if ($paintingCategory && $serviceCategoryId === $paintingCategory->id) {
                continue;
            }

            JobServiceRequirement::create([
                'job_id' => $job->id,
                'service_category_id' => $serviceCategoryId,
                'required_crew_size' => 1,
                'status' => JobServiceRequirement::STATUS_PENDING,
            ]);
        }

        JobActivity::log($job, 'stage_changed', 'Job created from accepted estimate', [
            'stage' => 'estimate_accepted',
            'estimate_id' => $estimate->id,
        ]);

        // Notify internal users
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new JobCreated($job));
        }

        $this->plannerAutomation->onJobCreated($job);

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

            // Update cost card status when job moves to in_progress
            if ($job->stage === 'in_progress') {
                app(JobCostCardService::class)->updateCardToActive($job);
            }

            JobStageChanged::dispatch($job, $oldStage, $job->stage);
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

        JobDateUpdated::dispatch($job, null, $date->toDateString(), false);
    }

    /**
     * Confirm the date from the company side.
     */
    public function confirmCompanyDate(Job $job): void
    {
        $job->update(['company_date_confirmed' => true]);

        JobActivity::log($job, 'date_confirmed_company', 'Company confirmed the scheduled date');

        JobDateUpdated::dispatch($job, null, $job->scheduled_date?->toDateString(), true);

        $this->plannerAutomation->onCompanyDateConfirmed($job);
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

        JobDateUpdated::dispatch($job, null, $job->scheduled_date?->toDateString(), true);

        $this->plannerAutomation->onCustomerDateConfirmed($job);
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

        JobColorUpdated::dispatch($job, 'confirmed', 'company');

        $this->plannerAutomation->onCompanyColorsConfirmed($job);
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

        JobColorUpdated::dispatch($job, 'confirmed', 'customer');

        $this->plannerAutomation->onCustomerColorsConfirmed($job);
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

        $customerUser = $job->getCustomer()?->user;
        JobDateUpdated::dispatch($job, $customerUser?->id, $date->toDateString(), false);

        $this->plannerAutomation->onCustomerProposedDate($job, $date->format('M j, Y'));
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

        // Update cost card status to completed
        app(JobCostCardService::class)->updateCardOnJobCompletion($job);

        // Auto-create commission entries for job completion
        app(CommissionService::class)->createFromJob($job);

        $this->plannerAutomation->onJobCompleted($job);

        // Also complete the deal
        $job->deal?->moveTo('completed');

        // Auto-create and send final invoice
        try {
            $invoiceService = app(InvoiceService::class);
            $invoice = $invoiceService->createFinalInvoice($job);

            if ($invoice->total > 0) {
                $invoiceService->sendInvoice($invoice);
            } else {
                $invoiceService->markAsPaid($invoice);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to create final invoice for job #'.$job->job_number.': '.$e->getMessage());
        }
    }
}
