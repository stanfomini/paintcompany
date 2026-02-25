<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoRecording;

class VideoRecordingPolicy
{
    /**
     * Determine whether the user can view the recording.
     */
    public function view(User $user, VideoRecording $recording): bool
    {
        return $recording->user_id === $user->id || $user->can('media.manage');
    }

    /**
     * Determine whether the user can update the recording.
     */
    public function update(User $user, VideoRecording $recording): bool
    {
        return $recording->user_id === $user->id || $user->can('media.manage');
    }

    /**
     * Determine whether the user can delete the recording.
     */
    public function delete(User $user, VideoRecording $recording): bool
    {
        return $recording->user_id === $user->id || $user->can('media.manage');
    }
}
