<?php

namespace App\Services;

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\MessengerResponder;
use App\Models\MessengerSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MessengerLeadAssignmentService
{
    /**
     * Get the next estimator via round-robin.
     * Uses DB-level locking for concurrency safety.
     */
    public function getNextEstimator(): ?User
    {
        return DB::transaction(function () {
            $responder = MessengerResponder::query()
                ->active()
                ->estimators()
                ->orderByRaw('last_assigned_at IS NOT NULL, last_assigned_at ASC')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->first();

            if (! $responder) {
                return null;
            }

            $responder->update(['last_assigned_at' => now()]);

            return $responder->user;
        });
    }

    /**
     * Directly assign a deal to a specific estimator (self-claim).
     */
    public function assignToEstimator(Deal $deal, User $estimator): void
    {
        $deal->update(['assigned_to' => $estimator->id]);

        // Update the responder's last_assigned_at for round-robin tracking
        MessengerResponder::where('user_id', $estimator->id)
            ->active()
            ->update(['last_assigned_at' => now()]);
    }

    /**
     * Assign a deal to the next estimator via round-robin.
     * Falls back to default_assigned_to if no roster exists.
     */
    public function assignViaRoundRobin(Deal $deal): ?User
    {
        $estimator = $this->getNextEstimator();

        if ($estimator) {
            $deal->update(['assigned_to' => $estimator->id]);

            return $estimator;
        }

        // Fallback to default_assigned_to
        $settings = MessengerSetting::getInstance();
        if ($settings->default_assigned_to) {
            $deal->update(['assigned_to' => $settings->default_assigned_to]);

            return User::find($settings->default_assigned_to);
        }

        return null;
    }

    /**
     * Get the role of a user in the responder roster.
     */
    public function getResponderRole(User $user): ?string
    {
        return MessengerResponder::where('user_id', $user->id)
            ->active()
            ->value('role');
    }

    /**
     * Check if a user is an active messenger responder.
     */
    public function isActiveResponder(User $user): bool
    {
        return MessengerResponder::where('user_id', $user->id)
            ->active()
            ->exists();
    }

    /**
     * Get the user to set as created_by for new leads.
     * First active responder, fallback to default_assigned_to, then first internal user.
     */
    public function getCreatedByUser(): ?User
    {
        // First active responder
        $responder = MessengerResponder::query()
            ->active()
            ->orderBy('sort_order')
            ->with('user')
            ->first();

        if ($responder?->user) {
            return $responder->user;
        }

        // Fallback to default_assigned_to
        $settings = MessengerSetting::getInstance();
        if ($settings->default_assigned_to) {
            return User::find($settings->default_assigned_to);
        }

        // Last resort: first internal user
        return User::where('type', 'internal')->first();
    }

    /**
     * Claim a thread for a specific user — remove all other responder participants.
     */
    public function claimThread(ChatThread $thread, User $claimer): void
    {
        $responderUserIds = MessengerResponder::query()
            ->active()
            ->pluck('user_id')
            ->reject(fn ($id) => $id === $claimer->id);

        foreach ($responderUserIds as $userId) {
            $thread->participants()
                ->where('user_id', $userId)
                ->whereNull('left_at')
                ->update(['left_at' => now()]);
        }
    }
}
