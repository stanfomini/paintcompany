<?php

use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Services\ProductionRateService;

describe('ProductionRateService calculatePrice', function () {
    beforeEach(function () {
        // Set up deterministic company settings
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 25.00,
            'exterior_hourly_wage' => 30.00,
            'payroll_burden_multiplier' => 1.40,
        ]);
    });

    it('returns correct calculation values with default multipliers', function () {
        $service = new ProductionRateService;

        // 1000 sqft at 250 sqft/hour production rate, 2 coats
        $result = $service->calculatePrice(
            quantity: 1000,
            productionRate: 250,
            coats: 2,
            scopeType: 'interior'
        );

        // Labor hours: (1000 / 250) * 1.8 coat multiplier = 7.2 hours
        // Effective rate: $25 * 1.40 = $35/hr
        // Labor cost: 7.2 * 35 = 252

        expect($result['labor_hours'])->toBe(7.2);
        expect($result['labor_cost'])->toBe(252.0);
        expect($result['coat_multiplier'])->toBe(1.8);
        expect($result['total'])->toBe(252.0);
    });

    it('returns correct values for single coat', function () {
        $service = new ProductionRateService;

        $result = $service->calculatePrice(
            quantity: 500,
            productionRate: 100,
            coats: 1,
            scopeType: 'interior'
        );

        // Labor hours: (500 / 100) * 1.0 = 5 hours
        // Effective rate: $25 * 1.40 = $35/hr
        // Labor cost: 5 * 35 = 175

        expect($result['labor_hours'])->toBe(5.0);
        expect($result['labor_cost'])->toBe(175.0);
        expect($result['coat_multiplier'])->toBe(1.0);
        expect($result['total'])->toBe(175.0);
    });

    it('returns correct values for three coats', function () {
        $service = new ProductionRateService;

        $result = $service->calculatePrice(
            quantity: 100,
            productionRate: 50,
            coats: 3,
            scopeType: 'interior'
        );

        // Labor hours: (100 / 50) * 2.5 = 5 hours
        // Effective rate: $25 * 1.40 = $35/hr
        // Labor cost: 5 * 35 = 175

        expect($result['labor_hours'])->toBe(5.0);
        expect($result['labor_cost'])->toBe(175.0);
        expect($result['coat_multiplier'])->toBe(2.5);
        expect($result['total'])->toBe(175.0);
    });

    it('uses exterior rates when scope type is exterior', function () {
        $service = new ProductionRateService;

        $result = $service->calculatePrice(
            quantity: 100,
            productionRate: 100,
            coats: 1,
            scopeType: 'exterior'
        );

        // Labor hours: (100 / 100) * 1.0 = 1 hour
        // Effective rate: $30 * 1.40 = $42/hr
        // Labor cost: 1 * 42 = 42

        expect($result['labor_hours'])->toBe(1.0);
        expect($result['labor_cost'])->toBe(42.0);
        expect($result['total'])->toBe(42.0);
    });

    it('rounds values to two decimal places', function () {
        $service = new ProductionRateService;

        $result = $service->calculatePrice(
            quantity: 100,
            productionRate: 33,
            coats: 1,
            scopeType: 'interior'
        );

        // Labor hours: 100 / 33 = 3.03030303...
        // Should be rounded to 3.03

        expect($result['labor_hours'])->toBe(3.03);
    });
});

describe('ProductionRateService calculatePriceFromRate', function () {
    beforeEach(function () {
        // Set up deterministic company settings
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 25.00,
            'exterior_hourly_wage' => 30.00,
            'payroll_burden_multiplier' => 1.40,
        ]);
    });

    it('extracts values correctly from ProductionRate model', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200.00,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
        ]);

        $service = new ProductionRateService;
        $result = $service->calculatePriceFromRate($rate, 400);

        // Labor hours: (400 / 200) * 1.8 = 3.6 hours
        // Effective rate: $25 * 1.40 = $35/hr
        // Labor cost: 3.6 * 35 = 126

        expect($result['labor_hours'])->toBe(3.6);
        expect($result['labor_cost'])->toBe(126.0);
        expect($result['coat_multiplier'])->toBe(1.8);
        expect($result['total'])->toBe(126.0);
    });

    it('uses provided coats instead of default when specified', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 100.00,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
        ]);

        $service = new ProductionRateService;
        $result = $service->calculatePriceFromRate($rate, 100, 3);

        // Uses 3 coats instead of default 2
        expect($result['coat_multiplier'])->toBe(2.5);
    });

    it('uses rate default_coats when coats not specified', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 100.00,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 1,
        ]);

        $service = new ProductionRateService;
        $result = $service->calculatePriceFromRate($rate, 100);

        // Uses default 1 coat from rate
        expect($result['coat_multiplier'])->toBe(1.0);
    });
});

