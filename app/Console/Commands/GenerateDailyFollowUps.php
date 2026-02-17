<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateDailyFollowUps extends Command
{
    protected $signature = 'followups:generate-daily';

    protected $description = 'Generate daily follow-up tasks for all active non-internal users';

    public function handle(): int
    {
        $created = 0;

        User::query()
            ->whereIn('type', ['customer', 'agent', 'crew'])
            ->where('follow_up_status', '!=', 'lost')
            ->where('is_active', true)
            ->chunkById(100, function ($users) use (&$created) {
                foreach ($users as $user) {
                    $hasIncomplete = Activity::query()
                        ->where('automation_key', 'daily_followup')
                        ->where('subject_type', User::class)
                        ->where('subject_id', $user->id)
                        ->where('is_completed', false)
                        ->where('due_at', '>=', today())
                        ->exists();

                    if ($hasIncomplete) {
                        continue;
                    }

                    $assignedTo = null;
                    if ($user->isCustomer()) {
                        $customer = Customer::where('user_id', $user->id)->first();
                        $assignedTo = $customer?->assigned_to;
                    }

                    Activity::create([
                        'user_id' => $assignedTo,
                        'assigned_to' => $assignedTo,
                        'subject_type' => User::class,
                        'subject_id' => $user->id,
                        'type' => 'task',
                        'automation_key' => 'daily_followup',
                        'title' => "Follow up with {$user->name}",
                        'due_at' => today()->setTime(9, 0),
                        'priority' => 'normal',
                        'is_completed' => false,
                    ]);

                    $created++;
                }
            });

        $this->info("Daily follow-ups generated. {$created} new task(s) created.");

        return self::SUCCESS;
    }
}
