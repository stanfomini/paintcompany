<?php

namespace App\Observers;

use App\Jobs\SendWelcomeEmailJob;
use App\Models\Customer;
use App\Models\User;

class CustomerObserver
{
    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        $this->dispatchWelcomeEmailIfNeeded($customer);
    }

    /**
     * Handle the Customer "updated" event.
     */
    public function updated(Customer $customer): void
    {
        if ($customer->wasChanged('assigned_to') && $customer->assigned_to) {
            $this->dispatchWelcomeEmailIfNeeded($customer);
        }
    }

    private function dispatchWelcomeEmailIfNeeded(Customer $customer): void
    {
        if (! $customer->assigned_to || ! $customer->email) {
            return;
        }

        $assignee = User::find($customer->assigned_to);

        if (! $assignee || ! $assignee->isInternal() || ! $assignee->emailAccount) {
            return;
        }

        SendWelcomeEmailJob::dispatch($customer, $assignee);
    }
}
