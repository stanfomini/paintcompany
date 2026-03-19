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
        // Internal users manage themselves; crew joins via invite only
        if (in_array($user->type, ['internal', 'crew'])) {
            return;
        }

        try {
            // Notify all internal users whose role has the notify.new_account_created permission
            $recipients = User::query()
                ->where('type', 'internal')
                ->permission('notify.new_account_created')
                ->get();
        } catch (\Throwable) {
            // Permission may not exist yet (e.g., during seeding)
            $recipients = collect();
        }

        // Fallback: if no users matched, notify user ID 1
        if ($recipients->isEmpty()) {
            $admin = User::find(1);
            if ($admin && $admin->isInternal()) {
                $recipients = collect([$admin]);
            }
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new NewAccountCreated($user));
        }
    }
}
