<?php

namespace App\Listeners;

use App\Models\Customer;
use App\Notifications\CustomerPortalLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;

class NotifyOnCustomerLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($user->type !== 'customer') {
            return;
        }

        $cacheKey = "customer_login_notify:{$user->id}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $customerRecord = Customer::where('user_id', $user->id)->first();
        $assignee = $customerRecord?->assignedTo;

        if (! $assignee) {
            return;
        }

        $assignee->notify(new CustomerPortalLogin($user));
        Cache::put($cacheKey, true, now()->addHours(8));
    }
}
