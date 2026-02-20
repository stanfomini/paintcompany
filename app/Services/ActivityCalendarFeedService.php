<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Job;
use App\Models\LifeCommitment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

class ActivityCalendarFeedService
{
    /**
     * Generate an iCal feed for a user's activities, jobs, and commitments.
     */
    public function generateFeed(User $user): string
    {
        $appName = config('app.name');

        $calendar = Calendar::create($appName.' - Calendar')
            ->description('Activities, jobs, and commitments from '.$appName)
            ->refreshInterval(60)
            ->productIdentifier('-//'.$appName.'//Calendar//EN');

        $this->addActivityEvents($calendar, $user);
        $this->addJobEvents($calendar, $user);
        $this->addLifeCommitmentEvents($calendar);

        return $calendar->get();
    }

    /**
     * Add activity-based events (tasks, meetings, calls).
     */
    protected function addActivityEvents(Calendar $calendar, User $user): void
    {
        $activities = Activity::query()
            ->forUserOrAssignedTo($user)
            ->whereNotNull('due_at')
            ->where(function ($q) {
                $q->where('is_completed', false)
                    ->orWhere('completed_at', '>=', now()->subWeek());
            })
            ->where('due_at', '>=', now()->subMonths(1))
            ->with([
                'subject' => fn ($m) => $m->morphWith([Deal::class => ['customer', 'property']]),
                'videoCall',
            ])
            ->get();

        foreach ($activities as $activity) {
            $event = $this->buildActivityEvent($activity);
            if ($event) {
                $calendar->event($event);
            }
        }
    }

    /**
     * Add job scheduled date events.
     */
    protected function addJobEvents(Calendar $calendar, User $user): void
    {
        $jobs = Job::query()
            ->whereNotNull('scheduled_date')
            ->where('scheduled_date', '>=', now()->subMonths(1)->toDateString())
            ->whereNotIn('stage', ['completed'])
            ->whereHas('deal', function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhere('created_by', $user->id);
            })
            ->with(['deal.customer', 'deal.property'])
            ->get();

