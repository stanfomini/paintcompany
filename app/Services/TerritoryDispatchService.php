<?php

namespace App\Services;

use App\Events\TerritoryClaimed;
use App\Events\TerritoryOffered;
use App\Events\TerritoryOfferExpired;
use App\Models\SalesTerritory;
use App\Models\Schedule;
use App\Models\TerritoryOffer;
use App\Models\TerritoryPreference;
use App\Models\User;
use App\Notifications\TerritoryScheduleAssigned;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TerritoryDispatchService
{
    /**
     * Get eligible sales reps for a territory on a given date.
     * Active internal users with territory_dispatch.claim permission,
     * not already assigned/claimed a territory for that day.
     */
    public function getEligibleReps(SalesTerritory $territory, Carbon $workDate): Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->where('is_active', true)
            ->permission('territory_dispatch.claim')
            ->whereDoesntHave('claimedTerritoryOffers', function ($q) use ($workDate) {
                $q->whereDate('work_date', $workDate->toDateString())
                    ->where('status', 'claimed');
            })
            ->get();
    }

    /**
     * Calculate round-robin priority queue for eligible users.
     * Returns array of [user_id => queue_position] sorted by priority score.
     *
     * Factors:
     * - days_since_last_assignment x 10 (capped at 50)
     * - (7 - assignments_this_week) x 8 (max 56)
     * - territory_preference bonus: preferred +20, neutral 0, avoid -20
     * - acceptance_rate x 10 (max 10)
     *
     * @return array<int, int>
     */
    public function calculatePriorityQueue(Collection $users, SalesTerritory $territory): array
    {
        $scores = [];

        foreach ($users as $user) {
            $score = 0;

            // Days since last assignment (capped at 5 = 50 points)
            $lastClaim = TerritoryOffer::where('claimed_by', $user->id)
                ->where('status', 'claimed')
                ->latest('claimed_at')
                ->first();
            $daysSince = $lastClaim ? $lastClaim->claimed_at->diffInDays(now()) : 5;
            $score += min($daysSince, 5) * 10;

            // Assignments this week (fewer = higher score)
            $weekStart = now()->startOfWeek();
            $assignmentsThisWeek = TerritoryOffer::where('claimed_by', $user->id)
                ->where('status', 'claimed')
                ->where('claimed_at', '>=', $weekStart)
                ->count();
            $score += max(0, 7 - $assignmentsThisWeek) * 8;

            // Territory preference
            $pref = TerritoryPreference::where('user_id', $user->id)
                ->where('territory_id', $territory->id)
                ->first();
            if ($pref) {
                $score += match ($pref->preference) {
                    'preferred' => 20,
                    'avoid' => -20,
                    default => 0,
                };
            }

            // Acceptance rate (based on last 20 offers)
            $recentOffers = DB::table('territory_offer_users')
                ->where('user_id', $user->id)
                ->whereNotNull('response')
                ->latest('responded_at')
                ->limit(20)
                ->get();
            if ($recentOffers->isNotEmpty()) {
                $accepted = $recentOffers->where('response', 'accepted')->count();
                $rate = $accepted / $recentOffers->count();
                $score += (int) ($rate * 10);
            } else {
                $score += 5; // neutral for new users
            }

            $scores[$user->id] = $score;
        }

        arsort($scores);

        $queue = [];
        $position = 1;
        foreach ($scores as $userId => $score) {
            $queue[$userId] = $position++;
        }

        return $queue;
    }

    /**
     * Create an offer and notify eligible users.
     */
    public function createOffer(
        SalesTerritory $territory,
        Carbon $workDate,
        array $eligibleUserIds,
        int $expiresInMinutes = 30
    ): TerritoryOffer {
        $offer = TerritoryOffer::create([
            'territory_id' => $territory->id,
            'work_date' => $workDate,
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addMinutes($expiresInMinutes),
            'created_by' => auth()->id(),
        ]);

        $priorityQueue = $this->calculatePriorityQueue(
            User::whereIn('id', $eligibleUserIds)->get(),
            $territory
        );

        foreach ($priorityQueue as $userId => $position) {
            $offer->eligibleUsers()->attach($userId, [
                'queue_position' => $position,
                'notified_at' => now(),
                'response' => 'pending',
            ]);

            TerritoryOffered::dispatch($offer, $userId, $position, count($priorityQueue));
        }

        return $offer;
    }

    /**
     * Claim an offer with race-condition-safe row locking.
     * Uses DB::transaction + lockForUpdate() pattern from JobDispatchService.
     */
    public function claimOffer(TerritoryOffer $offer, User $user): bool
    {
        return DB::transaction(function () use ($offer, $user) {
            $locked = TerritoryOffer::where('id', $offer->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->isExpired()) {
                return false;
            }

            $locked->update([
                'status' => 'claimed',
                'claimed_by' => $user->id,
                'claimed_at' => now(),
            ]);

            // Update this user's pivot to accepted
            $locked->eligibleUsers()->updateExistingPivot($user->id, [
                'response' => 'accepted',
                'responded_at' => now(),
            ]);

            // Mark all other pending users as expired
            DB::table('territory_offer_users')
                ->where('territory_offer_id', $locked->id)
                ->where('user_id', '!=', $user->id)
                ->where('response', 'pending')
                ->update([
                    'response' => 'expired',
                    'responded_at' => now(),
                ]);

            TerritoryClaimed::dispatch($locked);

            return true;
        });
    }

    /**
     * Decline an offer for a specific user.
     */
    public function declineOffer(TerritoryOffer $offer, User $user): bool
    {
        $pivot = DB::table('territory_offer_users')
            ->where('territory_offer_id', $offer->id)
            ->where('user_id', $user->id)
            ->where('response', 'pending')
            ->first();

        if (! $pivot) {
            return false;
        }

        DB::table('territory_offer_users')
            ->where('territory_offer_id', $offer->id)
            ->where('user_id', $user->id)
            ->update([
                'response' => 'declined',
                'responded_at' => now(),
            ]);

        return true;
    }

    /**
     * Bulk expire past-due offers. Returns count of expired offers.
     */
    public function expireOffers(): int
    {
        $expiredOffers = TerritoryOffer::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredOffers as $offer) {
            $offer->update(['status' => 'expired']);

            DB::table('territory_offer_users')
                ->where('territory_offer_id', $offer->id)
                ->where('response', 'pending')
                ->update([
                    'response' => 'expired',
                    'responded_at' => now(),
                ]);

            TerritoryOfferExpired::dispatch($offer);
        }

        return $expiredOffers->count();
    }

    /**
     * Claim a flex block with race-condition-safe row locking.
     * Reuses the same DB::transaction + lockForUpdate() pattern from claimOffer().
     */
    public function claimFlexBlock(Schedule $schedule, User $user): bool
    {
        if (! $user->employeeProfile) {
            return false;
        }

        return DB::transaction(function () use ($schedule, $user) {
            $locked = Schedule::where('id', $schedule->id)
                ->where('is_flex', true)
                ->whereNull('employee_profile_id')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $locked->update([
                'employee_profile_id' => $user->employeeProfile->id,
            ]);

            $user->notify(new TerritoryScheduleAssigned($locked));

            return true;
        });
    }
}
