<?php

use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;

describe('ProductionRate model', function () {
    it('can create a rate', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'name' => 'Wall Painting',
            'description' => 'Standard wall painting',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 300.00,
            'hourly_labor_rate' => 45.00,
            'material_cost_per_unit' => 0.15,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        expect($rate)->toBeInstanceOf(ProductionRate::class);
        expect($rate->name)->toBe('Wall Painting');
        expect($rate->description)->toBe('Standard wall painting');
        expect($rate->unit_type)->toBe('sqft');
        expect((float) $rate->production_rate)->toBe(300.00);
        expect((float) $rate->hourly_labor_rate)->toBe(45.00);
        expect((float) $rate->material_cost_per_unit)->toBe(0.15);
        expect($rate->coat_multipliers)->toEqual(['1' => 1.0, '2' => 1.8, '3' => 2.5]);
        expect($rate->default_coats)->toBe(2);
        expect($rate->is_active)->toBeTrue();
        expect($rate->sort_order)->toBe(1);
    });

    it('can update a rate', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'name' => 'Original Name',
            'production_rate' => 200.00,
        ]);

        $rate->update([
            'name' => 'Updated Name',
            'production_rate' => 350.00,
        ]);

        expect($rate->fresh()->name)->toBe('Updated Name');
        expect((float) $rate->fresh()->production_rate)->toBe(350.00);
    });

    it('can delete a rate', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create();
        $rateId = $rate->id;

        $rate->delete();

        expect(ProductionRate::find($rateId))->toBeNull();
    });

    it('belongs to a category', function () {
        $category = ProductionRateCategory::factory()->create([
            'name' => 'Test Category',
        ]);
        $rate = ProductionRate::factory()->forCategory($category)->create();

        expect($rate->category)->toBeInstanceOf(ProductionRateCategory::class);
        expect($rate->category->name)->toBe('Test Category');
    });
});

describe('ProductionRate calculatePrice method', function () {
    it('calculates price correctly with default coats', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200.00,
            'hourly_labor_rate' => 50.00,
            'material_cost_per_unit' => 0.20,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
        ]);

        // 500 sqft with default 2 coats
        // Labor cost: (500 / 200) * 50 = 2.5 * 50 = 125
        // Material cost: 500 * 0.20 = 100
        // Coat multiplier: 1.8
        // Total: (125 + 100) * 1.8 = 405

        $price = $rate->calculatePrice(500);

        expect($price)->toBe(405.0);
    });

    it('calculates price correctly with specified coats', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 100.00,
            'hourly_labor_rate' => 60.00,
            'material_cost_per_unit' => 0.30,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
        ]);

        // 200 sqft with 3 coats
        // Labor cost: (200 / 100) * 60 = 2 * 60 = 120
        // Material cost: 200 * 0.30 = 60
        // Coat multiplier: 2.5
        // Total: (120 + 60) * 2.5 = 450

        $price = $rate->calculatePrice(200, 3);

        expect($price)->toBe(450.0);
    });

    it('uses 1 coat when coats not specified and default_coats is 1', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 100.00,
            'hourly_labor_rate' => 50.00,
            'material_cost_per_unit' => 0.10,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 1,
        ]);

        // 100 sqft with 1 coat (from default_coats)
        // Labor cost: (100 / 100) * 50 = 1 * 50 = 50
        // Material cost: 100 * 0.10 = 10
        // Coat multiplier: 1.0
        // Total: (50 + 10) * 1.0 = 60

        $price = $rate->calculatePrice(100);

        expect($price)->toBe(60.0);
    });
});

