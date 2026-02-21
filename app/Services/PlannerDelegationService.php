<?php

namespace App\Services;

use App\Models\PlannerDelegation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class PlannerDelegationService
{
    public function createDelegation(
        User $grantor,
        User $targetUser,
        User $delegate,
        string $accessLevel,
        bool $isPermanent = false,
        ?Carbon $startsAt = null,
        ?Carbon $expiresAt = null,
    ): PlannerDelegation {
        if ($targetUser->id === $delegate->id) {
            throw new \InvalidArgumentException('Cannot delegate planner access to the same user.');
        }

        $isOwnPlanner = $grantor->id === $targetUser->id;

        if ($isOwnPlanner) {
            if (! $grantor->can('planner.delegate_own')) {
                throw new AuthorizationException('You do not have permission to share your planner.');
            }
        } else {
            if (! $grantor->can('planner.delegate')) {
                throw new AuthorizationException('You do not have permission to delegate planner access for other users.');
            }
        }

        return PlannerDelegation::updateOrCreate(
            [
                'target_user_id' => $targetUser->id,
                'delegate_id' => $delegate->id,
            ],
            [
                'grantor_id' => $grantor->id,
                'access_level' => $accessLevel,
                'starts_at' => $startsAt,
                'expires_at' => $isPermanent ? null : $expiresAt,
                'is_permanent' => $isPermanent,
            ],
        );
    }

    public function revokeDelegation(int $delegationId, User $revoker): void
    {
        $delegation = PlannerDelegation::findOrFail($delegationId);

        $canRevoke = $revoker->id === $delegation->grantor_id
            || $revoker->id === $delegation->target_user_id
            || $revoker->can('planner.delegate');

        if (! $canRevoke) {
            throw new AuthorizationException('You do not have permission to revoke this delegation.');
        }

        $delegation->delete();
    }

    public function getDelegationsFor(User $delegate): Collection
    {
        return PlannerDelegation::query()
            ->forDelegate($delegate)
            ->active()
            ->with('targetUser')
            ->get();
    }

    public function getDelegatesFor(User $targetUser): Collection
    {
        return PlannerDelegation::query()
            ->forTarget($targetUser)
            ->active()
            ->with(['delegate', 'grantor'])
            ->get();
    }

    public function canActOnBehalf(User $delegate, User $targetUser, string $requiredLevel = 'view'): bool
    {
        $delegation = PlannerDelegation::query()
            ->forDelegate($delegate)
            ->forTarget($targetUser)
            ->active()
            ->first();

        if (! $delegation) {
            return false;
        }

        return $delegation->meetsAccessLevel($requiredLevel);
    }

    public function expireStale(): int
    {
        return PlannerDelegation::query()
            ->where('is_permanent', false)
            ->where('expires_at', '<', now())
            ->delete();
    }
}
