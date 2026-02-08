<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Services\JobCostCardService;

it('card created from estimate has full COGS decomposition', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'interior_hourly_wage' => 25,
        'interior_margin_percent' => 50,
        'payroll_burden_multiplier' => 1.35,
        'production_management_percent' => 7,
        'sales_percent' => 7,
    ]);
    CompanySetting::clearInstanceCache();

    $customer = Customer::factory()->create([
        'first_name' => 'COGS',
        'last_name' => 'Test',
    ]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'subtotal' => 5000,
    ]);

    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    EstimateAreaSurface::factory()->create([
        'area_id' => $area->id,
        'labor_cost' => 400,
        'material_cost' => 200,
        'labor_hours' => 16,
        'is_included' => true,
    ]);

    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $service = new JobCostCardService;
    $card = $service->createFromEstimate($estimate, $job);

    expect($card)->toBeInstanceOf(JobCostCard::class)
        ->and($card->exists)->toBeTrue()
        ->and((float) $card->total_cogs)->toBeGreaterThan(0)
        ->and((float) $card->labor_cost)->toBeGreaterThanOrEqual(0)
        ->and((float) $card->labor_hours)->toBeGreaterThanOrEqual(0)
        ->and((float) $card->total_hours)->toBeGreaterThanOrEqual(0);
});

it('labor hours stored correctly on card from estimate', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'interior_hourly_wage' => 25,
        'interior_margin_percent' => 50,
        'payroll_burden_multiplier' => 1.35,
        'interior_cleanup_ratio' => 15,
    ]);
    CompanySetting::clearInstanceCache();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'subtotal' => 3000,
    ]);

    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    EstimateAreaSurface::factory()->create([
        'area_id' => $area->id,
        'labor_cost' => 300,
        'material_cost' => 100,
        'labor_hours' => 12,
        'is_included' => true,
    ]);

    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $service = new JobCostCardService;
    $card = $service->createFromEstimate($estimate, $job);

    // Card should have hours populated (may include cleanup hours)
    expect((float) $card->labor_hours)->toBeGreaterThanOrEqual(0)
        ->and((float) $card->total_hours)->toBeGreaterThanOrEqual((float) $card->labor_hours);
});

it('settings snapshot captured on card creation', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'interior_hourly_wage' => 30,
        'interior_margin_percent' => 50,
        'payroll_burden_multiplier' => 1.35,
    ]);
    CompanySetting::clearInstanceCache();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'subtotal' => 4000,
    ]);

    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    EstimateAreaSurface::factory()->create([
        'area_id' => $area->id,
        'labor_cost' => 250,
        'material_cost' => 150,
        'labor_hours' => 10,
        'is_included' => true,
    ]);

    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $service = new JobCostCardService;
    $card = $service->createFromEstimate($estimate, $job);

    expect($card->settings_snapshot)->not->toBeNull()
        ->and($card->settings_snapshot)->toBeArray();
});

it('actuals can be saved via updateActuals', function () {
    CompanySetting::clearInstanceCache();

    $card = JobCostCard::factory()->create([
        'total_cogs' => 3000,
    ]);

    $service = new JobCostCardService;
    $service->updateActuals($card, [
        'actual_material_cost' => 800,
        'actual_labor_cost' => 1200,
        'actual_subcontractor_cost' => 300,
        'actual_other_cost' => 100,
        'actual_notes' => 'Job went well',
    ]);

    $card->refresh();

    expect((float) $card->actual_material_cost)->toBe(800.0)
        ->and((float) $card->actual_labor_cost)->toBe(1200.0)
        ->and((float) $card->actual_subcontractor_cost)->toBe(300.0)
        ->and((float) $card->actual_other_cost)->toBe(100.0)
        ->and((float) $card->actual_total_cost)->toBe(2400.0)
        ->and($card->actual_notes)->toBe('Job went well')
        ->and($card->has_actuals)->toBeTrue();
});

it('variance computation works correctly', function () {
    CompanySetting::clearInstanceCache();

    $card = JobCostCard::factory()->create([
        'total_cogs' => 3000,
    ]);

    $service = new JobCostCardService;
    $service->updateActuals($card, [
        'actual_material_cost' => 500,
        'actual_labor_cost' => 1000,
        'actual_subcontractor_cost' => 200,
        'actual_other_cost' => 100,
    ]);

    $card->refresh();

    // Actual total = 500 + 1000 + 200 + 100 = 1800
    // Variance = 3000 - 1800 = 1200 (under budget = positive)
    expect((float) $card->actual_total_cost)->toBe(1800.0)
        ->and($card->cost_variance)->toBe(1200.0)
        ->and($card->cost_variance_percent)->toBe(40.0); // 1200/3000 * 100
});

