<?php

namespace App\Policies;

use App\Models\ChatThread;
use App\Models\User;

class ChatThreadPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ChatThread $chatThread): bool
    {
        if ($chatThread->type === ChatThread::TYPE_DEAL
            && $user->isInternal()
            && $user->roles->isNotEmpty()
            && ! $user->can('chat.view_deal_chats')
        ) {
            return false;
        }

        return $chatThread->hasParticipant($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ChatThread $chatThread): bool
    {
        return $user->isInternal() || $chatThread->hasParticipant($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ChatThread $chatThread): bool
    {
        return $user->isInternal();
    }

    /**
     * Determine whether the user can send a message to the thread.
     */
    public function sendMessage(User $user, ChatThread $chatThread): bool
    {
        return $chatThread->hasParticipant($user);
    }

    /**
     * Determine whether the user can send an internal message to the thread.
     */
    public function sendInternalMessage(User $user, ChatThread $chatThread): bool
    {
        return $user->isInternal() && $chatThread->hasParticipant($user);
    }

    /**
     * Determine whether the user can add a participant to the thread.
     */
    public function addParticipant(User $user, ChatThread $chatThread): bool
    {
        return $user->isInternal();
    }

    /**
     * Determine whether the user can remove a participant from the thread.
     */
    public function removeParticipant(User $user, ChatThread $chatThread): bool
    {
        return $user->isInternal();
    }
}