describe('ProductionRateService getCoatMultiplier', function () {
    it('returns correct default multipliers', function () {
        $service = new ProductionRateService;

        expect($service->getCoatMultiplier(1))->toBe(1.0);
        expect($service->getCoatMultiplier(2))->toBe(1.8);
        expect($service->getCoatMultiplier(3))->toBe(2.5);
    });

    it('returns 3-coat multiplier for coats greater than 3', function () {
        $service = new ProductionRateService;

        expect($service->getCoatMultiplier(4))->toBe(2.5);
        expect($service->getCoatMultiplier(5))->toBe(2.5);
    });

    it('uses custom multipliers when provided', function () {
        $service = new ProductionRateService;

        $customMultipliers = [
            1 => 1.0,
            2 => 2.0,
            3 => 3.0,
        ];

        expect($service->getCoatMultiplier(1, $customMultipliers))->toBe(1.0);
        expect($service->getCoatMultiplier(2, $customMultipliers))->toBe(2.0);
        expect($service->getCoatMultiplier(3, $customMultipliers))->toBe(3.0);
    });

    it('uses custom 3-coat multiplier for coats greater than 3 when custom multipliers provided', function () {
        $service = new ProductionRateService;

        $customMultipliers = [
            1 => 1.0,
            2 => 1.5,
            3 => 2.0,
        ];

        expect($service->getCoatMultiplier(4, $customMultipliers))->toBe(2.0);
        expect($service->getCoatMultiplier(5, $customMultipliers))->toBe(2.0);
    });

    it('falls back to default when custom multipliers missing key', function () {
        $service = new ProductionRateService;

        $incompleteMultipliers = [
            2 => 2.0,
        ];

        // Falls back to default for 1 coat
        expect($service->getCoatMultiplier(1, $incompleteMultipliers))->toBe(1.0);
    });
});

describe('ProductionRateService calculateWallSqft', function () {
    it('calculates wall square footage correctly', function () {
        $service = new ProductionRateService;

        // Formula: 2 * (length + width) * height
        // Room 12x10 with 8ft ceilings: 2 * (12 + 10) * 8 = 2 * 22 * 8 = 352
        $result = $service->calculateWallSqft(12, 10, 8);

        expect($result)->toBe(352.0);
    });

    it('handles square rooms correctly', function () {
        $service = new ProductionRateService;

        // Square room 10x10 with 9ft ceilings: 2 * (10 + 10) * 9 = 2 * 20 * 9 = 360
        $result = $service->calculateWallSqft(10, 10, 9);

        expect($result)->toBe(360.0);
    });

    it('handles decimal dimensions', function () {
        $service = new ProductionRateService;

        // Room 12.5x8.5 with 9ft ceilings: 2 * (12.5 + 8.5) * 9 = 2 * 21 * 9 = 378
        $result = $service->calculateWallSqft(12.5, 8.5, 9);

        expect($result)->toBe(378.0);
    });
});

describe('ProductionRateService calculateCeilingSqft', function () {
    it('calculates ceiling square footage correctly', function () {
        $service = new ProductionRateService;

        // Formula: length * width
        // Room 12x10: 12 * 10 = 120
        $result = $service->calculateCeilingSqft(12, 10);

        expect($result)->toBe(120.0);
    });

    it('handles square rooms correctly', function () {
        $service = new ProductionRateService;

        // Square room 15x15: 15 * 15 = 225
        $result = $service->calculateCeilingSqft(15, 15);

        expect($result)->toBe(225.0);
    });

    it('handles decimal dimensions', function () {
        $service = new ProductionRateService;

        // Room 10.5x8.5: 10.5 * 8.5 = 89.25
        $result = $service->calculateCeilingSqft(10.5, 8.5);

        expect($result)->toBe(89.25);
    });
});