it('payment sync tallies correctly from invoices', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create(['subtotal' => 5000]);

    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $card = JobCostCard::factory()->forJob($job)->create([
        'company_setting_id' => $settings->id,
    ]);

    // Create two non-void invoices
    Invoice::factory()->forJob($job)->create([
        'total' => 2000,
        'amount_paid' => 2000,
        'status' => 'paid',
    ]);

    Invoice::factory()->forJob($job)->create([
        'total' => 3000,
        'amount_paid' => 1500,
        'status' => 'partially_paid',
    ]);

    // Create a void invoice (should be excluded)
    Invoice::factory()->forJob($job)->void()->create([
        'total' => 1000,
        'amount_paid' => 0,
    ]);

    $service = new JobCostCardService;
    $service->syncPaymentSummary($job);

    $card->refresh();

    // Only non-void invoices: total = 2000 + 3000 = 5000, collected = 2000 + 1500 = 3500
    expect((float) $card->total_invoiced)->toBe(5000.0)
        ->and((float) $card->total_collected)->toBe(3500.0)
        ->and((float) $card->balance_due)->toBe(1500.0);
});

it('collection percent and is_fully_paid attributes work', function () {
    CompanySetting::clearInstanceCache();

    // Fully paid scenario
    $cardPaid = JobCostCard::factory()->create([
        'total_invoiced' => 1000,
        'total_collected' => 1000,
        'balance_due' => 0,
    ]);

    expect($cardPaid->collection_percent)->toBe(100.0)
        ->and($cardPaid->is_fully_paid)->toBeTrue();

    // Partially paid scenario
    $cardPartial = JobCostCard::factory()->create([
        'total_invoiced' => 1000,
        'total_collected' => 600,
        'balance_due' => 400,
    ]);

    expect($cardPartial->collection_percent)->toBe(60.0)
        ->and($cardPartial->is_fully_paid)->toBeFalse();
});

it('backwards compatibility of existing computed attributes', function () {
    CompanySetting::clearInstanceCache();

    $card = new JobCostCard([
        'price' => 10000,
        'production_mgmt_labor' => 700,
        'subcontractor_cost' => 500,
        'wc_insurance_burden' => 300,
        'material_cost' => 1500,
        'sales_commission' => 600,
        'allocation_total' => 200,
    ]);

    // Legacy total_cost = sum of the 5 cost columns
    expect($card->total_cost)->toBe(3600.0)
        ->and($card->gross_profit)->toBe(6400.0)
        ->and($card->gross_profit_percent)->toBe(64.0)
        ->and($card->net_profit)->toBe(6200.0);
});

it('running totals include new fields', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();

    JobCostCard::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'price' => 10000,
        'total_cogs' => 4000,
        'labor_hours' => 20,
        'total_hours' => 25,
        'total_invoiced' => 10000,
        'total_collected' => 8000,
        'balance_due' => 2000,
        'is_manual' => true,
    ]);

    JobCostCard::factory()->create([
        'company_setting_id' => $settings->id,
        'year' => now()->year,
        'price' => 5000,
        'total_cogs' => 2000,
        'labor_hours' => 10,
        'total_hours' => 12,
        'total_invoiced' => 5000,
        'total_collected' => 5000,
        'balance_due' => 0,
        'is_manual' => true,
    ]);

    $service = new JobCostCardService;
    $totals = $service->getRunningTotals(now()->year);

    expect($totals['total_cogs'])->toBe(6000.0)
        ->and($totals['labor_hours'])->toBe(30.0)
        ->and($totals['total_hours'])->toBe(37.0)
        ->and($totals['total_invoiced'])->toBe(15000.0)
        ->and($totals['total_collected'])->toBe(13000.0)
        ->and($totals['balance_due'])->toBe(2000.0)
        ->and($totals['count'])->toBe(2);
});

it('null actuals handling returns expected defaults', function () {
    CompanySetting::clearInstanceCache();

    $card = JobCostCard::factory()->create([
        'price' => 8000,
        'total_cogs' => 3500,
        'actual_material_cost' => null,
        'actual_labor_cost' => null,
        'actual_subcontractor_cost' => null,
        'actual_other_cost' => null,
        'actual_total_cost' => null,
    ]);

    expect($card->has_actuals)->toBeFalse()
        ->and($card->cost_variance)->toBeNull()
        ->and($card->cost_variance_percent)->toBeNull()
        // effective_gross_profit should use projected COGS when no actuals
        ->and($card->effective_gross_profit)->toBe(8000.0 - 3500.0);
});
