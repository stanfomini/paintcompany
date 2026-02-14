<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\SlaMetric;
use App\Models\User;
use Carbon\Carbon;

class SlaMetricService
{
    /**
     * Calculate all daily metrics for a given date.
     */
    public function calculateDaily(Carbon $date): void
    {
        $users = User::where('type', 'internal')
            ->where('is_active', true)
            ->get();

        foreach ($users as $user) {
            $this->calculateSpeedToLead($user, $date);
            $this->calculateFollowUpCompliance($user, $date);
            $this->calculateResponseTime($user, $date);
            $this->calculateEstimateTurnaround($user, $date);
        }
    }

    /**
     * Speed to Lead: Average time from deal creation to first activity (in minutes).
     */
    public function calculateSpeedToLead(User $user, Carbon $date): ?SlaMetric
    {
        $deals = Deal::query()
            ->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhere('created_by', $user->id);
            })
            ->whereDate('created_at', $date)
            ->get();

        if ($deals->isEmpty()) {
            return null;
        }

        $totalMinutes = 0;
        $count = 0;

        foreach ($deals as $deal) {
            $firstActivity = Activity::where('subject_type', Deal::class)
                ->where('subject_id', $deal->id)
                ->where('type', '!=', 'note')
                ->orderBy('created_at')
                ->first();

            if ($firstActivity) {
                $minutes = $deal->created_at->diffInMinutes($firstActivity->created_at);
                $totalMinutes += $minutes;
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return SlaMetric::updateOrCreate(
            [
                'user_id' => $user->id,
                'metric_type' => 'speed_to_lead',
                'period' => 'daily',
                'period_date' => $date->toDateString(),
            ],
            [
                'value' => round($totalMinutes / $count, 2),
                'sample_count' => $count,
            ]
        );
    }

    /**
     * Follow-Up Compliance: Percentage of tasks completed on or before their due date.
     */
    public function calculateFollowUpCompliance(User $user, Carbon $date): ?SlaMetric
    {
        $activities = Activity::query()
            ->forUserOrAssignedTo($user)
            ->whereNotNull('due_at')
            ->whereDate('due_at', $date)
            ->where('type', '!=', 'note')
            ->get();

        if ($activities->isEmpty()) {
            return null;
        }

        $onTime = $activities->filter(function ($activity) {
            return $activity->is_completed
                && $activity->completed_at
                && $activity->completed_at <= $activity->due_at;
        })->count();

        $total = $activities->count();
        $compliance = ($onTime / $total) * 100;

        return SlaMetric::updateOrCreate(
            [
                'user_id' => $user->id,
                'metric_type' => 'follow_up_compliance',
                'period' => 'daily',
                'period_date' => $date->toDateString(),
            ],
            [
                'value' => round($compliance, 2),
                'sample_count' => $total,
            ]
        );
    }

    /**
     * Response Time: Average time from activity creation to completion (in minutes).
     */
    public function calculateResponseTime(User $user, Carbon $date): ?SlaMetric
    {
        $activities = Activity::query()
            ->forUserOrAssignedTo($user)
            ->where('is_completed', true)
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', $date)
            ->whereIn('type', ['task', 'call', 'email'])
            ->get();

        if ($activities->isEmpty()) {
            return null;
        }

        $totalMinutes = 0;
        $count = 0;

        foreach ($activities as $activity) {
            $minutes = $activity->created_at->diffInMinutes($activity->completed_at);
            $totalMinutes += $minutes;
            $count++;
        }

        return SlaMetric::updateOrCreate(
            [
                'user_id' => $user->id,
                'metric_type' => 'response_time',
                'period' => 'daily',
                'period_date' => $date->toDateString(),
            ],
            [
                'value' => round($totalMinutes / $count, 2),
                'sample_count' => $count,
            ]
        );
    }

    /**
     * Estimate Turnaround: Average hours from deal reaching "quote_scheduled" to estimate being sent.
     */
    public function calculateEstimateTurnaround(User $user, Carbon $date): ?SlaMetric
    {
        $estimates = Estimate::query()
            ->whereNotNull('sent_at')
            ->whereDate('sent_at', $date)
            ->whereHas('deal', function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhere('created_by', $user->id);
            })
            ->with('deal')
            ->get();

        if ($estimates->isEmpty()) {
            return null;
        }

        $totalHours = 0;
        $count = 0;

        foreach ($estimates as $estimate) {
            if ($estimate->deal) {
                $hours = $estimate->deal->created_at->diffInHours($estimate->sent_at);
                $totalHours += $hours;
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return SlaMetric::updateOrCreate(
            [
                'user_id' => $user->id,
                'metric_type' => 'estimate_turnaround',
                'period' => 'daily',
                'period_date' => $date->toDateString(),
            ],
            [
                'value' => round($totalHours / $count, 2),
                'sample_count' => $count,
            ]
        );
    }

    /**
     * Get dashboard summary metrics for a user over a period.
     *
     * @return array{
     *     speed_to_lead: ?float,
     *     follow_up_compliance: ?float,
     *     response_time: ?float,
     *     estimate_turnaround: ?float,
     *     overdue_count: int,
     *     due_today_count: int,
     *     completed_today_count: int
     * }
     */
    public function getDashboardMetrics(User $user, int $days = 7): array
    {
        $from = now()->subDays($days)->toDateString();

        $metrics = SlaMetric::query()
            ->forUser($user)
            ->forPeriod('daily')
            ->where('period_date', '>=', $from)
            ->get();

        $averageOf = function (string $type) use ($metrics): ?float {
            $typeMetrics = $metrics->where('metric_type', $type);
            if ($typeMetrics->isEmpty()) {
                return null;
            }

            $totalWeighted = $typeMetrics->sum(fn ($m) => $m->value * $m->sample_count);
            $totalSamples = $typeMetrics->sum('sample_count');

            return $totalSamples > 0 ? round($totalWeighted / $totalSamples, 2) : null;
        };

        return [
            'speed_to_lead' => $averageOf('speed_to_lead'),
            'follow_up_compliance' => $averageOf('follow_up_compliance'),
            'response_time' => $averageOf('response_time'),
            'estimate_turnaround' => $averageOf('estimate_turnaround'),
            'overdue_count' => Activity::forUserOrAssignedTo($user)->overdue()->count(),
            'due_today_count' => Activity::forUserOrAssignedTo($user)->incomplete()->dueToday()->count(),
            'completed_today_count' => Activity::forUserOrAssignedTo($user)
                ->where('is_completed', true)
                ->whereDate('completed_at', today())
                ->count(),
        ];
    }
}
