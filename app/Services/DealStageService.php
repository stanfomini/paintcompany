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

        $deal->update([
            'stage' => $newStage,
            'follow_up_date' => in_array($newStage, Deal::PLANNER_STAGES)
                ? ($followUpDate ?? now()->addDay()->toDateString())
                : $deal->follow_up_date,
        ]);

        if (in_array($newStage, Deal::PLANNER_STAGES)) {
            $this->createFollowUpActivity($deal, $followUpDate);
        }

        if ($newStage === 'quote_scheduled') {
            $this->createQuoteScheduledActivity($deal, $followUpDate);
        }
    }

    /**
     * Create a quote-scheduled activity linked to the deal.
     */
    protected function createQuoteScheduledActivity(Deal $deal, ?string $scheduledDate = null): void
    {
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
        ]);
    }

    /**
     * Create a follow-up activity linked to the deal.
     */
    protected function createFollowUpActivity(Deal $deal, ?string $followUpDate = null): void
    {
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
        ]);
    }
}
