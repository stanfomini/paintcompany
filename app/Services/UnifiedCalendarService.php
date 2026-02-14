<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Job;
use App\Models\LifeCommitment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class UnifiedCalendarService
{
    /**
     * Get all calendar events for a user within a date range.
     *
     * Merges: Activities (tasks, meetings, calls), Job scheduled dates,
     * and LifeCommitment recurring events into a unified collection.
     *
     * @return Collection<int, array{
     *     id: string,
     *     title: string,
     *     start: Carbon,
     *     end: Carbon,
     *     type: string,
     *     source: string,
     *     color: string,
     *     subject_type: ?string,
     *     subject_id: ?int,
     *     url: ?string,
     *     meta: array<string, mixed>
     * }>
     */
    public function getEvents(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $events = collect();

        $events = $events->merge($this->getActivityEvents($user, $from, $to));
        $events = $events->merge($this->getJobEvents($user, $from, $to));
        $events = $events->merge($this->getLifeCommitmentEvents($from, $to));

        return $events->sortBy('start')->values();
    }

    /**
     * Get events for a specific date.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getEventsForDate(User $user, CarbonInterface $date): Collection
    {
        return $this->getEvents($user, $date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    /**
     * Get events for the current week.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getEventsForWeek(User $user, ?CarbonInterface $weekStart = null): Collection
    {
        $start = ($weekStart ?? now())->copy()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        return $this->getEvents($user, $start, $end);
    }

    /**
     * Get events for the current month.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getEventsForMonth(User $user, ?CarbonInterface $monthStart = null): Collection
    {
        $start = ($monthStart ?? now())->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->getEvents($user, $start, $end);
    }

    /**
     * Get activity-based calendar events (tasks, meetings, calls, emails).
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function getActivityEvents(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $activities = Activity::query()
            ->forUserOrAssignedTo($user)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, $to])
            ->where(function ($q) {
                $q->where('is_completed', false)
                    ->orWhere('completed_at', '>=', now()->subWeek());
            })
            ->with(['subject' => fn ($m) => $m->morphWith([Deal::class => ['customer']])])
            ->get();

        return $activities->map(function (Activity $activity) {
            $start = Carbon::parse($activity->due_at);
            $isMeeting = $activity->isMeeting();

            return [
                'id' => "activity-{$activity->id}",
                'title' => $activity->title,
                'start' => $start,
                'end' => $start->copy()->addHour(),
                'type' => $activity->type,
                'source' => 'activity',
                'color' => $this->getActivityColor($activity),
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'url' => $activity->getSubjectUrl(),
                'meta' => [
                    'activity_id' => $activity->id,
                    'is_completed' => $activity->is_completed,
                    'is_overdue' => $activity->isOverdue(),
                    'priority' => $activity->priority ?? 'normal',
                    'meeting_type' => $isMeeting ? $activity->meeting_type : null,
                    'meeting_status' => $isMeeting ? $activity->meeting_status : null,
                ],
            ];
        });
    }

    /**
     * Get job scheduled date events.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function getJobEvents(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $jobs = Job::query()
            ->whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '>=', $from)
            ->whereDate('scheduled_date', '<=', $to)
            ->whereNotIn('stage', ['completed'])
            ->whereHas('deal', function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhere('created_by', $user->id);
            })
            ->with(['deal.customer'])
            ->get();

        return $jobs->map(function (Job $job) {
            $start = Carbon::parse($job->scheduled_date)->setTime(8, 0);
            $customerName = $job->deal?->customer?->full_name ?? 'Customer';
            $jobTitle = $job->title ?? "Job #{$job->job_number}";

            return [
                'id' => "job-{$job->id}",
                'title' => "{$jobTitle} - {$customerName}",
                'start' => $start,
                'end' => $start->copy()->addHours(8),
                'type' => 'job',
                'source' => 'job',
                'color' => '#2563eb',
                'subject_type' => Job::class,
                'subject_id' => $job->id,
                'url' => null,
                'meta' => [
                    'job_id' => $job->id,
                    'stage' => $job->stage,
                    'date_confirmed' => $job->company_date_confirmed && $job->customer_date_confirmed,
                    'has_crew' => $job->dispatched_at !== null,
                ],
            ];
        });
    }

    /**
     * Expand recurring LifeCommitment events into the date range.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function getLifeCommitmentEvents(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $commitments = LifeCommitment::query()
            ->active()
            ->get();

        $events = collect();

        foreach ($commitments as $commitment) {
            $expanded = $this->expandCommitment($commitment, $from, $to);
            $events = $events->merge($expanded);
        }

        return $events;
    }

    /**
     * Expand a single recurring LifeCommitment into individual date events.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function expandCommitment(LifeCommitment $commitment, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $events = collect();
        $recurrenceDays = $commitment->recurrence_days ?? [];
        $recurrenceTime = $commitment->recurrence_time ?? '09:00';
        $dayOfMonth = $commitment->recurrence_day_of_month;

        if (empty($recurrenceDays) && ! $dayOfMonth) {
            return $events;
        }

        $period = CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->endOfDay());

        foreach ($period as $date) {
            $matches = false;

            // Check weekly recurrence (days of week: 0=Sunday, 1=Monday, etc.)
            if (! empty($recurrenceDays) && in_array($date->dayOfWeek, $recurrenceDays)) {
                $matches = true;
            }

            // Check monthly recurrence (day of month)
            if ($dayOfMonth && $date->day === $dayOfMonth) {
                $matches = true;
            }

            if (! $matches) {
                continue;
            }

            $timeParts = explode(':', $recurrenceTime);
            $start = $date->copy()->setTime((int) ($timeParts[0] ?? 9), (int) ($timeParts[1] ?? 0));

            $events->push([
                'id' => "commitment-{$commitment->id}-{$date->format('Y-m-d')}",
                'title' => $commitment->commitment,
                'start' => $start,
                'end' => $start->copy()->addMinutes(30),
                'type' => 'commitment',
                'source' => 'life_commitment',
                'color' => '#8b5cf6',
                'subject_type' => null,
                'subject_id' => null,
                'url' => null,
                'meta' => [
                    'commitment_id' => $commitment->id,
                    'area' => $commitment->area,
                    'action_type' => $commitment->action_type,
                ],
            ]);
        }

        return $events;
    }

    /**
     * Get the display color for an activity based on its type.
     */
    protected function getActivityColor(Activity $activity): string
    {
        if ($activity->is_completed) {
            return '#9ca3af'; // gray
        }

        if ($activity->isOverdue()) {
            return '#ef4444'; // red
        }

        return match ($activity->type) {
            'meeting' => '#8b5cf6', // purple
            'call' => '#f59e0b', // amber
            'email' => '#3b82f6', // blue
            'task' => '#10b981', // emerald
            default => '#6b7280', // gray
        };
    }
}
