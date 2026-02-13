<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Deal;
use App\Notifications\MeetingReminder;
use App\Services\MeetingService;
use Illuminate\Console\Command;

class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders';

    protected $description = 'Send reminder emails for meetings happening in the next hour';

    public function handle(MeetingService $meetingService): int
    {
        $meetings = Activity::query()
            ->needingReminder()
            ->with(['subject' => fn ($m) => $m->morphWith([Deal::class => ['customer', 'agent']])])
            ->get();

        $sent = 0;

        foreach ($meetings as $meeting) {
            $deal = $meeting->subject;

            if (! $deal instanceof Deal) {
                continue;
            }

            $parties = $meetingService->getDealParties($deal);

            foreach ($parties as $user) {
                if ($user) {
                    $user->notify(new MeetingReminder($meeting));
                    $sent++;
                }
            }

            $meeting->update(['reminder_sent_at' => now()]);
        }

        $this->info("Sent {$sent} reminder(s) for {$meetings->count()} meeting(s).");

        return self::SUCCESS;
    }
}
