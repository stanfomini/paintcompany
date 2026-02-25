<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoChannel;

class VideoChannelPolicy
{
    /**
     * Determine whether the user can view the channel.
     */
    public function view(User $user, VideoChannel $channel): bool
    {
        return $channel->user_id === $user->id || $user->can('media.manage');
    }

    /**
     * Determine whether the user can create channels.
     */
    public function create(User $user): bool
    {
        return $user->can('media.manage_channels');
    }

    /**
     * Determine whether the user can update the channel.
     */
    public function update(User $user, VideoChannel $channel): bool
    {
        return $channel->user_id === $user->id || $user->can('media.manage');
    }

    /**
     * Determine whether the user can delete the channel.
     */
    public function delete(User $user, VideoChannel $channel): bool
    {
        return $channel->user_id === $user->id || $user->can('media.manage');
    }
}
