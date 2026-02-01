<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Estimate margin calculations', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->create();
        $this->deal = Deal::factory()->create();
    });

    it('calculates totals with company margins applied', function () {
        // Set up company settings with known margins
        CompanySetting::create([
            'company_name' => 'Test Company',
            'net_profit_margin_percent' => 20.00, // +20%
            'overhead_percent' => 10.00, // +10%
            'markup_percent' => 0, // No additional markup
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        // Create an item with $100 base cost
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        $estimate->calculateTotals(applyMargins: true);

        // Base: 100
        // With 20% profit: 100 * 1.20 = 120
        // With 10% overhead: 120 * 1.10 = 132
        expect((float) $estimate->subtotal)->toBe(132.00);
        expect((float) $estimate->total)->toBe(132.00);
    });

    it('calculates totals without margins when disabled', function () {
        CompanySetting::create([
            'company_name' => 'Test Company',
            'net_profit_margin_percent' => 30.00,
            'overhead_percent' => 10.00,
            'markup_percent' => 50.00,
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        $estimate->calculateTotals(applyMargins: false);

        // Should be raw base cost without margins
        expect((float) $estimate->subtotal)->toBe(100.00);
        expect((float) $estimate->total)->toBe(100.00);
    });

    it('applies tax after margins', function () {
        CompanySetting::create([
            'company_name' => 'Test Company',
            'net_profit_margin_percent' => 0,
            'overhead_percent' => 0,
            'markup_percent' => 100.00, // Double the price
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 10, // 10% tax
            'discount_amount' => 0,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        $estimate->calculateTotals(applyMargins: true);

        // Base: 100, with 100% markup = 200
        // Tax: 200 * 10% = 20
        // Total: 200 + 20 = 220
        expect((float) $estimate->subtotal)->toBe(200.00);
        expect((float) $estimate->tax_amount)->toBe(20.00);
        expect((float) $estimate->total)->toBe(220.00);
    });

    it('includes items and areas in base cost calculation', function () {
        CompanySetting::create([
            'company_name' => 'Test Company',
            'net_profit_margin_percent' => 0,
            'overhead_percent' => 0,
            'markup_percent' => 0,
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        // Create area with a surface that has a known total
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        // Create surface with explicit values - override all calculated fields
        EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Test Surface',
            'surface_type' => 'wall',
            'quantity' => 100,
            'unit' => 'sqft',
            'coats' => 1,
            'production_rate_value' => 100,
            'hourly_labor_rate' => 50,
            'material_cost_per_unit' => 1.00,
            'labor_hours' => 1,
            'labor_cost' => 50,
            'material_cost' => 100,
            'total' => 150, // 50 labor + 100 materials
            'is_included' => true,
            'sort_order' => 0,
        ]);

        $estimate->refresh();
        $estimate->calculateTotals(applyMargins: false);

        expect((float) $estimate->items_subtotal)->toBe(100.00);
        expect((float) $estimate->areas_subtotal)->toBe(150.00);
        expect((float) $estimate->subtotal)->toBe(250.00);
    });

    it('returns base cost via getBaseCost method', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'items_subtotal' => 100,
            'areas_subtotal' => 50,
            'options_subtotal' => 25,
        ]);

        expect($estimate->getBaseCost())->toBe(175.00);
    });

    it('calculates margin amount correctly', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'items_subtotal' => 100,
            'areas_subtotal' => 0,
            'options_subtotal' => 0,
            'subtotal' => 150, // After margins applied
        ]);

        expect($estimate->getMarginAmount())->toBe(50.00);
    });

    it('uses default company margins when settings exist', function () {
        // Create settings with defaults
        $settings = CompanySetting::getInstance();

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        $estimate->calculateTotals(applyMargins: true);

        // Default margins: 30% profit, 12% overhead, 45% markup
        // Base: 100
        // With 30% profit: 100 * 1.30 = 130
        // With 12% overhead: 130 * 1.12 = 145.60
        // With 45% markup: 145.60 * 1.45 = 211.12
        expect((float) $estimate->subtotal)->toBe(211.12);
    });
});
