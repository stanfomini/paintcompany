<?php

use App\Models\CompanySetting;
use App\Models\ProductionPlanWeek;
use App\Models\SalesPlanGoal;
use App\Models\SalesPlanWeek;
use Database\Seeders\BusinessPlanProjectionSeeder;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->year = (int) now()->year;
    $this->seed(BusinessPlanProjectionSeeder::class);
});

it('sets the annual revenue goal to $500,000', function () {
    $goal = SalesPlanGoal::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->first();

    expect($goal)->not->toBeNull()
        ->and((float) $goal->annual_revenue_goal)->toBe(500000.00)
        ->and((float) $goal->avg_job_size)->toBe(5000.00)
        ->and((float) $goal->lead_conversion_rate)->toBe(65.00)
        ->and((float) $goal->estimate_close_rate)->toBe(55.00);
});

it('creates 52 sales plan weeks', function () {
    $count = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->count();

    expect($count)->toBe(52);
});

it('creates 52 production plan weeks', function () {
    $count = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->count();

    expect($count)->toBe(52);
});

it('plans 100 total sales jobs for $500,000 revenue', function () {
    $weeks = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->get();

    $totalJobs = $weeks->sum('jobs_booked_plan');
    $totalRevenue = $weeks->sum('revenue_plan');

    expect($totalJobs)->toBe(100)
        ->and((float) $totalRevenue)->toBe(500000.00);
});

it('has zero activity during startup weeks 1-6', function () {
    $startupWeeks = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->whereBetween('week_number', [1, 6])
        ->get();

    expect($startupWeeks)->toHaveCount(6);

    foreach ($startupWeeks as $week) {
        expect($week->jobs_booked_plan)->toBe(0)
            ->and($week->leads_plan)->toBe(0)
            ->and($week->estimates_plan)->toBe(0)
            ->and((float) $week->revenue_plan)->toBe(0.00);
    }
});

it('books first job in week 7', function () {
    $week7 = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->where('week_number', 7)
        ->first();

    expect($week7->jobs_booked_plan)->toBe(1)
        ->and((float) $week7->revenue_plan)->toBe(5000.00);
});

it('is 90% done by end of November (week 48)', function () {
    $byNov = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->whereBetween('week_number', [1, 48])
        ->get();

    $jobs = $byNov->sum('jobs_booked_plan');
    $revenue = (float) $byNov->sum('revenue_plan');

    expect($jobs)->toBe(90)
        ->and($revenue)->toBe(450000.00);
});

it('has remaining 10% in December (weeks 49-52)', function () {
    $december = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->whereBetween('week_number', [49, 52])
        ->get();

    $jobs = $december->sum('jobs_booked_plan');
    $revenue = (float) $december->sum('revenue_plan');

    expect($jobs)->toBe(10)
        ->and($revenue)->toBe(50000.00);
});

it('peaks in August (weeks 31-35) with highest weekly averages', function () {
    // August peak: 3, 4, 4, 3, 3 = 17 jobs in 5 weeks = 3.4/week avg
    $august = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->whereBetween('week_number', [31, 35])
        ->get();

    $augustJobs = $august->sum('jobs_booked_plan');
    expect($augustJobs)->toBe(17);

    // Week 32 and 33 are the peak at 4 jobs each
    $week32 = $august->firstWhere('week_number', 32);
    $week33 = $august->firstWhere('week_number', 33);
    expect($week32->jobs_booked_plan)->toBe(4)
        ->and($week33->jobs_booked_plan)->toBe(4);
});

it('derives leads and estimates from conversion rates', function () {
    // Week 32 has 4 jobs booked (peak)
    $peakWeek = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->where('week_number', 32)
        ->first();

    // 4 jobs / 0.55 close rate = ceil(7.27) = 8 estimates
    expect($peakWeek->estimates_plan)->toBe(8);

    // 8 estimates / 0.65 conversion rate = ceil(12.31) = 13 leads
    expect($peakWeek->leads_plan)->toBe(13);
});

it('production lags sales by 3 weeks', function () {
    // Week 7 has first sale (1 job) -> production sees it in week 10
    $productionWeek10 = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->where('week_number', 10)
        ->first();

    expect($productionWeek10->jobs_completed_plan)->toBe(1);

    // Week 8 has 1 sale -> production in week 11
    $productionWeek11 = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->where('week_number', 11)
        ->first();

    expect($productionWeek11->jobs_completed_plan)->toBe(1);

    // Production weeks 1-9 should be 0
    $earlyProduction = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->whereBetween('week_number', [1, 9])
        ->get();

    foreach ($earlyProduction as $week) {
        expect($week->jobs_completed_plan)->toBe(0);
    }
});

it('production peak shifts to weeks 34-38 due to lag', function () {
    // Sales peak is weeks 31-35, so production peak shifts to 34-38
    $prodPeak = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->whereBetween('week_number', [34, 38])
        ->get();

    // Should mirror sales weeks 31-35: 3, 4, 4, 3, 3
    expect($prodPeak->firstWhere('week_number', 35)->jobs_completed_plan)->toBe(4)
        ->and($prodPeak->firstWhere('week_number', 36)->jobs_completed_plan)->toBe(4);
});

it('assigns labor hours proportional to jobs at 40 hours each', function () {
    $weeks = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->get();

    foreach ($weeks as $week) {
        $expectedHours = $week->jobs_completed_plan * 40;
        expect((float) $week->labor_hours_plan)->toBe((float) $expectedHours,
            "Week {$week->week_number}: expected {$expectedHours} hours, got {$week->labor_hours_plan}"
        );
    }
});

it('leaves actual columns at zero', function () {
    $salesWeeks = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->get();

    foreach ($salesWeeks as $week) {
        expect($week->leads_actual)->toBe(0)
            ->and($week->estimates_actual)->toBe(0)
            ->and($week->jobs_booked_actual)->toBe(0);
    }

    $productionWeeks = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->get();

    foreach ($productionWeeks as $week) {
        expect($week->jobs_completed_actual)->toBe(0);
    }
});

it('is idempotent when run twice', function () {
    $this->seed(BusinessPlanProjectionSeeder::class);

    $salesCount = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->count();

    $productionCount = ProductionPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->count();

    $goalCount = SalesPlanGoal::where('company_setting_id', $this->settings->id)
        ->where('year', $this->year)
        ->count();

    expect($salesCount)->toBe(52)
        ->and($productionCount)->toBe(52)
        ->and($goalCount)->toBe(1);
});
