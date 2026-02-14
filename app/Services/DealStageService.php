<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;

class DealStageService
{
    /**
     * Transition a deal to a new stage with side effects.
     */
    public function transitionTo(Deal $deal, string $newStage, ?string $followUpDate = null): void
    {
        if (! array_key_exists($newStage, Deal::STAGES)) {
            return;
        }

        $oldStage = $deal->stage;

        $deal->update([
            'stage' => $newStage,
            'follow_up_date' => in_array($newStage, Deal::PLANNER_STAGES)
                ? ($followUpDate ?? now()->addDay()->toDateString())
                : $deal->follow_up_date,
        ]);

        // Auto-complete prior activities based on stage transitions
        $this->autoCompletePriorActivities($deal, $oldStage, $newStage);

        if (in_array($newStage, Deal::PLANNER_STAGES)) {
            $this->createFollowUpActivity($deal, $followUpDate);
        }

        if ($newStage === 'quote_scheduled') {
            $this->createQuoteScheduledActivity($deal, $followUpDate);
        }
    }

    /**
     * Auto-complete prior activities when a deal transitions stages.
     */
    protected function autoCompletePriorActivities(Deal $deal, string $oldStage, string $newStage): void
    {
        // Deal reaches terminal stage -> complete ALL deal activities
        if (in_array($newStage, Deal::TERMINAL_STAGES)) {
            Activity::query()
                ->forSubject($deal)
                ->incomplete()
                ->get()
                ->each->markAsCompleted();

            return;
        }

        // Deal leaves planner stages OR moves to quote_scheduled -> complete follow-up activities
        $wasPlannerStage = in_array($oldStage, Deal::PLANNER_STAGES);
        $isNowQuoteScheduled = $newStage === 'quote_scheduled';

        if ($wasPlannerStage || $isNowQuoteScheduled) {
            Activity::query()
                ->byAutomationKey('deal_follow_up')
                ->forSubject($deal)
                ->incomplete()
                ->get()
                ->each->markAsCompleted();
        }
    }

    /**
     * Create a quote-scheduled activity linked to the deal.
     */
    protected function createQuoteScheduledActivity(Deal $deal, ?string $scheduledDate = null): void
    {
        // Dedup: don't create if an incomplete one already exists for this deal
        $exists = Activity::query()
            ->byAutomationKey('deal_quote_scheduled')
            ->forSubject($deal)
            ->incomplete()
            ->exists();

        if ($exists) {
            return;
        }

        $customerName = $deal->customer?->full_name ?? 'Unknown';
        $dueAt = $scheduledDate
            ? \Carbon\Carbon::parse($scheduledDate)
            : now()->addDay()->setTime(9, 0);

        // If only a date was provided (no time component), default to 9am
        if ($dueAt->format('H:i') === '00:00') {
            $dueAt->setTime(9, 0);
        }

        Activity::create([
            'user_id' => $deal->assigned_to ?? $deal->created_by ?? auth()->id(),
            'assigned_to' => $deal->assigned_to ?? $deal->created_by ?? auth()->id(),
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'meeting',
            'title' => "Quote appointment: {$customerName}",
            'due_at' => $dueAt,
            'is_completed' => false,
            'automation_key' => 'deal_quote_scheduled',
        ]);
    }

    /**
     * Create a follow-up activity linked to the deal.
     */
    protected function createFollowUpActivity(Deal $deal, ?string $followUpDate = null): void
    {
        // Dedup: don't create if an incomplete one already exists for this deal
        $exists = Activity::query()
            ->byAutomationKey('deal_follow_up')
            ->forSubject($deal)
            ->incomplete()
            ->exists();

        if ($exists) {
            return;
        }

        $customerName = $deal->customer?->full_name ?? 'Unknown';
        $dueAt = $followUpDate
            ? \Carbon\Carbon::parse($followUpDate)->setTime(9, 0)
            : now()->addDay()->setTime(9, 0);

        Activity::create([
            'user_id' => $deal->assigned_to ?? $deal->created_by ?? auth()->id(),
            'assigned_to' => $deal->assigned_to ?? $deal->created_by ?? auth()->id(),
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => "Follow up: {$customerName} - {$deal->title}",
            'due_at' => $dueAt,
            'is_completed' => false,
            'automation_key' => 'deal_follow_up',
        ]);
    }
}
