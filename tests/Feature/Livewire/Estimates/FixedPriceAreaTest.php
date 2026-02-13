<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\NestedAreaTemplate;
use App\Models\PackageTemplate;
use App\Models\User;
use App\Services\EstimatePackageGeneratorService;
use Livewire\Livewire;

describe('Fixed-price areas on show page', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->user->id,
            'estimator_mode' => 'template',
        ]);
    });

    it('displays fixed-price area with correct label instead of sqft', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(250.00)->create([
            'name' => 'Walk-in Closet',
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.show', ['estimate' => $this->estimate])
            ->assertSee('Walk-in Closet')
            ->assertSee('Fixed Price')
            ->assertSee('$250.00');
    });

    it('displays fixed-price area labor hours from estimated_labor_hours', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(200.00)->create([
            'name' => 'Half Bath',
            'estimated_labor_hours' => 2.5,
            'estimated_labor_cost' => 75.00,
            'estimated_material_cost' => 15.00,
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.show', ['estimate' => $this->estimate])
            ->assertSee('Half Bath')
            ->assertSee('2.5 hrs');
    });

    it('displays standard areas with sqft and surfaces', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Living Room',
        ]);
        EstimateAreaSurface::factory()->forArea($area)->wall()->create();

        Livewire::actingAs($this->user)
            ->test('estimates.show', ['estimate' => $this->estimate])
            ->assertSee('Living Room')
            ->assertSee('sqft')
            ->assertSee('1 surfaces');
    });

    it('shows both fixed-price and standard areas together', function () {
        EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Master Bedroom',
            'wall_sqft' => 800,
        ]);
        EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(150.00)->create([
            'name' => 'Small Closet',
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.show', ['estimate' => $this->estimate])
            ->assertSee('Master Bedroom')
            ->assertSee('Small Closet')
            ->assertSee('Fixed Price');
    });
});

describe('Fixed-price areas in package generation', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->user->id,
            'estimator_mode' => 'template',
            'tax_rate' => 0,
        ]);
    });

    it('includes fixed-price areas as EstimateArea package items', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(300.00)->create([
            'name' => 'Closet',
        ]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);

        $generator = app(EstimatePackageGeneratorService::class);
        $packages = $generator->generateFromTemplates($this->estimate);

        expect($packages)->toHaveCount(1);

        $package = $packages->first();
        $areaItems = $package->items()->where('itemable_type', EstimateArea::class)->get();

        expect($areaItems)->toHaveCount(1);
        expect($areaItems->first()->itemable_id)->toBe($area->id);
        expect($areaItems->first()->is_included)->toBeTrue();
        expect($areaItems->first()->customer_approved)->toBeTrue();
    });

    it('includes both surface items and fixed-price area items', function () {
        // Standard area with surfaces
        $standardArea = EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Bedroom',
        ]);
        EstimateAreaSurface::factory()->forArea($standardArea)->wall()->create();
        EstimateAreaSurface::factory()->forArea($standardArea)->ceiling()->create();

        // Fixed-price area
        $fixedArea = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(200.00)->create([
            'name' => 'Pantry',
        ]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);

        $generator = app(EstimatePackageGeneratorService::class);
        $packages = $generator->generateFromTemplates($this->estimate);

        $package = $packages->first();

        $surfaceItems = $package->items()->where('itemable_type', EstimateAreaSurface::class)->count();
        $areaItems = $package->items()->where('itemable_type', EstimateArea::class)->count();

        expect($surfaceItems)->toBe(2);
        expect($areaItems)->toBe(1);
    });

    it('fixed-price area effective price returns correct amount', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(350.00)->create();

        PackageTemplate::factory()->good()->create(['is_active' => true]);

        $generator = app(EstimatePackageGeneratorService::class);
        $packages = $generator->generateFromTemplates($this->estimate);

        $package = $packages->first();
        $areaItem = $package->items()->where('itemable_type', EstimateArea::class)->first();

        expect($areaItem->effective_price)->toBe(350.00);
    });
});

