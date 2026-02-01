<?php

use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use Database\Seeders\SpreadsheetProductionRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the seeder before each test
    $this->seed(SpreadsheetProductionRateSeeder::class);
});

describe('SpreadsheetProductionRateSeeder', function () {
    it('seeds interior production rates', function () {
        $interiorCount = ProductionRate::where('scope', 'interior')->count();
        expect($interiorCount)->toBeGreaterThan(0);

        // Check categories exist
        $categories = ProductionRate::where('scope', 'interior')
            ->distinct('surface_category')
            ->pluck('surface_category');

        expect($categories)->toContain('prep');
        expect($categories)->toContain('walls');
        expect($categories)->toContain('ceiling');
        expect($categories)->toContain('baseboard');
        expect($categories)->toContain('doors');
    });

    it('seeds exterior production rates', function () {
        $exteriorCount = ProductionRate::where('scope', 'exterior')->count();
        expect($exteriorCount)->toBeGreaterThan(0);

        // Check categories exist
        $categories = ProductionRate::where('scope', 'exterior')
            ->distinct('surface_category')
            ->pluck('surface_category');

        expect($categories)->toContain('prep');
        expect($categories)->toContain('body');
        expect($categories)->toContain('eaves');
        expect($categories)->toContain('fascia');
        expect($categories)->toContain('windows');
        expect($categories)->toContain('trim');
        expect($categories)->toContain('exterior_doors');
        expect($categories)->toContain('wood_replacement');
    });

    it('creates production rate categories', function () {
        $interior = ProductionRateCategory::where('type', 'interior')->first();
        expect($interior)->not->toBeNull();
        expect($interior->name)->toBe('Interior Painting');

        $exterior = ProductionRateCategory::where('type', 'exterior')->first();
        expect($exterior)->not->toBeNull();
        expect($exterior->name)->toBe('Exterior Painting');
    });
});

describe('Interior Wall Types (spreadsheet exact values)', function () {
    it('has Textured Walls same color at 265 sqft/hr', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Textured Walls same color')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(265.00);
        expect($rate->rate_type)->toBe('sqft_hr');
        expect($rate->scope)->toBe('interior');
        expect($rate->surface_category)->toBe('walls');
    });

    it('has Textured Walls change color at 150 sqft/hr', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Textured Walls change color')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(150.00);
        expect($rate->rate_type)->toBe('sqft_hr');
    });

    it('has Smooth Walls same color at 210 sqft/hr', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Smooth Walls same color')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(210.00);
    });

    it('has Smooth Walls change color at 120 sqft/hr', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Smooth Walls change color')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(120.00);
    });

    it('has prime and paint new textured drywall at 95.78 sqft/hr', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'prime and paint new textured drywall')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(95.78);
    });
});

describe('Interior Door Types (hours per unit)', function () {
    it('has Doors both sides at 2 hours/door', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Doors both sides')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(2.00);
        expect($rate->rate_type)->toBe('hours_per_unit');
    });

    it('has Closet Doors both sides at 4 hours/door', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Closet Doors both sides')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(4.00);
        expect($rate->rate_type)->toBe('hours_per_unit');
    });
});

describe('Exterior Body Application (spreadsheet exact values)', function () {
    it('has Siding Spray at 200 sqft/hr', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Siding Spray')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(200.00);
        expect($rate->rate_type)->toBe('sqft_hr');
        expect($rate->scope)->toBe('exterior');
        expect($rate->surface_category)->toBe('body');
    });

    it('has Siding Spray w/light backroll at 150 sqft/hr', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Siding Spray w/light backroll')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(150.00);
    });
});

describe('Wood Replacement (cost per linear foot)', function () {
    it('has 1st story trim at $6.84/lnft', function () {
        $rate = ProductionRate::where('spreadsheet_name', '1st story trim')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(6.84);
        expect($rate->rate_type)->toBe('cost_per_lnft');
        expect($rate->surface_category)->toBe('wood_replacement');
    });

    it('has Part of or entire door frame at $113.48/lnft', function () {
        $rate = ProductionRate::where('spreadsheet_name', 'Part of or entire door frame')->first();
        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(113.48);
        expect($rate->rate_type)->toBe('cost_per_lnft');
    });
});

describe('ProductionRate calculateHoursForQuantity', function () {
    it('calculates hours for sqft_hr rate type', function () {
        // Textured Walls change color: 150 sqft/hr
        // 468 sqft with 2 coats (1.8 multiplier) = 468 / 150 * 1.8 = 5.616 hours
        $rate = ProductionRate::where('spreadsheet_name', 'Textured Walls change color')->first();
        $hours = $rate->calculateHoursForQuantity(468, 2);
        expect(round($hours, 3))->toBe(5.616);
    });

    it('calculates hours for lnft_hr rate type', function () {
        // Baseboards change color: 30 lnft/hr
        // 100 lnft with 2 coats (1.8 multiplier) = 100 / 30 * 1.8 = 6.0 hours
        $rate = ProductionRate::where('spreadsheet_name', 'Baseboards change color')->first();
        $hours = $rate->calculateHoursForQuantity(100, 2);
        expect(round($hours, 3))->toBe(6.0);
    });

    it('calculates hours for hours_per_unit rate type', function () {
        // Doors both sides: 2 hours/door
        // 3 doors with 1 coat (1.0 multiplier) = 3 * 2 * 1.0 = 6 hours
        $rate = ProductionRate::where('spreadsheet_name', 'Doors both sides')->first();
        $hours = $rate->calculateHoursForQuantity(3, 1);
        expect(round($hours, 3))->toBe(6.0);
    });

    it('returns zero for cost_per_lnft rate type', function () {
        // Wood replacement costs don't have hours
        $rate = ProductionRate::where('spreadsheet_name', '1st story trim')->first();
        $hours = $rate->calculateHoursForQuantity(100);
        expect($hours)->toBe(0.0);
    });
});

