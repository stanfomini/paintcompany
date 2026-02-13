<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use Carbon\Carbon;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

class ActivityCalendarFeedService
{
    /**
     * Generate an iCal feed for a user's activities.
     */
    public function generateFeed(User $user): string
    {
        $activities = Activity::query()
            ->forUserOrAssignedTo($user)
            ->whereNotNull('due_at')
            ->where(function ($q) {
                $q->where('is_completed', false)
                    ->orWhere('completed_at', '>=', now()->subWeek());
            })
            ->where('due_at', '>=', now()->subMonths(1))
            ->with(['subject' => fn ($m) => $m->morphWith([Deal::class => ['customer']])])
            ->get();

        $calendar = Calendar::create('Apex Negotiations - Activities')
            ->description('Activities and appointments from your planner')
            ->refreshInterval(60)
            ->productIdentifier('-//Apex Negotiations//Activities//EN');

        foreach ($activities as $activity) {
            $event = $this->buildEvent($activity);
            if ($event) {
                $calendar->event($event);
            }
        }

        return $calendar->get();
    }

    protected function buildEvent(Activity $activity): ?Event
    {
        $start = Carbon::parse($activity->due_at);

        $event = Event::create($activity->title)
            ->uniqueIdentifier("activity-{$activity->id}@apexnegotiations.com")
            ->startsAt($start)
            ->endsAt($start->copy()->addHour())
            ->description($this->buildDescription($activity));

        $url = $activity->getSubjectUrl();
        if ($url) {
            $event->url($url);
        }

        return $event;
    }

    protected function buildDescription(Activity $activity): string
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

        return implode("\n", $parts);
    }
}