describe('Fixed-price areas in package builder', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->user->id,
            'estimator_mode' => 'template',
            'tax_rate' => 0,
        ]);
    });

    it('loads fixed-price areas in package builder', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(250.00)->create([
            'name' => 'Closet Area',
        ]);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area->id,
            'quantity' => 1,
            'is_included' => true,
            'customer_approved' => true,
            'customer_approved_at' => now(),
            'sort_order' => 0,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('estimates.package-builder', ['estimate' => $this->estimate]);

        $packages = $component->get('packages');
        expect($packages)->toHaveCount(1);

        $areas = $packages[0]['areas'];
        expect($areas)->toHaveCount(1);
        expect($areas[0]['name'])->toBe('Closet Area');
        expect($areas[0]['is_fixed_price'])->toBeTrue();
        expect($areas[0]['included_total'])->toBe(250.00);
    });

    it('displays fixed-price label in package builder UI', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(175.00)->create([
            'name' => 'Half Bath',
        ]);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area->id,
            'quantity' => 1,
            'is_included' => true,
            'customer_approved' => true,
            'customer_approved_at' => now(),
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->assertSee('Half Bath')
            ->assertSee('Fixed Price')
            ->assertSee('$175.00');
    });

    it('can toggle fixed-price area inclusion in package', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(200.00)->create([
            'name' => 'Pantry',
        ]);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();
        $packageItem = EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area->id,
            'quantity' => 1,
            'is_included' => true,
            'customer_approved' => true,
            'customer_approved_at' => now(),
            'sort_order' => 0,
        ]);

        Livewire::actingAs($this->user)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id);

        expect($packageItem->fresh()->is_included)->toBeFalse();
    });
});

describe('Margin-based pricing for fixed-price templates', function () {
    beforeEach(function () {
        CompanySetting::query()->delete();
        CompanySetting::clearInstanceCache();
        CompanySetting::create([
            'company_name' => 'Test Painting Co',
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.35,
            'interior_margin_percent' => 50.00,
            'interior_cleanup_hours_ratio' => 16.00,
            'interior_sundries_cost_per_hour' => 3.30,
            'material_tax_rate' => 7.50,
        ]);
    });

    afterEach(function () {
        CompanySetting::clearInstanceCache();
    });

    it('calculates OTD price from COGS using PBP formula', function () {
        $template = NestedAreaTemplate::factory()->create([
            'scope_type' => 'interior',
            'fixed_price' => 95.00,
            'use_margin_pricing' => true,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
        ]);

        $settings = CompanySetting::getInstance();
        $effectiveRate = $settings->getEffectiveHourlyRateForScope('interior');

        // Use the same methods the model uses internally
        $laborCost = 1.5 * $effectiveRate;
        $cleanupHours = $settings->calculateCleanupHours(1.5, 'interior');
        $cleanupCost = $cleanupHours * $effectiveRate;
        $sundriesCost = $settings->calculateSundriesCost(1.5, 'interior');
        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, 12.00);
        $expectedOTD = $settings->calculateOTDFromCOGS($cogsResult['cogs'], 'interior');

        $calculatedPrice = $template->getCalculatedPrice();

        expect($calculatedPrice)->toBe($expectedOTD);
        expect($calculatedPrice)->toBeGreaterThan(95.00); // Must be higher than manual price
    });

    it('returns calculated price when use_margin_pricing is enabled', function () {
        $template = NestedAreaTemplate::factory()->create([
            'scope_type' => 'interior',
            'fixed_price' => 95.00,
            'use_margin_pricing' => true,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
        ]);

        $effectivePrice = $template->getEffectivePrice();

        expect($effectivePrice)->not->toBe(95.00);
        expect($effectivePrice)->toBe($template->getCalculatedPrice());
    });

    it('returns manual fixed_price when use_margin_pricing is disabled', function () {
        $template = NestedAreaTemplate::factory()->create([
            'scope_type' => 'interior',
            'fixed_price' => 95.00,
            'use_margin_pricing' => false,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
        ]);

        expect($template->getEffectivePrice())->toBe(95.00);
    });

    it('falls back to manual price when no labor data exists', function () {
        $template = NestedAreaTemplate::factory()->create([
            'fixed_price' => 150.00,
            'use_margin_pricing' => true,
            'estimated_hours' => 0,
            'material_cost' => 0,
        ]);

        expect($template->getEffectivePrice())->toBe(150.00);
    });

    it('formatted_price attribute uses effective price', function () {
        $template = NestedAreaTemplate::factory()->create([
            'scope_type' => 'interior',
            'fixed_price' => 95.00,
            'use_margin_pricing' => true,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
        ]);

        $calculatedPrice = $template->getCalculatedPrice();
        $expected = '$'.number_format($calculatedPrice, 2);

        expect($template->formatted_price)->toBe($expected);
    });
});
