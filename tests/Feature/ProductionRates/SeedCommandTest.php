<?php

use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('production-rates:seed Command', function () {
    it('runs successfully', function () {
        $this->artisan('production-rates:seed')
            ->expectsOutput('Seeding default production rates...')
            ->expectsOutput('Default production rates seeded successfully.')
            ->assertExitCode(0);
    });

    it('creates interior painting category', function () {
        $this->artisan('production-rates:seed');

        expect(ProductionRateCategory::where('name', 'Interior Painting')->exists())->toBeTrue();
    });

    it('creates exterior painting category', function () {
        $this->artisan('production-rates:seed');

        expect(ProductionRateCategory::where('name', 'Exterior Painting')->exists())->toBeTrue();
    });

    it('creates specialty services category', function () {
        $this->artisan('production-rates:seed');

        expect(ProductionRateCategory::where('name', 'Specialty Services')->exists())->toBeTrue();
    });

    it('creates wall painting rate', function () {
        $this->artisan('production-rates:seed');

        $rate = ProductionRate::where('name', 'Wall Painting')->first();

        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(300.00);
        expect((float) $rate->hourly_labor_rate)->toBe(45.00);
    });

    it('creates ceiling painting rate', function () {
        $this->artisan('production-rates:seed');

        $rate = ProductionRate::where('name', 'Ceiling Painting')->first();

        expect($rate)->not->toBeNull();
        expect((float) $rate->production_rate)->toBe(400.00);
    });

    it('creates trim painting rate', function () {
        $this->artisan('production-rates:seed');

        $rate = ProductionRate::where('name', 'Trim Painting')->first();

        expect($rate)->not->toBeNull();
        expect($rate->unit_type)->toBe('lnft');
    });

    it('creates exterior siding rate', function () {
        $this->artisan('production-rates:seed');

        $rate = ProductionRate::where('name', 'Siding Painting')->first();

        expect($rate)->not->toBeNull();
        expect((float) $rate->hourly_labor_rate)->toBe(50.00);
    });

    it('creates deck staining rate', function () {
        $this->artisan('production-rates:seed');

        $rate = ProductionRate::where('name', 'Deck Staining')->first();

        expect($rate)->not->toBeNull();
    });

    it('creates specialty rates', function () {
        $this->artisan('production-rates:seed');

        expect(ProductionRate::where('name', 'Faux Finishing')->exists())->toBeTrue();
        expect(ProductionRate::where('name', 'Wallpaper Removal')->exists())->toBeTrue();
    });

    it('is idempotent - running twice does not duplicate', function () {
        $this->artisan('production-rates:seed');
        $countAfterFirst = ProductionRate::count();

        $this->artisan('production-rates:seed');
        $countAfterSecond = ProductionRate::count();

        expect($countAfterFirst)->toBe($countAfterSecond);
    });

    it('creates rates with default coat multipliers', function () {
        $this->artisan('production-rates:seed');

        $rate = ProductionRate::where('name', 'Wall Painting')->first();

        expect($rate->coat_multipliers)->not->toBeNull();
        expect((float) $rate->coat_multipliers['1'])->toBe(1.0);
        expect((float) $rate->coat_multipliers['2'])->toBe(1.8);
        expect((float) $rate->coat_multipliers['3'])->toBe(2.5);
    });

    it('sets default coats for rates', function () {
        $this->artisan('production-rates:seed');

        $wallRate = ProductionRate::where('name', 'Wall Painting')->first();
        $wallpaperRate = ProductionRate::where('name', 'Wallpaper Removal')->first();

        expect($wallRate->default_coats)->toBe(2);
        expect($wallpaperRate->default_coats)->toBe(1);
    });

    it('creates categories with correct types', function () {
        $this->artisan('production-rates:seed');

        $interior = ProductionRateCategory::where('name', 'Interior Painting')->first();
        $exterior = ProductionRateCategory::where('name', 'Exterior Painting')->first();
        $specialty = ProductionRateCategory::where('name', 'Specialty Services')->first();

        expect($interior->type)->toBe(ProductionRateCategory::TYPE_INTERIOR);
        expect($exterior->type)->toBe(ProductionRateCategory::TYPE_EXTERIOR);
        expect($specialty->type)->toBe(ProductionRateCategory::TYPE_SPECIALTY);
    });

    it('creates all rates as active', function () {
        $this->artisan('production-rates:seed');

        $inactiveCount = ProductionRate::where('is_active', false)->count();

        expect($inactiveCount)->toBe(0);
    });
});
