<?php

namespace App\Observers;

use App\Models\User;
use App\Notifications\NewAccountCreated;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        // Don't notify for internal users (admins create these themselves)
        if ($user->type === 'internal') {
            return;
        }

        // Notify the primary admin (user ID 1)
        $admin = User::find(1);

        if ($admin) {
            $admin->notify(new NewAccountCreated($user));
        }
    }
}
