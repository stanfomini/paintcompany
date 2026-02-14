<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;

class PriorityScoreService
{
    /**
     * Calculate the priority score for a single activity.
     */
    public function calculateScore(Activity $activity): int
    {
        $score = 0;

        // Completed activities sink to the bottom
        if ($activity->is_completed) {
            return -100;
        }

        // Manual priority flags
        $score += match ($activity->priority) {
            'urgent' => 40,
            'high' => 20,
            'low' => -10,
            default => 0,
        };

        // Due date scoring
        if ($activity->due_at) {
            $daysUntilDue = (int) now()->startOfDay()->diffInDays($activity->due_at->startOfDay(), false);

            if ($daysUntilDue < -2) {
                $score += 50; // Overdue 3+ days
            } elseif ($daysUntilDue < 0) {
                $score += 30; // Overdue 1-2 days
            } elseif ($daysUntilDue === 0) {
                $score += 20; // Due today
            } elseif ($daysUntilDue === 1) {
                $score += 10; // Due tomorrow
            }
        } else {
            $score -= 5; // No due date = less urgent
        }

        // New lead bonus: deal subject created within 24 hours
        if ($activity->subject_type === Deal::class && $activity->subject) {
            $deal = $activity->subject;
            if ($deal->created_at && $deal->created_at->diffInHours(now()) < 24 && $deal->stage === 'lead') {
                $score += 35;
            }
        }

        return $score;
    }

    /**
     * Calculate and persist the priority score for a single activity.
     */
    public function updateScore(Activity $activity): void
    {
        $score = $this->calculateScore($activity);

        if ($activity->priority_score !== $score) {
            $activity->updateQuietly(['priority_score' => $score]);
        }
    }

    /**
     * Recalculate priority scores for all incomplete activities for a user.
     */
    public function recalculateForUser(User $user): int
    {
        $activities = Activity::query()
            ->forUserOrAssignedTo($user)
            ->incomplete()
            ->with('subject')
            ->get();

        $updated = 0;
        foreach ($activities as $activity) {
            $oldScore = $activity->priority_score;
            $newScore = $this->calculateScore($activity);

            if ($oldScore !== $newScore) {
                $activity->updateQuietly(['priority_score' => $newScore]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Recalculate priority scores for all incomplete activities.
     */
    public function recalculateAll(): int
    {
        $activities = Activity::query()
            ->incomplete()
            ->with('subject')
            ->get();

        $updated = 0;
        foreach ($activities as $activity) {
            $oldScore = $activity->priority_score;
            $newScore = $this->calculateScore($activity);

            if ($oldScore !== $newScore) {
                $activity->updateQuietly(['priority_score' => $newScore]);
                $updated++;
            }
        }

        return $updated;
    }
}
