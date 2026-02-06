<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\SalesPlanGoal;
use App\Models\SalesPlanWeek;
use App\Services\SalesPlanService;
use Carbon\Carbon;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->service = new SalesPlanService;
});

it('creates goal for year if not exists', function () {
    $goal = $this->service->getGoalForYear($this->settings, 2026);

    expect($goal)->toBeInstanceOf(SalesPlanGoal::class)
        ->and($goal->year)->toBe(2026)
        ->and($goal->annual_revenue_goal)->toBe('1500000.00');
});

it('returns existing goal for year', function () {
    $existingGoal = SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => 2026,
        'annual_revenue_goal' => 2000000,
    ]);

    $goal = $this->service->getGoalForYear($this->settings, 2026);

    expect($goal->id)->toBe($existingGoal->id)
        ->and($goal->annual_revenue_goal)->toBe('2000000.00');
});

it('creates week record if not exists', function () {
    $week = $this->service->getWeek($this->settings, 5, 2026);

    expect($week)->toBeInstanceOf(SalesPlanWeek::class)
        ->and($week->week_number)->toBe(5)
        ->and($week->year)->toBe(2026);
});

it('returns all weeks for year', function () {
    SalesPlanWeek::factory()->count(3)->create([
        'company_setting_id' => $this->settings->id,
        'year' => 2026,
    ]);

    $weeks = $this->service->getWeeksForYear($this->settings, 2026);

    expect($weeks)->toHaveCount(3);
});

it('calculates weekly actuals from deals and estimates', function () {
    // Use a different year to avoid conflicts with current test date
    $weekStart = Carbon::parse('2025-02-03'); // Week 6 of 2025
    $weekEnd = $weekStart->copy()->endOfWeek();

    // Create deals in this week
    $deals = Deal::factory()->count(3)->create([
        'created_at' => $weekStart->copy()->addDay(),
    ]);

    // Create estimates in this week (reuse the deals we created)
    Estimate::factory()->count(2)->create([
        'deal_id' => $deals->first()->id,
        'status' => 'draft',
        'created_at' => $weekStart->copy()->addDays(2),
    ]);

    // Create accepted estimates in this week
    Estimate::factory()->count(2)->create([
        'deal_id' => $deals->last()->id,
        'status' => 'accepted',
        'created_at' => $weekStart->copy()->addDays(3),
        'accepted_at' => $weekStart->copy()->addDays(3),
        'subtotal' => 5000,
    ]);

    $actuals = $this->service->calculateWeeklyActuals(6, 2025);

    expect($actuals['leads'])->toBe(3)
        ->and($actuals['estimates'])->toBe(4) // 2 draft + 2 accepted
        ->and($actuals['jobs_booked'])->toBe(2)
        ->and($actuals['revenue'])->toBe(10000.0);
});

it('syncs week actuals from app data', function () {
    $week = SalesPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => 2026,
        'week_number' => 6,
        'leads_actual' => 0,
    ]);

    // Create a deal in week 6
    $weekStart = Carbon::parse('2026-02-02');
    Deal::factory()->create(['created_at' => $weekStart->copy()->addDay()]);

    $updatedWeek = $this->service->syncWeekActuals($week);

    expect($updatedWeek->leads_actual)->toBe(1)
        ->and($updatedWeek->synced_at)->not->toBeNull();
});

it('calculates ytd progress against goals', function () {
    SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'annual_revenue_goal' => 1040000, // $20k/week
        'avg_job_size' => 5000,
        'lead_conversion_rate' => 50,
        'estimate_close_rate' => 50,
    ]);

    // Create some weekly data
    SalesPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'week_number' => 1,
        'leads_actual' => 10,
        'estimates_actual' => 5,
        'jobs_booked_actual' => 2,
        'revenue_actual' => 10000,
    ]);

    $progress = $this->service->getYTDProgress($this->settings, now()->year);

    expect($progress)->toHaveKeys(['leads', 'estimates', 'jobs_booked', 'revenue'])
        ->and($progress['leads']['actual'])->toBe(10)
        ->and($progress['revenue']['actual'])->toBe(10000.0);
});

it('populates plan from goals', function () {
    SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => 2026,
        'annual_revenue_goal' => 520000, // $10k/week
        'avg_job_size' => 5000, // 104 jobs/year = 2/week
        'lead_conversion_rate' => 100,
        'estimate_close_rate' => 100,
    ]);

    $this->service->populatePlanFromGoals($this->settings, 2026);

    $weeks = $this->service->getWeeksForYear($this->settings, 2026);

    expect($weeks)->toHaveCount(52)
        ->and($weeks->first()->revenue_plan)->toBe('10000.00');
});

it('calculates required jobs from goal', function () {
    $goal = SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'annual_revenue_goal' => 1500000,
        'avg_job_size' => 5000,
    ]);

    expect($goal->required_jobs)->toBe(300);
});

it('calculates required leads from goal', function () {
    $goal = SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'annual_revenue_goal' => 1000000,
        'avg_job_size' => 5000, // 200 jobs
        'lead_conversion_rate' => 50, // 400 estimates
        'estimate_close_rate' => 50, // 800 leads
    ]);

    expect($goal->required_leads)->toBe(800);
});

it('calculates weekly targets from goal', function () {
    $goal = SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'annual_revenue_goal' => 520000, // $10k/week
        'avg_job_size' => 5200, // 100 jobs/year
        'lead_conversion_rate' => 50, // 200 estimates
        'estimate_close_rate' => 50, // 400 leads
    ]);

    $targets = $goal->weekly_targets;

    expect($targets)->toHaveKeys(['leads', 'estimates', 'jobs', 'revenue'])
        ->and($targets['jobs'])->toBe(2)
        ->and($targets['revenue'])->toBe(10000.0);
});
