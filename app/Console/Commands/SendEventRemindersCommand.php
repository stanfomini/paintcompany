<?php

namespace App\Console\Commands;

use App\Models\LiveEvent;
use App\Notifications\LiveEventReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendEventRemindersCommand extends Command
{
    protected $signature = 'live-events:send-reminders';

    protected $description = 'Send reminder notifications for live events starting within the next hour';

    public function handle(): int
    {
        $events = LiveEvent::query()
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [now(), now()->addHour()])
            ->whereNull('reminder_sent_at')
            ->get();

        $count = 0;

        foreach ($events as $event) {
            $event->loadMissing('registrations');

            foreach ($event->registrations as $registration) {
                if ($registration->user) {
                    $registration->user->notify(new LiveEventReminder($event));
                } else {
                    Notification::route('mail', $registration->email)
                        ->notify(new LiveEventReminder($event));
                }

                $count++;
            }

            $event->update(['reminder_sent_at' => now()]);
        }

        $this->info("Sent {$count} live event reminders for {$events->count()} event(s).");

        return self::SUCCESS;
    }
}
