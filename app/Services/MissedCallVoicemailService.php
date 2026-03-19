<?php

namespace App\Services;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MissedCallVoicemailService
{
    /**
     * Count unhandled missed calls/voicemails visible to user (for badge).
     */
    public function getUnhandledCountForUser(User $user): int
    {
        return CallLog::query()
            ->missed()
            ->visibleTo($user)
            ->unhandled()
            ->count();
    }

    /**
     * Get paginated call logs visible to the user with optional filters.
     */
    public function getVisibleCallLogs(User $user, string $filter = 'all', string $search = '', int $perPage = 25): LengthAwarePaginator
    {
        $query = CallLog::query()
            ->with(['customer', 'callerUser', 'answeredByUser', 'voicemailForUser', 'userInteractions'])
            ->visibleTo($user)
            ->latest('started_at');

        if ($filter === 'missed') {
            $query->where('status', 'no-answer');
        } elseif ($filter === 'voicemail') {
            $query->voicemails();
        } elseif ($filter === 'unhandled') {
            $query->missed()->unhandled();
        } elseif ($filter === 'inbound') {
            $query->inbound();
        } elseif ($filter === 'outbound') {
            $query->outbound();
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('from_number', 'like', "%{$search}%")
                    ->orWhere('to_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Mark a call log as listened by a user.
     */
    public function markListened(CallLog $callLog, User $user): void
    {
        $callLog->markListenedBy($user);
    }

    /**
     * Mark a call log as handled by a user with an action.
     */
    public function markHandled(CallLog $callLog, User $user, string $action): void
    {
        $callLog->markHandledBy($user, $action);
    }

    /**
     * Reassign a voicemail to a different user.
     */
    public function assignTo(CallLog $callLog, User $user): void
    {
        $callLog->update(['voicemail_for_user_id' => $user->id]);
    }
}
