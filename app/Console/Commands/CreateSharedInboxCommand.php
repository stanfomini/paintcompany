<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Console\Command;

class CreateSharedInboxCommand extends Command
{
    protected $signature = 'email:create-shared-inbox
        {address=hello : Local part of the email address}
        {--display-name=Painting Made Easy Denver : Display name for the account}';

    protected $description = 'Create a shared email inbox and add all admin/owner users as members';

    public function handle(): int
    {
        $address = $this->argument('address');
        $displayName = $this->option('display-name');

        // Check if already exists
        $existing = EmailAccount::where('address', $address)->first();
        if ($existing) {
            $this->warn("Email account '{$address}@".config('email.domain')."' already exists.");

            if (! $existing->isShared()) {
                $this->error('That account is a personal account. Cannot convert to shared via this command.');

                return Command::FAILURE;
            }

            $this->info('Existing shared account found. Updating members...');
            $account = $existing;
        } else {
            $account = EmailAccount::create([
                'type' => EmailAccount::TYPE_SHARED,
                'user_id' => null,
                'address' => $address,
                'display_name' => $displayName,
                'is_active' => true,
            ]);
            $this->info("Created shared account: {$address}@".config('email.domain'));
        }

        // Add all admin/owner users as members
        $admins = User::where('type', 'internal')
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', ['owner', 'admin']);
            })
            ->get();

        $added = 0;
        foreach ($admins as $admin) {
            if (! $account->members()->where('users.id', $admin->id)->exists()) {
                $account->members()->attach($admin->id, [
                    'can_send' => true,
                    'receives_notifications' => true,
                ]);
                $added++;
                $this->line("  Added member: {$admin->name}");
            }
        }

        $this->info("Done. {$added} new members added. Total members: {$account->members()->count()}");

        return Command::SUCCESS;
    }
}
