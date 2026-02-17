<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

class ScheduleCalendarFeedService
{
    /**
     * Generate an iCal feed for a user's work schedule.
     */
    public function generateFeed(User $user): string
    {
        $calendar = Calendar::create('Apex Negotiations - Work Schedule')
            ->description('Employee work schedule from Apex Negotiations')
            ->refreshInterval(60)
            ->productIdentifier('-//Apex Negotiations//Schedule//EN');

        $profile = $user->employeeProfile;

        if (! $profile) {
            return $calendar->get();
        }

        $schedules = Schedule::query()
            ->forEmployee($profile->id)
            ->where('date', '>=', now()->subMonth()->toDateString())
            ->where('date', '<=', now()->addMonths(3)->toDateString())
            ->where('status', '!=', 'cancelled')
            ->with('job')
            ->get();

        foreach ($schedules as $schedule) {
            $calendar->event($this->buildEvent($schedule));
        }

        return $calendar->get();
    }

    protected function buildEvent(Schedule $schedule): Event
    {
        $title = 'Work Shift';
        if ($schedule->job) {
            $title = $schedule->job->title ?? "Job #{$schedule->job->job_number}";
        }

        $start = Carbon::parse($schedule->date->format('Y-m-d').' '.$schedule->start_time);
        $end = Carbon::parse($schedule->date->format('Y-m-d').' '.$schedule->end_time);

        $descriptionParts = [];
        $descriptionParts[] = 'Status: '.ucfirst($schedule->status);

        if ($schedule->break_duration) {
            $descriptionParts[] = "Break: {$schedule->break_duration} min";
        }

        if ($schedule->notes) {
            $descriptionParts[] = "Notes: {$schedule->notes}";
        }

        return Event::create($title)
            ->uniqueIdentifier("schedule-{$schedule->id}@apexnegotiations.com")
            ->startsAt($start)
            ->endsAt($end)
            ->description(implode("\n", $descriptionParts));
    }
}
