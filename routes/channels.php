<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * Authorize access to a chat thread channel.
 * User must be an active participant in the thread.
 */
Broadcast::channel('chat.thread.{threadId}', function (User $user, int $threadId) {
    $thread = ChatThread::find($threadId);

    if (! $thread) {
        return false;
    }

    return $thread->hasParticipant($user);
}, ['guards' => ['web', 'agent', 'customer', 'crew']]);

/**
 * Authorize access to a user's personal notification channel.
 * Only the user themselves can access their channel.
 */
Broadcast::channel('chat.user.{userId}', function (User $user, int $userId) {
    return $user->id === $userId;
}, ['guards' => ['web', 'agent', 'customer', 'crew']]);

/**
 * Authorize access to a job channel.
 * Internal users, assigned crew, and the job's customer can listen.
 */
Broadcast::channel('job.{jobId}', function (User $user, int $jobId) {
    if ($user->isInternal()) {
        return true;
    }

    $job = Job::find($jobId);
    if (! $job) {
        return false;
    }

    if ($job->crews()->where('crew_user_id', $user->id)->exists()) {
        return true;
    }

    if ($job->deal?->customer?->user_id === $user->id) {
        return true;
    }

    return false;
}, ['guards' => ['web', 'customer', 'crew']]);

/**
 * Authorize access to a customer job channel.
 * Only the customer who owns the job can listen.
 */
Broadcast::channel('customer.job.{jobId}', function (User $user, int $jobId) {
    return $user->isCustomer() && Job::where('id', $jobId)
        ->whereHas('deal', fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('user_id', $user->id)))
        ->exists();
}, ['guards' => ['customer']]);

/**
 * Authorize access to a crew job channel.
 * Only crews dispatched to this job can listen.
 */
Broadcast::channel('crew.job.{jobId}', function (User $user, int $jobId) {
    return Job::where('id', $jobId)
        ->whereHas('crews', fn ($q) => $q->where('crew_user_id', $user->id))
        ->exists();
}, ['guards' => ['crew']]);

/**
 * Authorize access to a crew member's personal channel.
 * Only the crew member themselves can listen.
 */
Broadcast::channel('crew.user.{userId}', function (User $user, int $userId) {
    return $user->id === $userId;
}, ['guards' => ['crew']]);

/**
 * Authorize access to the internal team channel.
 * Only internal users can listen to this channel.
 */
Broadcast::channel('internal-team', function (User $user) {
    return $user->isInternal();
});

/**
 * Authorize access to the internal jobs channel.
 * Only internal users can listen (for job kanban board updates).
 */
Broadcast::channel('internal-jobs', function (User $user) {
    return $user->isInternal();
});

/**
 * Authorize access to a video call room.
 * User must be internal, assigned crew, or the customer for the associated job.
 */
Broadcast::channel('video-call.{roomId}', function (User $user, string $roomId) {
    $videoCall = \App\Models\VideoCall::where('room_id', $roomId)->first();
    if (! $videoCall) {
        return false;
    }

    // Use the user's ID as the presence member ID. This is deterministic —
    // both client and server know it without any custom headers or auth overrides.
    // Same user on multiple tabs shares one presence slot (correct for video calls).
    $userData = [
        'id' => $user->id,
        'name' => $user->name,
        'user_id' => $user->id,
    ];

    // Internal users can always join
    if ($user->isInternal()) {
        return $userData;
    }

    // Job-based calls (legacy job_id column)
    $job = $videoCall->job;
    if ($job) {
        if ($job->crews()->where('crew_user_id', $user->id)->exists()) {
            return $userData;
        }

        if ($job->deal?->customer?->user_id === $user->id) {
            return $userData;
        }
    }

    // Polymorphic calls (Deal-based, etc.)
    $callable = $videoCall->callable;
    if ($callable instanceof \App\Models\Deal) {
        if ($callable->customer?->user_id === $user->id) {
            return $userData;
        }

        if ($callable->agent_id === $user->id) {
            return $userData;
        }
    }

    return false;
}, ['guards' => ['web', 'agent', 'customer', 'crew']]);

/**
 * Authorize access to a streaming studio session.
 * Only the session owner and internal users can join.
 */
