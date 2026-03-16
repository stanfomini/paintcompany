<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\PackageTemplate;
use App\Models\ProjectPreview;
use App\Services\EstimatePackageGeneratorService;

it('applies questionnaire discount to generated packages', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create(['tax_rate' => 0]);

    // Create a project preview with completed questionnaire linked to the estimate
    ProjectPreview::factory()->create([
        'estimate_id' => $estimate->id,
        'deal_id' => $deal->id,
        'questionnaire_completed_at' => now(),
        'questionnaire_discount_percent' => 5.00,
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
            'step_3' => ['areas' => ['living_areas'], 'notes' => ''],
            'step_4' => ['logistics' => [], 'notes' => ''],
            'step_5' => ['investment' => 'good_quality', 'timeline' => '2_4_weeks', 'notes' => ''],
        ],
    ]);

    // Create a package template
    PackageTemplate::factory()->good()->create();

    $service = new EstimatePackageGeneratorService;
    $packages = $service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(5.00);
});

it('does not apply discount when preview has no questionnaire', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create(['tax_rate' => 0]);

    // Preview without questionnaire completion
    ProjectPreview::factory()->create([
        'estimate_id' => $estimate->id,
        'deal_id' => $deal->id,
        'questionnaire_completed_at' => null,
        'questionnaire_discount_percent' => null,
    ]);

    PackageTemplate::factory()->good()->create();

    $service = new EstimatePackageGeneratorService;
    $packages = $service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(0.00);
});

it('does not apply discount when no preview exists', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create(['tax_rate' => 0]);

    PackageTemplate::factory()->good()->create();

    $service = new EstimatePackageGeneratorService;
    $packages = $service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(0.00);
});

it('applies discount via deal_id when estimate_id not set on preview', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create(['tax_rate' => 0]);

    // Preview linked by deal but not by estimate_id
    ProjectPreview::factory()->create([
        'estimate_id' => null,
        'deal_id' => $deal->id,
        'questionnaire_completed_at' => now(),
        'questionnaire_discount_percent' => 5.00,
        'questionnaire_data' => [
            'step_1' => ['motivation' => 'selling', 'notes' => ''],
            'step_2' => ['desired_outcome' => 'modern_clean', 'notes' => ''],
            'step_3' => ['areas' => ['living_areas'], 'notes' => ''],
            'step_4' => ['logistics' => [], 'notes' => ''],
            'step_5' => ['investment' => 'good_quality', 'timeline' => 'flexible', 'notes' => ''],
        ],
    ]);

    PackageTemplate::factory()->good()->create();

    $service = new EstimatePackageGeneratorService;
    $packages = $service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(5.00);
});

it('discount affects package total calculation', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create(['tax_rate' => 0]);

    ProjectPreview::factory()->create([
        'estimate_id' => $estimate->id,
        'deal_id' => $deal->id,
        'questionnaire_completed_at' => now(),
        'questionnaire_discount_percent' => 5.00,
    ]);

    // Create a package with known subtotal and apply discount
    $package = EstimatePackage::factory()->forEstimate($estimate)->create([
        'subtotal' => 1000.00,
        'tax_rate' => 0,
        'discount_percent' => 5.00,
        'discount_amount' => 0,
        'warranty_price' => 0,
    ]);

    // Add an item to the package
    $item = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'total' => 1000,
    ]);

    EstimatePackageItem::factory()->create([
        'package_id' => $package->id,
        'itemable_type' => EstimateItem::class,
        'itemable_id' => $item->id,
        'is_included' => true,
        'customer_approved' => true,
    ]);

    $package->calculateTotals();
    $package->save();

    // 1000 - 5% = 950
    expect((float) $package->total)->toBe(950.00);
    expect((float) $package->discount_amount)->toBe(50.00);
});
