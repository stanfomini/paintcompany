<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\SalesPlanGoal;
use App\Models\SalesPlanWeek;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SalesPlanService
{
    /**
     * Get or create the goal for a specific year.
     */
    public function getGoalForYear(CompanySetting $settings, int $year): SalesPlanGoal
    {
        return SalesPlanGoal::firstOrCreate(
            [
                'company_setting_id' => $settings->id,
                'year' => $year,
            ],
            [
                'annual_revenue_goal' => 1500000,
                'avg_job_size' => 5000,
                'lead_conversion_rate' => 65,
                'estimate_close_rate' => 55,
            ]
        );
    }

    /**
     * Get or create week record for a specific week.
     */
    public function getWeek(CompanySetting $settings, int $week, int $year): SalesPlanWeek
    {
        return SalesPlanWeek::firstOrCreate(
            [
                'company_setting_id' => $settings->id,
                'week_number' => $week,
                'year' => $year,
            ],
            [
                'leads_plan' => 0,
                'leads_actual' => 0,
                'estimates_plan' => 0,
                'estimates_actual' => 0,
                'jobs_booked_plan' => 0,
                'jobs_booked_actual' => 0,
                'revenue_plan' => 0,
                'revenue_actual' => 0,
            ]
        );
    }

    /**
     * Get all weeks for a year with data.
     *
     * @return Collection<int, SalesPlanWeek>
     */
    public function getWeeksForYear(CompanySetting $settings, int $year): Collection
    {
        return SalesPlanWeek::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->orderBy('week_number')
            ->get();
    }

    /**
     * Calculate actuals from app data for a specific week.
     *
     * @return array{leads: int, estimates: int, jobs_booked: int, revenue: float}
     */
    public function calculateWeeklyActuals(int $week, int $year): array
    {
        $weekStart = Carbon::now()->setISODate($year, $week)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        // Count leads (new deals) created this week
        $leads = Deal::whereBetween('created_at', [$weekStart, $weekEnd])->count();

        // Count estimates created this week
        $estimates = Estimate::whereBetween('created_at', [$weekStart, $weekEnd])->count();

        // Count jobs booked (estimates accepted) this week
        $jobsBooked = Estimate::where('status', 'accepted')
            ->whereBetween('accepted_at', [$weekStart, $weekEnd])
            ->count();

        // Calculate revenue from accepted estimates this week
        $revenue = Estimate::where('status', 'accepted')
            ->whereBetween('accepted_at', [$weekStart, $weekEnd])
            ->sum('subtotal');

        return [
            'leads' => $leads,
            'estimates' => $estimates,
            'jobs_booked' => $jobsBooked,
            'revenue' => (float) $revenue,
        ];
    }

    /**
     * Sync actual values for a specific week from app data.
     */
    public function syncWeekActuals(SalesPlanWeek $week): SalesPlanWeek
    {
        $actuals = $this->calculateWeeklyActuals($week->week_number, $week->year);

        $week->update([
            'leads_actual' => $actuals['leads'],
            'estimates_actual' => $actuals['estimates'],
            'jobs_booked_actual' => $actuals['jobs_booked'],
            'revenue_actual' => $actuals['revenue'],
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
     * Calculate YTD progress vs annual goal.
     *
     * @return array{
     *     leads: array{actual: int, target: int, percent: float},
     *     estimates: array{actual: int, target: int, percent: float},
     *     jobs_booked: array{actual: int, target: int, percent: float},
     *     revenue: array{actual: float, target: float, percent: float}
     * }
     */
    public function getYTDProgress(CompanySetting $settings, int $year): array
    {
        $goal = $this->getGoalForYear($settings, $year);
        $weeks = $this->getWeeksForYear($settings, $year);

        $totals = [
            'leads' => $weeks->sum('leads_actual'),
            'estimates' => $weeks->sum('estimates_actual'),
            'jobs_booked' => $weeks->sum('jobs_booked_actual'),
            'revenue' => (float) $weeks->sum('revenue_actual'),
        ];

        // Calculate YTD targets based on current week
        $currentWeek = min(Carbon::now()->weekOfYear, 52);
        $weeklyTargets = $goal->weekly_targets;
        $ytdTargets = [
            'leads' => $weeklyTargets['leads'] * $currentWeek,
            'estimates' => $weeklyTargets['estimates'] * $currentWeek,
            'jobs_booked' => $weeklyTargets['jobs'] * $currentWeek,
            'revenue' => $weeklyTargets['revenue'] * $currentWeek,
        ];

        return [
            'leads' => [
                'actual' => $totals['leads'],
                'target' => $ytdTargets['leads'],
                'percent' => $ytdTargets['leads'] > 0
                    ? round(($totals['leads'] / $ytdTargets['leads']) * 100, 1)
                    : 0,
            ],
            'estimates' => [
                'actual' => $totals['estimates'],
                'target' => $ytdTargets['estimates'],
                'percent' => $ytdTargets['estimates'] > 0
                    ? round(($totals['estimates'] / $ytdTargets['estimates']) * 100, 1)
                    : 0,
            ],
            'jobs_booked' => [
                'actual' => $totals['jobs_booked'],
                'target' => $ytdTargets['jobs_booked'],
                'percent' => $ytdTargets['jobs_booked'] > 0
                    ? round(($totals['jobs_booked'] / $ytdTargets['jobs_booked']) * 100, 1)
                    : 0,
            ],
            'revenue' => [
                'actual' => $totals['revenue'],
                'target' => $ytdTargets['revenue'],
                'percent' => $ytdTargets['revenue'] > 0
                    ? round(($totals['revenue'] / $ytdTargets['revenue']) * 100, 1)
                    : 0,
            ],
        ];
    }

    /**
     * Get weekly trend data for charts.
     *
     * @return Collection<int, array{week: int, label: string, leads_plan: int, leads_actual: int, estimates_plan: int, estimates_actual: int, jobs_plan: int, jobs_actual: int, revenue_plan: float, revenue_actual: float}>
     */
    public function getWeeklyTrend(CompanySetting $settings, int $year, int $startWeek = 1, int $endWeek = 52): Collection
    {
        $weeks = SalesPlanWeek::query()
            ->where('company_setting_id', $settings->id)
            ->where('year', $year)
            ->whereBetween('week_number', [$startWeek, $endWeek])
            ->orderBy('week_number')
            ->get();

        return $weeks->map(fn (SalesPlanWeek $week) => [
            'week' => $week->week_number,
            'label' => $week->week_label,
            'leads_plan' => $week->leads_plan,
            'leads_actual' => $week->leads_actual,
            'estimates_plan' => $week->estimates_plan,
            'estimates_actual' => $week->estimates_actual,
            'jobs_plan' => $week->jobs_booked_plan,
            'jobs_actual' => $week->jobs_booked_actual,
            'revenue_plan' => (float) $week->revenue_plan,
            'revenue_actual' => (float) $week->revenue_actual,
        ]);
    }

    /**
     * Pre-populate plan values from annual goals.
     */
    public function populatePlanFromGoals(CompanySetting $settings, int $year): void
    {
        $goal = $this->getGoalForYear($settings, $year);
        $weeklyTargets = $goal->weekly_targets;

        // Create or update all 52 weeks with plan values from goals
        for ($week = 1; $week <= 52; $week++) {
            SalesPlanWeek::updateOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'year' => $year,
                    'week_number' => $week,
                ],
                [
                    'leads_plan' => $weeklyTargets['leads'],
                    'estimates_plan' => $weeklyTargets['estimates'],
                    'jobs_booked_plan' => $weeklyTargets['jobs'],
                    'revenue_plan' => $weeklyTargets['revenue'],
                ]
            );
        }
    }
}