Broadcast::channel('studio.{sessionId}', function (User $user, int $sessionId) {
    $session = \App\Models\StreamSession::find($sessionId);
    if (! $session) {
        return false;
    }

    if ($user->isInternal() || $session->user_id === $user->id) {
        return ['id' => $user->id, 'name' => $user->name];
    }

    return false;
}, ['guards' => ['web', 'agent']]);

/**
 * Authorize access to a deal channel.
 * Internal users, agents on the deal, or the deal's customer can listen.
 */
Broadcast::channel('deal.{dealId}', function (User $user, int $dealId) {
    if ($user->isInternal()) {
        return true;
    }

    $deal = Deal::find($dealId);
    if (! $deal) {
        return false;
    }

    if ($deal->agent_id === $user->id) {
        return true;
    }

    if ($deal->customer?->user_id === $user->id) {
        return true;
    }

    return false;
}, ['guards' => ['web', 'agent', 'customer']]);

/**
 * Authorize access to an estimate channel.
 * Internal users, agents on the deal, or the deal's customer can listen.
 */
/**
 * Authorize access to a user's notification channel.
 * This is the default channel Laravel uses for database notifications with broadcast.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

/**
 * Authorize access to an estimate channel.
 * Internal users, agents on the deal, or the deal's customer can listen.
 */
Broadcast::channel('estimate.{estimateId}', function (User $user, int $estimateId) {
    if ($user->isInternal()) {
        return true;
    }

    $estimate = Estimate::with('deal')->find($estimateId);
    if (! $estimate) {
        return false;
    }

    $deal = $estimate->deal;

    if ($deal?->agent_id === $user->id) {
        return true;
    }

    if ($deal?->customer?->user_id === $user->id) {
        return true;
    }

    return false;
}, ['guards' => ['web', 'agent', 'customer']]);

/**
 * Personal dispatch channel - user receives territory offers.
 * Only the user themselves can listen (internal users only).
 */
Broadcast::channel('territory-dispatch.user.{userId}', function (User $user, int $userId) {
    return $user->id === $userId && $user->isInternal();
}, ['guards' => ['web']]);

/**
 * Offer-specific channel - all eligible users listen for claim/expire.
 */
Broadcast::channel('territory-dispatch.offer.{offerId}', function (User $user, int $offerId) {
    return $user->isInternal() && \App\Models\TerritoryOffer::where('id', $offerId)
        ->whereHas('eligibleUsers', fn ($q) => $q->where('user_id', $user->id))
        ->exists();
}, ['guards' => ['web']]);

/**
 * Authorize access to a user's email channel.
 * Only internal users with email.access permission can listen.
 */
Broadcast::channel('email.user.{userId}', function (User $user, int $userId) {
    return $user->id === $userId && $user->isInternal();
}, ['guards' => ['web']]);

/**
 * Authorize access to a color visualization channel.
 * Any authenticated user can listen to their own visualization updates.
 */
Broadcast::channel('visualization.{uuid}', function (User $user, string $uuid) {
    return \App\Models\ColorVisualization::where('uuid', $uuid)
        ->where('user_id', $user->id)
        ->exists();
}, ['guards' => ['web', 'customer', 'agent']]);

/**
 * Authorize access to a user's call channel.
 * Only the user themselves can listen, and they must be an internal user.
 */
Broadcast::channel('call.user.{userId}', function (User $user, int $userId) {
    return $user->id === $userId && $user->isInternal();
}, ['guards' => ['web']]);

/**
 * Authorize access to a live event presence channel.
 * Authenticated users get their identity for the viewer list.
 * Guest viewers require separate handling (custom auth endpoint).
 */
Broadcast::channel('live-event.{eventId}', function (User $user, int $eventId) {
    if ($user) {
        return ['id' => $user->id, 'name' => $user->name];
    }

    return false;
}, ['guards' => ['web', 'agent', 'customer', 'crew']]);

/**
 * Authorize access to the live event host channel.
 * Only the event's host user can listen on this private channel.
 */
Broadcast::channel('live-event-host.{eventId}', function (User $user, int $eventId) {
    $event = \App\Models\LiveEvent::find($eventId);

    return $event && $event->host_user_id === $user->id;
}, ['guards' => ['web']]);