describe('ProductionRate calculateCostForQuantity', function () {
    it('calculates cost for wood replacement', function () {
        // 1st story trim: $6.84/lnft
        // 100 lnft = $684
        $rate = ProductionRate::where('spreadsheet_name', '1st story trim')->first();
        $cost = $rate->calculateCostForQuantity(100);
        expect($cost)->toBe(684.0);
    });

    it('returns zero for non-cost rate types', function () {
        // Wall painting rates shouldn't return cost this way
        $rate = ProductionRate::where('spreadsheet_name', 'Textured Walls change color')->first();
        $cost = $rate->calculateCostForQuantity(100);
        expect($cost)->toBe(0.0);
    });
});

describe('ProductionRate scopes', function () {
    it('filters by scope using byScope', function () {
        $interior = ProductionRate::byScope('interior')->count();
        $exterior = ProductionRate::byScope('exterior')->count();

        expect($interior)->toBeGreaterThan(0);
        expect($exterior)->toBeGreaterThan(0);
        expect($interior + $exterior)->toBe(ProductionRate::count());
    });

    it('filters interior rates using interior scope', function () {
        $interior = ProductionRate::interior()->get();
        expect($interior)->each(fn ($rate) => $rate->scope->toBe('interior'));
    });

    it('filters exterior rates using exterior scope', function () {
        $exterior = ProductionRate::exterior()->get();
        expect($exterior)->each(fn ($rate) => $rate->scope->toBe('exterior'));
    });

    it('filters by surface category', function () {
        $walls = ProductionRate::bySurfaceCategory('walls')->get();
        expect($walls->count())->toBeGreaterThan(0);
        expect($walls)->each(fn ($rate) => $rate->surface_category->toBe('walls'));
    });
});

describe('ProductionRate attribute labels', function () {
    it('returns correct rate type label', function () {
        $rate = ProductionRate::where('rate_type', 'sqft_hr')->first();
        expect($rate->rate_type_label)->toBe('sqft/hr');

        $rate = ProductionRate::where('rate_type', 'hours_per_unit')->first();
        expect($rate->rate_type_label)->toBe('hrs/unit');

        $rate = ProductionRate::where('rate_type', 'cost_per_lnft')->first();
        expect($rate->rate_type_label)->toBe('$/lnft');
    });

    it('returns correct scope label', function () {
        $interior = ProductionRate::interior()->first();
        expect($interior->scope_label)->toBe('Interior');

        $exterior = ProductionRate::exterior()->first();
        expect($exterior->scope_label)->toBe('Exterior');
    });

    it('returns correct surface category label', function () {
        $walls = ProductionRate::where('surface_category', 'walls')->first();
        expect($walls->surface_category_label)->toBe('Walls');

        $body = ProductionRate::where('surface_category', 'body')->first();
        expect($body->surface_category_label)->toBe('Body Application');
    });
});

describe('ProductionRate getCategoriesForScope', function () {
    it('returns interior categories for interior scope', function () {
        $categories = ProductionRate::getCategoriesForScope('interior');

        expect($categories)->toHaveKey('prep');
        expect($categories)->toHaveKey('walls');
        expect($categories)->toHaveKey('ceiling');
        expect($categories)->toHaveKey('baseboard');
        expect($categories)->toHaveKey('doors');
        expect($categories['walls'])->toBe('Walls');
    });

    it('returns exterior categories for exterior scope', function () {
        $categories = ProductionRate::getCategoriesForScope('exterior');

        expect($categories)->toHaveKey('prep');
        expect($categories)->toHaveKey('body');
        expect($categories)->toHaveKey('eaves');
        expect($categories)->toHaveKey('fascia');
        expect($categories)->toHaveKey('windows');
        expect($categories)->toHaveKey('trim');
        expect($categories)->toHaveKey('exterior_doors');
        expect($categories)->toHaveKey('wood_replacement');
        expect($categories['body'])->toBe('Body Application');
    });
});

describe('Spreadsheet verification calculations', function () {
    it('matches spreadsheet wall calculation: 468 sqft at 150 sqft/hr with 2 coats', function () {
        // From plan: 468 sqft at "Textured Walls change color" (150 sqft/hr)
        // Hours = 468 / 150 = 3.12 hours
        // With 2 coats: 3.12 * 1.8 = 5.616 hours
        $rate = ProductionRate::where('spreadsheet_name', 'Textured Walls change color')->first();

        $hoursWithoutCoats = 468 / 150;
        expect($hoursWithoutCoats)->toBe(3.12);

        $hoursWithCoats = $rate->calculateHoursForQuantity(468, 2);
        expect(round($hoursWithCoats, 3))->toBe(5.616);
    });

    it('matches spreadsheet ceiling calculation', function () {
        // Smooth Ceiling change color: 50 sqft/hr
        // 168 sqft with 2 coats = 168 / 50 * 1.8 = 6.048 hours
        $rate = ProductionRate::where('spreadsheet_name', 'Smooth Ceiling Change color')->first();
        $hours = $rate->calculateHoursForQuantity(168, 2);
        expect(round($hours, 3))->toBe(6.048);
    });

    it('matches spreadsheet baseboard calculation', function () {
        // Baseboards same color: 40 lnft/hr
        // 52 lnft with 2 coats = 52 / 40 * 1.8 = 2.34 hours
        $rate = ProductionRate::where('spreadsheet_name', 'Baseboards same color')->first();
        $hours = $rate->calculateHoursForQuantity(52, 2);
        expect(round($hours, 3))->toBe(2.34);
    });
});
