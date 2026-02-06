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
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
    });

    it('calculates area subtotals with scope-specific margin', function () {
        // Set up company settings with known interior margin
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_margin_percent' => 50.00, // COGS / 0.50 = COGS × 2
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'project_type' => 'interior',
        ]);

        // Create an interior area with a surface (COGS = $150)
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'scope_type' => 'interior',
        ]);

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
            'total' => 150, // COGS: 50 labor + 100 materials
            'is_included' => true,
            'sort_order' => 0,
        ]);

        $estimate->refresh();
        $estimate->load('areas.surfaces');
        $estimate->calculateTotals();

        // Area COGS = $150
        // With 50% interior margin: customer price calculated via getCustomerPrice()
        // areas_subtotal should be > COGS due to margin
        expect((float) $estimate->areas_subtotal)->toBeGreaterThan(150.00);
    });

    it('items are not margin-adjusted - they use user-set prices', function () {
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_margin_percent' => 50.00,
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        // Create an item with $100 price - this IS the customer price
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        $estimate->refresh();
        $estimate->load('areas.surfaces');
        $estimate->calculateTotals();

        // Items are user-priced - no margin applied at estimate level
        expect((float) $estimate->items_subtotal)->toBe(100.00);
        expect((float) $estimate->subtotal)->toBe(100.00);
        expect((float) $estimate->total)->toBe(100.00);
    });

    it('applies tax after subtotal', function () {
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_margin_percent' => 50.00,
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

        $estimate->refresh();
        $estimate->load('areas.surfaces');
        $estimate->calculateTotals();

        // Items subtotal: 100 (no margin on items)
        // Tax: 100 * 10% = 10
        // Total: 100 + 10 = 110
        expect((float) $estimate->subtotal)->toBe(100.00);
        expect((float) $estimate->tax_amount)->toBe(10.00);
        expect((float) $estimate->total)->toBe(110.00);
    });

    it('includes items and areas in subtotal', function () {
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_margin_percent' => 100, // 100% = COGS / 1.0 = no markup
        ]);

        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'project_type' => 'interior',
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        // Create area with a surface (COGS = $150)
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'scope_type' => 'interior',
        ]);

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
            'total' => 150,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        $estimate->refresh();
        $estimate->load('areas.surfaces');
        $estimate->calculateTotals();

        // items_subtotal = 100, areas_subtotal includes margin from getCustomerPrice()
        expect((float) $estimate->items_subtotal)->toBe(100.00);
        // With 100% margin (COGS/1.0 = COGS), area customer price = COGS
        // But getFullCOGS() adds cleanup/sundries on top of surface COGS
        // So areas_subtotal >= 150
        expect((float) $estimate->areas_subtotal)->toBeGreaterThanOrEqual(150.00);
        expect((float) $estimate->subtotal)->toBeGreaterThanOrEqual(250.00);
    });

    it('returns raw COGS via getCOGS method', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'items_subtotal' => 100,
            'options_subtotal' => 25,
        ]);

        // getCOGS sums actual area surfaces + stored items_subtotal + options_subtotal
        // With no areas, COGS = 0 + 100 + 25
        expect($estimate->getCOGS())->toBe(125.00);
    });

    it('calculates margin amount correctly', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'items_subtotal' => 100,
            'areas_subtotal' => 0,
            'options_subtotal' => 0,
            'subtotal' => 200, // Manually set higher than COGS for testing
        ]);

        // getMarginAmount = subtotal - getCOGS() = 200 - 100 = 100
        expect($estimate->getMarginAmount())->toBe(100.00);
    });

    it('calculates margin percent as gross profit percentage', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->user->id,
            'items_subtotal' => 100,
            'areas_subtotal' => 0,
            'options_subtotal' => 0,
            'subtotal' => 200,
        ]);

        // GP% = (1 - (COGS / subtotal)) * 100 = (1 - 100/200) * 100 = 50%
        expect($estimate->getMarginPercent())->toBe(50.00);
    });
});
