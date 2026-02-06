<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\ProductionPlanWeek;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProductionPlanService
{
    /**
     * Get or create week record for a specific week.
     */
    public function getWeek(CompanySetting $settings, int $week, int $year): ProductionPlanWeek
    {
        return ProductionPlanWeek::firstOrCreate(
            [
                'company_setting_id' => $settings->id,
                'week_number' => $week,
                'year' => $year,
            ],
            [
                'revenue_plan' => 0,
                'revenue_actual' => 0,
                'jobs_completed_plan' => 0,
                'jobs_completed_actual' => 0,
                'labor_hours_plan' => 0,
                'labor_hours_actual' => 0,
            ]
        );
    }

    /**
     * Get all weeks for a year with data.
     *
     * @return Collection<int, ProductionPlanWeek>
     */
    public function getWeeksForYear(CompanySetting $settings, int $year): Collection
    {
        return ProductionPlanWeek::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->orderBy('week_number')
            ->get();
    }

    /**
     * Calculate actuals from app data for a specific week.
     *
     * @return array{jobs_completed: int, revenue: float, labor_hours: float}
     */
    public function calculateWeeklyActuals(int $week, int $year): array
    {
        $weekStart = Carbon::now()->setISODate($year, $week)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        // Get completed jobs this week
        $completedJobs = Job::where('stage', 'completed')
            ->whereBetween('actual_end_date', [$weekStart, $weekEnd])
            ->with('estimate.areas.surfaces')
            ->get();

        $jobsCompleted = $completedJobs->count();

        // Calculate revenue from completed jobs
        $revenue = 0;
        $laborHours = 0;

        foreach ($completedJobs as $job) {
            if ($job->estimate) {
                $revenue += (float) $job->estimate->subtotal;

                // Sum labor hours from estimate areas
                foreach ($job->estimate->areas as $area) {
                    foreach ($area->surfaces as $surface) {
                        $laborHours += (float) ($surface->labor_hours ?? 0);
                    }
                }
            }
        }

        return [
            'jobs_completed' => $jobsCompleted,
            'revenue' => $revenue,
            'labor_hours' => $laborHours,
        ];
    }

    /**
     * Sync actual values for a specific week from app data.
     */
    public function syncWeekActuals(ProductionPlanWeek $week): ProductionPlanWeek
    {
        $actuals = $this->calculateWeeklyActuals($week->week_number, $week->year);

        $week->update([
            'jobs_completed_actual' => $actuals['jobs_completed'],
            'revenue_actual' => $actuals['revenue'],
            'labor_hours_actual' => $actuals['labor_hours'],
            'synced_at' => now(),
        ]);

        return $week->fresh();
    }

    /**
     * Sync all weeks for a year from app data.
     */
    public function syncYearActuals(CompanySetting $settings, int $year): void
    {
        $currentWeek = Carbon::now()->weekOfYear;
        $currentYear = Carbon::now()->year;

        // Only sync weeks that have passed or are current
        $maxWeek = ($year < $currentYear) ? 52 : $currentWeek;

        for ($week = 1; $week <= $maxWeek; $week++) {
            $weekRecord = $this->getWeek($settings, $week, $year);
            $this->syncWeekActuals($weekRecord);
        }
    }

    /**
     * Calculate YTD production vs goal.
     *
     * @return array{
     *     revenue: array{actual: float, plan: float, percent: float},
     *     jobs: array{actual: int, plan: int, percent: float},
     *     labor_hours: array{actual: float, plan: float, percent: float}
     * }
     */
    public function getYTDProgress(CompanySetting $settings, int $year): array
    {
        $weeks = $this->getWeeksForYear($settings, $year);

        $totals = [
            'revenue_actual' => (float) $weeks->sum('revenue_actual'),
            'revenue_plan' => (float) $weeks->sum('revenue_plan'),
            'jobs_actual' => $weeks->sum('jobs_completed_actual'),
            'jobs_plan' => $weeks->sum('jobs_completed_plan'),
            'hours_actual' => (float) $weeks->sum('labor_hours_actual'),
            'hours_plan' => (float) $weeks->sum('labor_hours_plan'),
        ];

        return [
            'revenue' => [
                'actual' => $totals['revenue_actual'],
                'plan' => $totals['revenue_plan'],
                'percent' => $totals['revenue_plan'] > 0
                    ? round(($totals['revenue_actual'] / $totals['revenue_plan']) * 100, 1)
                    : ($totals['revenue_actual'] > 0 ? 100 : 0),
            ],
            'jobs' => [
                'actual' => $totals['jobs_actual'],
                'plan' => $totals['jobs_plan'],
                'percent' => $totals['jobs_plan'] > 0
                    ? round(($totals['jobs_actual'] / $totals['jobs_plan']) * 100, 1)
                    : ($totals['jobs_actual'] > 0 ? 100 : 0),
            ],
            'labor_hours' => [
                'actual' => $totals['hours_actual'],
                'plan' => $totals['hours_plan'],
                'percent' => $totals['hours_plan'] > 0
                    ? round(($totals['hours_actual'] / $totals['hours_plan']) * 100, 1)
                    : ($totals['hours_actual'] > 0 ? 100 : 0),
            ],
        ];
    }

    /**
     * Calculate annual production goal progress.
     *
     * @return array{actual: float, goal: float, percent: float, remaining: float, weeks_remaining: int, required_weekly: float}
     */
    public function getAnnualProgress(CompanySetting $settings, int $year, float $annualGoal): array
    {
        $weeks = $this->getWeeksForYear($settings, $year);
        $actualRevenue = (float) $weeks->sum('revenue_actual');

        $currentWeek = Carbon::now()->weekOfYear;
        $weeksRemaining = max(0, 52 - $currentWeek);
        $remaining = max(0, $annualGoal - $actualRevenue);
        $requiredWeekly = $weeksRemaining > 0 ? $remaining / $weeksRemaining : 0;

        return [
            'actual' => $actualRevenue,
            'goal' => $annualGoal,
            'percent' => $annualGoal > 0 ? round(($actualRevenue / $annualGoal) * 100, 1) : 0,
            'remaining' => $remaining,
            'weeks_remaining' => $weeksRemaining,
            'required_weekly' => round($requiredWeekly, 2),
        ];
    }

    /**
     * Get weekly trend data for charts.
     *
     * @return Collection<int, array{week: int, label: string, revenue_plan: float, revenue_actual: float, jobs_plan: int, jobs_actual: int, hours_plan: float, hours_actual: float}>
     */
    public function getWeeklyTrend(CompanySetting $settings, int $year, int $startWeek = 1, int $endWeek = 52): Collection
    {
        $weeks = ProductionPlanWeek::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->whereBetween('week_number', [$startWeek, $endWeek])
            ->orderBy('week_number')
            ->get();

        return $weeks->map(fn (ProductionPlanWeek $week) => [
            'week' => $week->week_number,
            'label' => $week->week_label,
            'revenue_plan' => (float) $week->revenue_plan,
            'revenue_actual' => (float) $week->revenue_actual,
            'jobs_plan' => $week->jobs_completed_plan,
            'jobs_actual' => $week->jobs_completed_actual,
            'hours_plan' => (float) $week->labor_hours_plan,
            'hours_actual' => (float) $week->labor_hours_actual,
        ]);
    }

    /**
     * Pre-populate plan values for the year.
     */
    public function populatePlan(CompanySetting $settings, int $year, float $weeklyRevenue, int $weeklyJobs, float $weeklyHours): void
    {
        for ($week = 1; $week <= 52; $week++) {
            ProductionPlanWeek::updateOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'year' => $year,
                    'week_number' => $week,
                ],
                [
                    'revenue_plan' => $weeklyRevenue,
                    'jobs_completed_plan' => $weeklyJobs,
                    'labor_hours_plan' => $weeklyHours,
                ]
            );
        }
    }
}
