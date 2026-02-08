<?php

use App\Models\Estimate;
use App\Models\EstimatePackage;
use App\Models\PackageTemplate;
use App\Models\ServiceCategory;
use App\Services\EstimatePackageGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EstimatePackageGeneratorService;

    $this->painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );

    $this->cleaning = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );
});

it('filters templates by service category when generating', function () {
    $estimate = Estimate::factory()->create(['areas_subtotal' => 2000, 'tax_rate' => 0]);

    PackageTemplate::factory()->good()->forService($this->painting)->create();
    PackageTemplate::factory()->better()->forService($this->painting)->create();
    PackageTemplate::factory()->good()->forService($this->cleaning)->create(['name' => 'Good Cleaning']);

    $packages = $this->service->generateFromTemplates($estimate, $this->painting);

    expect($packages)->toHaveCount(2);
    expect($packages->every(fn ($p) => $p->service_category_id === $this->painting->id))->toBeTrue();
});

it('generates all templates when no category filter provided', function () {
    $estimate = Estimate::factory()->create(['areas_subtotal' => 2000, 'tax_rate' => 0]);

    PackageTemplate::factory()->good()->forService($this->painting)->create();
    PackageTemplate::factory()->good()->forService($this->cleaning)->create(['name' => 'Good Cleaning']);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(2);
});

it('sets service_category_id on generated estimate packages', function () {
    $estimate = Estimate::factory()->create(['areas_subtotal' => 2000, 'tax_rate' => 0]);

    PackageTemplate::factory()->good()->forService($this->painting)->create();

    $this->service->generateFromTemplates($estimate, $this->painting);

    $package = $estimate->packages()->first();
    expect($package->service_category_id)->toBe($this->painting->id);
});

it('scopes shouldUseTemplates by service category', function () {
    $estimate = Estimate::factory()->create();

    PackageTemplate::factory()->good()->forService($this->painting)->create();

    expect($this->service->shouldUseTemplates($estimate, $this->painting))->toBeTrue();
    expect($this->service->shouldUseTemplates($estimate, $this->cleaning))->toBeFalse();
});

it('PackageTemplate scopeForService filters correctly', function () {
    PackageTemplate::factory()->good()->forService($this->painting)->create();
    PackageTemplate::factory()->good()->forService($this->cleaning)->create(['name' => 'Good Cleaning']);

    $paintingTemplates = PackageTemplate::forService($this->painting)->get();
    $cleaningTemplates = PackageTemplate::forService($this->cleaning)->get();
    $allTemplates = PackageTemplate::forService(null)->get();

    expect($paintingTemplates)->toHaveCount(1);
    expect($cleaningTemplates)->toHaveCount(1);
    expect($allTemplates)->toHaveCount(2);
});

it('PackageTemplate has serviceCategory relationship', function () {
    $template = PackageTemplate::factory()->good()->forService($this->painting)->create();

    expect($template->serviceCategory)->not->toBeNull();
    expect($template->serviceCategory->slug)->toBe('painting');
});

it('EstimatePackage has serviceCategory relationship', function () {
    $package = EstimatePackage::factory()->forService($this->painting)->create();

    expect($package->serviceCategory)->not->toBeNull();
    expect($package->serviceCategory->slug)->toBe('painting');
});
