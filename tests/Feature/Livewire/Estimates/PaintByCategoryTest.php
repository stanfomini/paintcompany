<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\User;

beforeEach(function () {
    // Create an internal user
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);

    // Create a paint brand and products for testing
    $this->brand = PaintBrand::factory()->create([
        'name' => 'Test Brand',
        'is_active' => true,
    ]);

    $this->wallPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $this->brand->id,
        'name' => 'Wall Paint',
        'scope_type' => PaintProduct::SCOPE_INTERIOR,
        'price_per_gallon' => 45.00,
        'coverage_sqft_brush_roll' => 400,
        'is_active' => true,
    ]);

    $this->ceilingPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $this->brand->id,
        'name' => 'Ceiling Paint',
        'scope_type' => PaintProduct::SCOPE_INTERIOR,
        'price_per_gallon' => 35.00,
        'coverage_sqft_brush_roll' => 350,
        'is_active' => true,
    ]);

    $this->trimPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $this->brand->id,
        'name' => 'Trim Paint',
        'scope_type' => PaintProduct::SCOPE_INTERIOR,
        'price_per_gallon' => 55.00,
        'coverage_sqft_brush_roll' => 300,
        'is_active' => true,
    ]);

    $this->doorPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $this->brand->id,
        'name' => 'Door Paint',
        'scope_type' => PaintProduct::SCOPE_INTERIOR,
        'price_per_gallon' => 60.00,
        'coverage_sqft_brush_roll' => 350,
        'is_active' => true,
    ]);

    // Create a customer and deal
    $this->customer = Customer::factory()->create();
    $this->deal = Deal::factory()->create([
        'customer_id' => $this->customer->id,
        'created_by' => $this->user->id,
    ]);
});

describe('CompanySetting paint by category', function () {
    it('can set and get default paint for a category', function () {
        $settings = CompanySetting::getInstance();

        // Set paint for walls category
        $settings->setDefaultPaintForCategory('walls', 'interior', $this->wallPaint->id);

        expect($settings->getDefaultPaintForCategory('walls', 'interior'))
            ->toBe($this->wallPaint->id);
    });

    it('can set multiple category defaults', function () {
        $settings = CompanySetting::getInstance();

        $settings->setDefaultPaintForCategory('walls', 'interior', $this->wallPaint->id);
        $settings->setDefaultPaintForCategory('ceiling', 'interior', $this->ceilingPaint->id);
        $settings->setDefaultPaintForCategory('trim', 'interior', $this->trimPaint->id);

        expect($settings->getPaintDefaultsForScope('interior'))->toHaveCount(3)
            ->and($settings->getDefaultPaintForCategory('walls', 'interior'))->toBe($this->wallPaint->id)
            ->and($settings->getDefaultPaintForCategory('ceiling', 'interior'))->toBe($this->ceilingPaint->id)
            ->and($settings->getDefaultPaintForCategory('trim', 'interior'))->toBe($this->trimPaint->id);
    });

    it('can remove category default by setting null', function () {
        $settings = CompanySetting::getInstance();

        $settings->setDefaultPaintForCategory('walls', 'interior', $this->wallPaint->id);
        expect($settings->getDefaultPaintForCategory('walls', 'interior'))->toBe($this->wallPaint->id);

        $settings->setDefaultPaintForCategory('walls', 'interior', null);
        expect($settings->getDefaultPaintForCategory('walls', 'interior'))->toBeNull();
    });

    it('returns correct surface categories for interior scope', function () {
        $categories = CompanySetting::getSurfaceCategoriesForScope('interior');

        expect($categories)->toHaveKeys(['walls', 'ceiling', 'trim', 'doors']);
    });

    it('returns correct surface categories for exterior scope', function () {
        $categories = CompanySetting::getSurfaceCategoriesForScope('exterior');

        expect($categories)->toHaveKeys(['body', 'trim', 'fascia', 'eaves', 'doors']);
    });
});