describe('ProductionRate getCoatMultiplier method', function () {
    it('returns correct multiplier from coat_multipliers', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'coat_multipliers' => ['1' => 1.0, '2' => 2.0, '3' => 3.0],
        ]);

        expect($rate->getCoatMultiplier(1))->toBe(1.0);
        expect($rate->getCoatMultiplier(2))->toBe(2.0);
        expect($rate->getCoatMultiplier(3))->toBe(3.0);
    });

    it('returns coat number when multiplier not found', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
        ]);

        // When multiplier for 4 coats not found, returns 4 (the coat number)
        expect($rate->getCoatMultiplier(4))->toBe(4.0);
    });

    it('handles null coat_multipliers', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'coat_multipliers' => null,
        ]);

        // When coat_multipliers is null, returns the coat number
        expect($rate->getCoatMultiplier(2))->toBe(2.0);
    });
});

describe('ProductionRate active scope', function () {
    it('filters to only active rates', function () {
        $category = ProductionRateCategory::factory()->create();
        ProductionRate::factory()->forCategory($category)->count(3)->create(['is_active' => true]);
        ProductionRate::factory()->forCategory($category)->inactive()->count(2)->create();

        $activeRates = ProductionRate::active()->get();

        expect($activeRates)->toHaveCount(3);
        expect($activeRates->every(fn ($rate) => $rate->is_active))->toBeTrue();
    });

    it('returns empty collection when no active rates exist', function () {
        $category = ProductionRateCategory::factory()->create();
        ProductionRate::factory()->forCategory($category)->inactive()->count(3)->create();

        $activeRates = ProductionRate::active()->get();

        expect($activeRates)->toHaveCount(0);
    });
});

describe('ProductionRate unit type constants', function () {
    it('has correct unit type constants', function () {
        expect(ProductionRate::UNIT_TYPE_SQFT)->toBe('sqft');
        expect(ProductionRate::UNIT_TYPE_LNFT)->toBe('lnft');
        expect(ProductionRate::UNIT_TYPE_UNIT)->toBe('unit');
        expect(ProductionRate::UNIT_TYPE_HOUR)->toBe('hour');
    });

    it('has unit types array with all types', function () {
        expect(ProductionRate::UNIT_TYPES)->toBe([
            'sqft',
            'lnft',
            'unit',
            'hour',
        ]);
    });
});

describe('ProductionRate unit type label attribute', function () {
    it('returns Square Feet for sqft', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
        ]);

        expect($rate->unit_type_label)->toBe('Square Feet');
    });

    it('returns Linear Feet for lnft', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'unit_type' => ProductionRate::UNIT_TYPE_LNFT,
        ]);

        expect($rate->unit_type_label)->toBe('Linear Feet');
    });

    it('returns Unit for unit', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'unit_type' => ProductionRate::UNIT_TYPE_UNIT,
        ]);

        expect($rate->unit_type_label)->toBe('Unit');
    });

    it('returns Hour for hour', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'unit_type' => ProductionRate::UNIT_TYPE_HOUR,
        ]);

        expect($rate->unit_type_label)->toBe('Hour');
    });

    it('returns raw type for unknown type', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'unit_type' => 'unknown',
        ]);

        expect($rate->unit_type_label)->toBe('unknown');
    });
});

describe('ProductionRate casts', function () {
    it('casts production_rate as decimal', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 123.456,
        ]);

        // Decimal cast with 2 decimal places
        expect($rate->production_rate)->toBe('123.46');
    });

    it('casts coat_multipliers as array', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
        ]);

        expect($rate->coat_multipliers)->toBeArray();
        expect((float) $rate->coat_multipliers['1'])->toBe(1.0);
    });

    it('casts is_active as boolean', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'is_active' => 1,
        ]);

        expect($rate->is_active)->toBeBool();
        expect($rate->is_active)->toBeTrue();
    });

    it('casts default_coats as integer', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'default_coats' => '2',
        ]);

        expect($rate->default_coats)->toBeInt();
        expect($rate->default_coats)->toBe(2);
    });

    it('casts sort_order as integer', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'sort_order' => '5',
        ]);

        expect($rate->sort_order)->toBeInt();
        expect($rate->sort_order)->toBe(5);
    });
});