describe('ProductionRateService seedDefaultRates', function () {
    it('creates categories and rates', function () {
        $service = new ProductionRateService;
        $service->seedDefaultRates();

        // Should create 3 categories
        expect(ProductionRateCategory::count())->toBe(3);

        // Check categories were created
        expect(ProductionRateCategory::where('type', 'interior')->exists())->toBeTrue();
        expect(ProductionRateCategory::where('type', 'exterior')->exists())->toBeTrue();
        expect(ProductionRateCategory::where('type', 'specialty')->exists())->toBeTrue();

        // Should create rates (47 interior + 80 exterior + 2 specialty = 129)
        expect(ProductionRate::count())->toBeGreaterThan(100);
    });

    it('creates interior category with rates', function () {
        $service = new ProductionRateService;
        $service->seedDefaultRates();

        $interiorCategory = ProductionRateCategory::where('name', 'Interior Painting')->first();

        expect($interiorCategory)->not->toBeNull();
        expect($interiorCategory->type)->toBe('interior');
        expect($interiorCategory->rates->count())->toBeGreaterThan(40);
    });

    it('creates exterior category with rates', function () {
        $service = new ProductionRateService;
        $service->seedDefaultRates();

        $exteriorCategory = ProductionRateCategory::where('name', 'Exterior Painting')->first();

        expect($exteriorCategory)->not->toBeNull();
        expect($exteriorCategory->type)->toBe('exterior');
        expect($exteriorCategory->rates->count())->toBeGreaterThan(70);
    });

    it('creates specialty category with correct rates', function () {
        $service = new ProductionRateService;
        $service->seedDefaultRates();

        $specialtyCategory = ProductionRateCategory::where('name', 'Specialty Services')->first();

        expect($specialtyCategory)->not->toBeNull();
        expect($specialtyCategory->type)->toBe('specialty');
        expect($specialtyCategory->rates)->toHaveCount(2);
    });

    it('does not duplicate when run multiple times', function () {
        $service = new ProductionRateService;
        $service->seedDefaultRates();
        $firstCount = ProductionRate::count();

        $service->seedDefaultRates();

        // Should not duplicate
        expect(ProductionRateCategory::count())->toBe(3);
        expect(ProductionRate::count())->toBe($firstCount);
    });

    it('creates rates with correct values', function () {
        $service = new ProductionRateService;
        $service->seedDefaultRates();

        $wallPainting = ProductionRate::where('name', 'Wall Painting')->first();

        expect($wallPainting)->not->toBeNull();
        expect((float) $wallPainting->production_rate)->toBe(300.00);
        expect($wallPainting->default_coats)->toBe(2);
        expect($wallPainting->unit_type)->toBe('sqft');
    });
});

describe('ProductionRateService testCalculator', function () {
    beforeEach(function () {
        // Set up deterministic company settings
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 25.00,
            'exterior_hourly_wage' => 30.00,
            'payroll_burden_multiplier' => 1.40,
        ]);
    });

    it('returns calculation for valid rate', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200.00,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
        ]);

        $service = new ProductionRateService;
        $result = $service->testCalculator(1000, $rate->id, 2);

        expect($result['error'])->toBeNull();
        expect($result['rate'])->not->toBeNull();
        expect($result['sqft'])->toBe(1000.0);
        expect($result['coats'])->toBe(2);
        // Labor hours: (1000/200) * 1.8 = 9 hours
        expect($result['labor_hours'])->toBe(9.0);
        expect($result['total'])->toBeGreaterThan(0);
    });

    it('returns error for invalid rate id', function () {
        $service = new ProductionRateService;
        $result = $service->testCalculator(1000, 99999, 2);

        expect($result['error'])->toBe('Production rate not found.');
        expect($result['rate'])->toBeNull();
        expect($result['total'])->toBe(0);
    });
});