        foreach ($jobs as $job) {
            $event = $this->buildJobEvent($job);
            if ($event) {
                $calendar->event($event);
            }
        }
    }

    /**
     * Add recurring life commitment events.
     */
    protected function addLifeCommitmentEvents(Calendar $calendar): void
    {
        $commitments = LifeCommitment::query()->active()->get();

        $from = now()->subWeek();
        $to = now()->addMonths(3);
        $period = CarbonPeriod::create($from->startOfDay(), $to->endOfDay());

        foreach ($commitments as $commitment) {
            $time = $commitment->recurrence_time ?? '09:00';

            // Handle one-time events
            if ($commitment->action_type === 'one_time') {
                if ($commitment->start_date && $commitment->start_date->between($from, $to)) {
                    $timeParts = explode(':', $time);
                    $start = $commitment->start_date->copy()->setTime((int) ($timeParts[0] ?? 9), (int) ($timeParts[1] ?? 0));

                    $endTimeStr = $commitment->recurrence_end_time;
                    if ($endTimeStr && preg_match('/^(\d{1,2}):(\d{2})$/', $endTimeStr, $endParts)) {
                        $end = $commitment->start_date->copy()->setTime((int) $endParts[1], (int) $endParts[2]);
                    } else {
                        $end = $start->copy()->addMinutes(30);
                    }

                    $calendar->event(
                        Event::create($commitment->commitment)
                            ->uniqueIdentifier("commitment-{$commitment->id}@apexnegotiations.com")
                            ->startsAt($start)
                            ->endsAt($end)
                            ->description("Area: {$commitment->area}\nType: One-Time")
                    );
                }

                continue;
            }

            $recurrenceDays = $commitment->recurrence_days ?? [];
            $dayOfMonth = $commitment->recurrence_day_of_month;

            if (empty($recurrenceDays) && ! $dayOfMonth) {
                continue;
            }

            foreach ($period as $date) {
                $matches = false;

                if (! empty($recurrenceDays) && in_array($date->dayOfWeek, $recurrenceDays)) {
                    $matches = true;
                }

                if ($dayOfMonth && $date->day === $dayOfMonth) {
                    $matches = true;
                }

                if (! $matches) {
                    continue;
                }

                $timeParts = explode(':', $time);
                $start = $date->copy()->setTime((int) ($timeParts[0] ?? 9), (int) ($timeParts[1] ?? 0));

                $endTimeStr = $commitment->recurrence_end_time;
                if ($endTimeStr && preg_match('/^(\d{1,2}):(\d{2})$/', $endTimeStr, $endParts)) {
                    $end = $date->copy()->setTime((int) $endParts[1], (int) $endParts[2]);
                } else {
                    $end = $start->copy()->addMinutes(30);
                }

                $calendar->event(
                    Event::create($commitment->commitment)
                        ->uniqueIdentifier("commitment-{$commitment->id}-{$date->format('Y-m-d')}@apexnegotiations.com")
                        ->startsAt($start)
                        ->endsAt($end)
                        ->description("Area: {$commitment->area}\nType: {$commitment->action_type}")
                );
            }
        }
    }

    protected function buildActivityEvent(Activity $activity): ?Event
    {
        $start = Carbon::parse($activity->due_at);
        $end = $activity->ends_at ? Carbon::parse($activity->ends_at) : $start->copy()->addHour();

        $event = Event::create($activity->title)
            ->uniqueIdentifier("activity-{$activity->id}@apexnegotiations.com")
            ->startsAt($start)
            ->endsAt($end)
            ->description($this->buildActivityDescription($activity));

        $url = $activity->getSubjectUrl();
        if ($url) {
            $event->url($url);
        }

        // Virtual meetings: set the video call join URL as location
        if ($activity->isVirtualMeeting() && $activity->videoCall) {
            $joinUrl = route('video-call.test', $activity->videoCall->room_id);
            $event->address("Virtual Meeting: {$joinUrl}");
        }

        // In-person meetings: set the property address as location
        if ($activity->isMeeting() && $activity->meeting_type === 'in_person') {
            $address = $this->getActivityAddress($activity);
            if ($address) {
                $event->address($address);
            }
        }

        return $event;
    }

    protected function buildJobEvent(Job $job): ?Event
    {
        $start = Carbon::parse($job->scheduled_date)->setTime(8, 0);
        $customerName = $job->deal?->customer?->full_name ?? 'Customer';
        $jobTitle = $job->title ?? "Job #{$job->job_number}";

        $description = "Customer: {$customerName}\nStage: {$job->stage}";
        if ($job->company_date_confirmed && $job->customer_date_confirmed) {
            $description .= "\nDate: Confirmed";
        }
        $description .= "\nView Job: ".route('jobs.show', $job);

        $event = Event::create("{$jobTitle} - {$customerName}")
            ->uniqueIdentifier("job-{$job->id}@apexnegotiations.com")
            ->startsAt($start)
            ->endsAt($start->copy()->addHours(8))
            ->description($description)
            ->url(route('jobs.show', $job));

        $address = $job->deal?->property?->full_address;
        if ($address) {
            $event->address($address);
        }

        return $event;
    }

    protected function buildActivityDescription(Activity $activity): string
    {
        $parts = [];
        $parts[] = 'Type: '.Activity::TYPES[$activity->type];

        if ($activity->isMeeting()) {
            $parts[] = 'Meeting Type: '.($activity->meeting_type === 'virtual' ? 'Virtual' : 'In Person');
            $parts[] = 'Status: '.ucfirst($activity->meeting_status ?? 'unknown');
        }

        if ($activity->description) {
            $parts[] = $activity->description;
        }
        if ($label = $activity->getSubjectLabel()) {
            $parts[] = "Customer: {$label}";
        }

        if ($activity->isVirtualMeeting() && $activity->videoCall) {
            $joinUrl = route('video-call.test', $activity->videoCall->room_id);
            $parts[] = "Join Video Call: {$joinUrl}";
        }

        $url = $activity->getSubjectUrl();
        if ($url) {
            $parts[] = "View: {$url}";
        }

        return implode("\n", $parts);
    }

    /**
     * Get the address for an activity from its subject's property.
     */
    protected function getActivityAddress(Activity $activity): ?string
    {
        if ($activity->subject instanceof Deal && $activity->subject->property) {
            return $activity->subject->property->full_address;
        }

        if ($activity->subject instanceof Job && $activity->subject->deal?->property) {
            return $activity->subject->deal->property->full_address;
        }

        return null;
    }
}
