<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Models\VideoCall;
use App\Notifications\MeetingCancelled;
use App\Notifications\MeetingConfirmed;
use App\Notifications\MeetingProposed;
use Carbon\Carbon;
use Illuminate\Support\Str;

class MeetingService
{
    /**
     * Propose a new meeting for a deal.
     */
    public function proposeMeeting(
        Deal $deal,
        User $proposer,
        string $title,
        Carbon $dateTime,
        string $meetingType,
        ?int $teamMemberId = null,
        ?string $description = null,
        ?int $estimateId = null,
    ): Activity {
        // Handle team assignment
        if ($teamMemberId && ! $deal->assigned_to) {
            $deal->update(['assigned_to' => $teamMemberId]);
        }

        $assignedTo = $deal->assigned_to ?? $teamMemberId;

        // Create the activity
        $activity = Activity::create([
            'user_id' => $proposer->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'meeting',
            'title' => $title,
            'description' => $description,
            'due_at' => $dateTime,
            'meeting_type' => $meetingType,
            'meeting_status' => 'proposed',
            'proposed_by' => $proposer->id,
            'assigned_to' => $assignedTo,
            'estimate_id' => $estimateId,
        ]);

        // Create VideoCall for virtual meetings
        if ($meetingType === 'virtual') {
            $videoCall = VideoCall::create([
                'callable_type' => Deal::class,
                'callable_id' => $deal->id,
                'initiated_by' => $proposer->id,
                'room_id' => 'meeting-'.Str::uuid(),
                'status' => 'pending',
            ]);

            $activity->update(['video_call_id' => $videoCall->id]);
        }

        // Auto-confirm for the proposer
        $this->autoConfirmForProposer($activity, $proposer, $deal);

        // Check if already fully confirmed (single-party deal)
        if ($activity->fresh()->isFullyConfirmed($deal)) {
            $activity->update(['meeting_status' => 'confirmed']);
            $activity->load('videoCall');
            $this->notifyAllParties($deal, $activity, MeetingConfirmed::class);
        } else {
            // Notify parties that need to confirm
            $this->notifyPartiesNeedingConfirmation($deal, $activity, $proposer);
        }

        return $activity->fresh();
    }

    /**
     * Confirm a meeting for a user.
     */
    public function confirmMeeting(Activity $activity, User $user): Activity
    {
        $deal = $activity->subject;

        if (! $deal instanceof Deal) {
            return $activity;
        }

        $party = $this->getUserParty($user, $deal);

        $confirmField = match ($party) {
            'customer' => 'customer_confirmed_at',
            'agent' => 'agent_confirmed_at',
            'team' => 'team_confirmed_at',
            default => null,
        };

        if (! $confirmField) {
            return $activity;
        }

        $activity->update([$confirmField => now()]);
        $activity->refresh();

        // Check if fully confirmed
        if ($activity->isFullyConfirmed($deal)) {
            $activity->update(['meeting_status' => 'confirmed']);
            $activity->load('videoCall');
            $this->notifyAllParties($deal, $activity, MeetingConfirmed::class);
        }

        return $activity;
    }

    /**
     * Decline a meeting.
     */
    public function declineMeeting(Activity $activity, User $user, ?string $reason = null): Activity
    {
        $deal = $activity->subject;

        $activity->update([
            'meeting_status' => 'declined',
            'description' => $reason
                ? $activity->description."\n\nDeclined by {$user->name}: {$reason}"
                : $activity->description,
        ]);

        if ($deal instanceof Deal) {
            $this->notifyAllParties($deal, $activity, MeetingCancelled::class, $user);
        }

        return $activity->fresh();
    }

    /**
     * Cancel a meeting.
     */
    public function cancelMeeting(Activity $activity, User $user): Activity
    {
        $deal = $activity->subject;

        $activity->update(['meeting_status' => 'cancelled']);

        if ($deal instanceof Deal) {
            $this->notifyAllParties($deal, $activity, MeetingCancelled::class, $user);
        }

        return $activity->fresh();
    }

    /**
     * Reschedule a meeting to a new date/time.
     */
    public function rescheduleMeeting(Activity $activity, Carbon $newDateTime, User $user): Activity
    {
        $deal = $activity->subject;

        $activity->update([
            'due_at' => $newDateTime,
            'meeting_status' => 'proposed',
            'customer_confirmed_at' => null,
            'agent_confirmed_at' => null,
            'team_confirmed_at' => null,
            'reminder_sent_at' => null,
        ]);

        // Auto-confirm for the rescheduler
        if ($deal instanceof Deal) {
            $this->autoConfirmForProposer($activity, $user, $deal);

            if ($activity->fresh()->isFullyConfirmed($deal)) {
                $activity->update(['meeting_status' => 'confirmed']);
                $activity->load('videoCall');
                $this->notifyAllParties($deal, $activity, MeetingConfirmed::class);
            } else {
                $this->notifyPartiesNeedingConfirmation($deal, $activity, $user);
            }
        }

        return $activity->fresh();
    }

    /**
     * Auto-confirm for the proposer based on their user type.
     */
    private function autoConfirmForProposer(Activity $activity, User $proposer, Deal $deal): void
    {
        $party = $this->getUserParty($proposer, $deal);

        $confirmField = match ($party) {
            'customer' => 'customer_confirmed_at',
            'agent' => 'agent_confirmed_at',
            'team' => 'team_confirmed_at',
            default => null,
        };

        if ($confirmField) {
            $activity->update([$confirmField => now()]);
        }
    }

    /**
     * Determine which party a user belongs to for a deal.
     */
    public function getUserParty(User $user, Deal $deal): string
    {
        if ($user->isCustomer() && $deal->customer?->user_id === $user->id) {
            return 'customer';
        }

        if ($user->isAgent() && $deal->agent_id === $user->id) {
            return 'agent';
        }

        if ($user->isInternal()) {
            return 'team';
        }

        return 'unknown';
    }

    /**
     * Get the users who are parties to this deal.
     *
     * @return array<string, User|null>
     */
    public function getDealParties(Deal $deal): array
    {
        $parties = [];

        // Team member
        if ($deal->assigned_to) {
            $parties['team'] = User::find($deal->assigned_to);
        }

        // Customer
        if ($deal->customer?->user_id) {
            $parties['customer'] = User::find($deal->customer->user_id);
        }

        // Agent
        if ($deal->agent_id) {
            $parties['agent'] = User::find($deal->agent_id);
        }

        return array_filter($parties);
    }

    /**
     * Notify parties that need to confirm a proposed meeting.
     */
    private function notifyPartiesNeedingConfirmation(Deal $deal, Activity $activity, User $proposer): void
    {
        $parties = $this->getDealParties($deal);

        foreach ($parties as $party => $user) {
            if ($user && $user->id !== $proposer->id && $activity->needsConfirmationFrom($party)) {
                $user->notify(new MeetingProposed($activity, $proposer));
            }
        }
    }

    /**
     * Notify all parties about a meeting event.
     *
     * @param  class-string  $notificationClass
     */
    private function notifyAllParties(Deal $deal, Activity $activity, string $notificationClass, ?User $actionBy = null): void
    {
        $parties = $this->getDealParties($deal);

        foreach ($parties as $user) {
            if ($user) {
                $user->notify(new $notificationClass($activity, $actionBy));
            }
        }
    }
}
