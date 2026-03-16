<?php

use App\Models\Estimate;
use App\Models\PackageTemplate;
use App\Models\ProjectPreview;
use App\Models\ServiceCategory;
use App\Services\EstimatePackageGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EstimatePackageGeneratorService;
    $this->category = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
});

it('applies questionnaire discount to generated packages when linked by estimate_id', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    // Create a project preview linked to this estimate with a questionnaire discount
    ProjectPreview::factory()->create([
        'estimate_id' => $estimate->id,
        'questionnaire_discount_percent' => 10.0,
        'status' => 'converted',
    ]);

    PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(10.0);
});

it('applies questionnaire discount to generated packages when linked by deal_id', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    // Create a project preview linked via deal_id (not estimate_id)
    ProjectPreview::factory()->create([
        'deal_id' => $estimate->deal_id,
        'estimate_id' => null,
        'questionnaire_discount_percent' => 15.0,
        'status' => 'converted',
    ]);

    PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(15.0);
});

it('does not apply discount when no questionnaire discount exists', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    // Preview without questionnaire discount
    ProjectPreview::factory()->create([
        'estimate_id' => $estimate->id,
        'questionnaire_discount_percent' => null,
        'status' => 'converted',
    ]);

    PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(0.0);
});

it('does not apply discount when no project preview is linked', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    // No project preview at all

    PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    expect((float) $packages->first()->discount_percent)->toBe(0.0);
});

it('applies questionnaire discount to all generated packages', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 3000,
        'tax_rate' => 0,
    ]);

    ProjectPreview::factory()->create([
        'estimate_id' => $estimate->id,
        'questionnaire_discount_percent' => 5.0,
        'status' => 'converted',
    ]);

    // Create multiple package templates
    PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);
    PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);
    PackageTemplate::factory()->best()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(3);
    $packages->each(function ($package) {
        expect((float) $package->discount_percent)->toBe(5.0);
    });
});

it('prefers estimate_id link over deal_id link', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    // Preview linked by estimate_id with 10% discount
    ProjectPreview::factory()->create([
        'estimate_id' => $estimate->id,
        'questionnaire_discount_percent' => 10.0,
        'status' => 'converted',
    ]);

    // Another preview linked by deal_id with 20% discount (should NOT be used)
    ProjectPreview::factory()->create([
        'deal_id' => $estimate->deal_id,
        'estimate_id' => null,
        'questionnaire_discount_percent' => 20.0,
        'status' => 'converted',
    ]);

    PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);
    // Should use the estimate_id-linked preview's 10%, not the deal_id one's 20%
    expect((float) $packages->first()->discount_percent)->toBe(10.0);
});
