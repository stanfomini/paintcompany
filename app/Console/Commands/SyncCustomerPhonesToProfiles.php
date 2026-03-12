<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

class SyncCustomerPhonesToProfiles extends Command
{
    protected $signature = 'customers:sync-phones';

    protected $description = 'Backfill customer_profiles.phone from customers.phone where profile phone is null';

    public function handle(): int
    {
        $updated = 0;

        Customer::whereNotNull('phone')
            ->whereNotNull('user_id')
            ->whereHas('user.customerProfile', fn ($q) => $q->whereNull('phone'))
            ->with('user.customerProfile')
            ->each(function (Customer $customer) use (&$updated) {
                $customer->user->customerProfile->update(['phone' => $customer->phone]);
                $updated++;
            });

        $this->info("Synced {$updated} customer phone(s) to profiles.");

        return self::SUCCESS;
    }
}
