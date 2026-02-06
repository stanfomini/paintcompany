<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\ProductionPlanWeek;
use App\Services\ProductionPlanService;
use Carbon\Carbon;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->service = new ProductionPlanService();
});

it('creates week record if not exists', function () {
    $week = $this->service->getWeek($this->settings, 5, 2026);

    expect($week)->toBeInstanceOf(ProductionPlanWeek::class)
        ->and($week->week_number)->toBe(5)
        ->and($week->year)->toBe(2026);
});

it('returns all weeks for year', function () {
    ProductionPlanWeek::factory()->count(3)->create([
        'company_setting_id' => $this->settings->id,
        'year' => 2026,
    ]);

    $weeks = $this->service->getWeeksForYear($this->settings, 2026);

    expect($weeks)->toHaveCount(3);
});

it('calculates weekly actuals from completed jobs', function () {
    // Set a specific week
    $weekStart = Carbon::parse('2026-02-02'); // Week 6 of 2026

    // Create a deal for the job
    $deal = Deal::factory()->create();

    // Create an estimate with a subtotal
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'status' => 'accepted',
        'subtotal' => 5000,
    ]);

    // Create a completed job in this week
    Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
        'stage' => 'completed',
        'actual_end_date' => $weekStart->copy()->addDays(3),
    ]);

    $actuals = $this->service->calculateWeeklyActuals(6, 2026);

    expect($actuals['jobs_completed'])->toBe(1)
        ->and($actuals['revenue'])->toBe(5000.0);
});

it('syncs week actuals from app data', function () {
    $week = ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => 2026,
        'week_number' => 6,
        'revenue_actual' => 0,
    ]);

    // Create completed job data
    $weekStart = Carbon::parse('2026-02-02');
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'status' => 'accepted',
        'subtotal' => 8000,
    ]);
    Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
        'stage' => 'completed',
        'actual_end_date' => $weekStart->copy()->addDays(2),
    ]);

    $updatedWeek = $this->service->syncWeekActuals($week);

    expect($updatedWeek->jobs_completed_actual)->toBe(1)
        ->and($updatedWeek->revenue_actual)->toBe('8000.00')
        ->and($updatedWeek->synced_at)->not->toBeNull();
});

it('calculates ytd progress', function () {
    ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'week_number' => 1,
        'revenue_plan' => 20000,
        'revenue_actual' => 25000,
        'jobs_completed_plan' => 4,
        'jobs_completed_actual' => 5,
        'labor_hours_plan' => 160,
        'labor_hours_actual' => 180,
    ]);

    $progress = $this->service->getYTDProgress($this->settings, now()->year);

    expect($progress)->toHaveKeys(['revenue', 'jobs', 'labor_hours'])
        ->and($progress['revenue']['actual'])->toBe(25000.0)
        ->and($progress['revenue']['plan'])->toBe(20000.0)
        ->and($progress['revenue']['percent'])->toBe(125.0);
});

it('calculates annual progress', function () {
    // Create some weekly data for current year
    ProductionPlanWeek::factory()->count(5)->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'revenue_actual' => 20000, // Total: 100k
    ]);

    $progress = $this->service->getAnnualProgress($this->settings, now()->year, 500000);

    expect($progress)->toHaveKeys(['actual', 'goal', 'percent', 'remaining', 'weeks_remaining', 'required_weekly'])
        ->and($progress['actual'])->toBe(100000.0)
        ->and($progress['goal'])->toBe(500000.0)
        ->and($progress['percent'])->toBe(20.0)
        ->and($progress['remaining'])->toBe(400000.0);
});

it('populates plan for year', function () {
    $this->service->populatePlan($this->settings, 2026, 25000, 5, 160);

    $weeks = $this->service->getWeeksForYear($this->settings, 2026);

    expect($weeks)->toHaveCount(52)
        ->and($weeks->first()->revenue_plan)->toBe('25000.00')
        ->and($weeks->first()->jobs_completed_plan)->toBe(5)
        ->and($weeks->first()->labor_hours_plan)->toBe('160.00');
});

it('calculates revenue percent of plan', function () {
    $week = ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'revenue_plan' => 20000,
        'revenue_actual' => 25000,
    ]);

    expect($week->revenue_percent_of_plan)->toBe(125.0);
});

it('handles zero plan in percent calculation', function () {
    $week = ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'revenue_plan' => 0,
        'revenue_actual' => 10000,
    ]);

    expect($week->revenue_percent_of_plan)->toBe(100.0);
});

it('calculates revenue variance', function () {
    $week = ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'revenue_plan' => 20000,
        'revenue_actual' => 18000,
    ]);

    expect($week->revenue_variance)->toBe(-2000.0);
});

it('determines if week meets target', function () {
    $goodWeek = ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'revenue_plan' => 20000,
        'revenue_actual' => 25000,
    ]);

    $badWeek = ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'revenue_plan' => 20000,
        'revenue_actual' => 15000,
    ]);

    expect($goodWeek->meets_target)->toBeTrue()
        ->and($badWeek->meets_target)->toBeFalse();
});

it('formats week label correctly', function () {
    $week = ProductionPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => 2026,
        'week_number' => 6,
    ]);

    expect($week->week_label)->toStartWith('Week 6');
});
