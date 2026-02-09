<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use App\Models\ProductionPlanWeek;
use App\Models\SalesPlanGoal;
use App\Models\SalesPlanWeek;
use Illuminate\Database\Seeder;

class BusinessPlanProjectionSeeder extends Seeder
{
    /**
     * Seasonal jobs-per-week distribution for a $500K first-year painting company.
     * 100 total jobs at $5,000 avg = $500,000 annual revenue.
     *
     * Ramps from week 7 (Feb), peaks in August (weeks 31-35), 90% done by Nov (week 48).
     *
     * @var array<int, int>
     */
    public const WEEKLY_JOBS = [
        // Startup: weeks 1-6 (0 jobs - building pipeline)
        1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0,
        // First sales ramp: weeks 7-10
        7 => 1, 8 => 1, 9 => 1, 10 => 1,
        // Early spring: weeks 11-14
        11 => 1, 12 => 1, 13 => 2, 14 => 2,
        // Spring building: weeks 15-18
        15 => 2, 16 => 2, 17 => 2, 18 => 2,
        // Late spring: weeks 19-22
        19 => 2, 20 => 2, 21 => 2, 22 => 2,
        // Early summer: weeks 23-26
        23 => 2, 24 => 3, 25 => 3, 26 => 2,
        // Mid summer: weeks 27-30
        27 => 3, 28 => 3, 29 => 3, 30 => 3,
        // August peak: weeks 31-35
        31 => 3, 32 => 4, 33 => 4, 34 => 3, 35 => 3,
        // September taper: weeks 36-39
        36 => 3, 37 => 2, 38 => 2, 39 => 2,
        // October: weeks 40-44
        40 => 2, 41 => 2, 42 => 2, 43 => 2, 44 => 2,
        // November: weeks 45-48 (90% mark at end of 48)
        45 => 2, 46 => 2, 47 => 1, 48 => 1,
        // December: weeks 49-52
        49 => 3, 50 => 3, 51 => 2, 52 => 2,
    ];

    private const AVG_JOB_SIZE = 5000;

    private const ESTIMATE_CLOSE_RATE = 0.55;

    private const LEAD_CONVERSION_RATE = 0.65;

    private const PRODUCTION_LAG_WEEKS = 3;

    private const LABOR_HOURS_PER_JOB = 40;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $year = (int) now()->year;
        $settings = CompanySetting::getInstance();

        $this->seedSalesPlanGoal($settings, $year);
        $this->seedSalesPlanWeeks($settings, $year);
        $this->seedProductionPlanWeeks($settings, $year);

        $totalJobs = array_sum(self::WEEKLY_JOBS);
        $totalRevenue = $totalJobs * self::AVG_JOB_SIZE;

        $this->command->info("Seasonal business plan projections seeded for {$year}.");
        $this->command->table(
            ['Metric', 'Value'],
            [
                ['Annual Revenue Goal', '$'.number_format($totalRevenue)],
                ['Total Jobs Planned', $totalJobs],
                ['Avg Job Size', '$'.number_format(self::AVG_JOB_SIZE)],
                ['Sales Weeks Seeded', '52'],
                ['Production Weeks Seeded', '52'],
                ['Production Lag', self::PRODUCTION_LAG_WEEKS.' weeks'],
            ]
        );
    }

    private function seedSalesPlanGoal(CompanySetting $settings, int $year): void
    {
        SalesPlanGoal::updateOrCreate(
            [
                'company_setting_id' => $settings->id,
                'year' => $year,
            ],
            [
                'annual_revenue_goal' => 500000,
                'avg_job_size' => self::AVG_JOB_SIZE,
                'lead_conversion_rate' => self::LEAD_CONVERSION_RATE * 100,
                'estimate_close_rate' => self::ESTIMATE_CLOSE_RATE * 100,
            ]
        );
    }

    private function seedSalesPlanWeeks(CompanySetting $settings, int $year): void
    {
        for ($week = 1; $week <= 52; $week++) {
            $jobs = self::WEEKLY_JOBS[$week];
            $revenue = $jobs * self::AVG_JOB_SIZE;
            $estimates = $jobs > 0 ? (int) ceil($jobs / self::ESTIMATE_CLOSE_RATE) : 0;
            $leads = $estimates > 0 ? (int) ceil($estimates / self::LEAD_CONVERSION_RATE) : 0;

            SalesPlanWeek::updateOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'year' => $year,
                    'week_number' => $week,
                ],
                [
                    'leads_plan' => $leads,
                    'estimates_plan' => $estimates,
                    'jobs_booked_plan' => $jobs,
                    'revenue_plan' => $revenue,
                ]
            );
        }
    }

    private function seedProductionPlanWeeks(CompanySetting $settings, int $year): void
    {
        for ($week = 1; $week <= 52; $week++) {
            $salesWeek = $week - self::PRODUCTION_LAG_WEEKS;
            $jobs = ($salesWeek >= 1 && $salesWeek <= 52)
                ? self::WEEKLY_JOBS[$salesWeek]
                : 0;

            $revenue = $jobs * self::AVG_JOB_SIZE;
            $laborHours = $jobs * self::LABOR_HOURS_PER_JOB;

            ProductionPlanWeek::updateOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'year' => $year,
                    'week_number' => $week,
                ],
                [
                    'jobs_completed_plan' => $jobs,
                    'revenue_plan' => $revenue,
                    'labor_hours_plan' => $laborHours,
                ]
            );
        }
    }
}
