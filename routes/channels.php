<?php

use App\Models\ChatThread;
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
 * Authorize access to the internal team channel.
 * Only internal users can listen to this channel.
 */
Broadcast::channel('internal-team', function (User $user) {
    return $user->isInternal();
});