describe('Estimate paint by category', function () {
    it('can set paint override for category', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
        ]);

        $estimate->setPaintForCategory('walls', $this->wallPaint->id);
        $estimate->save();

        expect($estimate->fresh()->getPaintProductIdForCategory('walls'))
            ->toBe($this->wallPaint->id);
    });

    it('resolves paint with estimate override over company default', function () {
        // Set company default
        $settings = CompanySetting::getInstance();
        $settings->setDefaultPaintForCategory('walls', 'interior', $this->ceilingPaint->id);

        // Create estimate with override
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
        ]);
        $estimate->setPaintForCategory('walls', $this->wallPaint->id);
        $estimate->save();

        // Should use estimate override, not company default
        $resolvedPaint = $estimate->fresh()->resolvePaintForCategory('walls');

        expect($resolvedPaint->id)->toBe($this->wallPaint->id);
    });

    it('falls back to company default when no estimate override', function () {
        // Set company default
        $settings = CompanySetting::getInstance();
        $settings->setDefaultPaintForCategory('walls', 'interior', $this->wallPaint->id);

        // Create estimate without override
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
        ]);

        // Should use company default
        expect($estimate->getPaintProductIdForCategory('walls'))
            ->toBe($this->wallPaint->id);
    });

    it('returns null when no override or default', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
        ]);

        expect($estimate->getPaintProductIdForCategory('walls'))->toBeNull();
    });

    it('counts paint overrides correctly', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
        ]);

        expect($estimate->getPaintOverrideCount())->toBe(0);

        $estimate->setPaintForCategory('walls', $this->wallPaint->id);
        $estimate->setPaintForCategory('ceiling', $this->ceilingPaint->id);
        $estimate->save();

        expect($estimate->fresh()->getPaintOverrideCount())->toBe(2);
    });
});

describe('EstimateAreaSurface category resolution', function () {
    it('maps wall surface type to walls category', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
        ]);

        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'surface_type' => 'sqft',
        ]);

        expect($surface->getSurfaceCategory())->toBe('walls');
    });

    it('maps ceiling surface to ceiling category', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'name' => 'Ceiling',
            'surface_type' => 'sqft',
        ]);

        expect($surface->getSurfaceCategory())->toBe('ceiling');
    });

    it('maps baseboard surface to trim category', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'name' => 'Baseboards',
            'surface_type' => 'lnft',
        ]);

        expect($surface->getSurfaceCategory())->toBe('trim');
    });

    it('maps door surface to doors category', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'name' => 'Interior Door',
            'surface_type' => 'unit',
        ]);

        expect($surface->getSurfaceCategory())->toBe('doors');
    });

    it('uses category paint in effective paint resolution', function () {
        // Set category paint on estimate
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
        ]);
        $estimate->setPaintForCategory('walls', $this->wallPaint->id);
        $estimate->save();

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        // Create wall surface without explicit paint_product_id
        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'surface_type' => 'sqft',
            'paint_product_id' => null,
        ]);

        // Should resolve to the category paint
        $effectivePaint = $surface->getEffectivePaintProduct();

        expect($effectivePaint)->not->toBeNull()
            ->and($effectivePaint->id)->toBe($this->wallPaint->id);
    });

    it('surface override takes priority over category paint', function () {
        // Set category paint on estimate
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
        ]);
        $estimate->setPaintForCategory('walls', $this->wallPaint->id);
        $estimate->save();

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        // Create wall surface WITH explicit paint_product_id override
        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'surface_type' => 'sqft',
            'paint_product_id' => $this->ceilingPaint->id, // Override with different paint
        ]);

        // Should use surface override, not category paint
        $effectivePaint = $surface->getEffectivePaintProduct();

        expect($effectivePaint)->not->toBeNull()
            ->and($effectivePaint->id)->toBe($this->ceilingPaint->id);
    });
});

describe('Estimate paint_products_by_category persistence', function () {
    it('persists paint by category JSON correctly', function () {
        $estimate = Estimate::factory()->create([
            'deal_id' => $this->deal->id,
            'created_by' => $this->user->id,
            'project_type' => 'interior',
            'paint_products_by_category' => [
                'interior' => [
                    'walls' => $this->wallPaint->id,
                    'ceiling' => $this->ceilingPaint->id,
                ],
            ],
        ]);

        $estimate->refresh();

        expect($estimate->paint_products_by_category)->toBeArray()
            ->and($estimate->paint_products_by_category['interior']['walls'])->toBe($this->wallPaint->id)
            ->and($estimate->paint_products_by_category['interior']['ceiling'])->toBe($this->ceilingPaint->id);
    });
});
