<?php

use App\Models\PaintBrand;
use App\Models\PaintProduct;
use Database\Seeders\PaintCatalogSeeder;

beforeEach(function () {
    $this->seed(PaintCatalogSeeder::class);
});

it('has correct total active paint products count', function () {
    // SW(24) + BM(17) + Behr(14) + PPG(15) = 70
    expect(PaintProduct::active()->count())->toBe(70);
});

it('has correct Sherwin-Williams product count', function () {
    $sw = PaintBrand::where('code', 'SW')->first();

    expect(PaintProduct::active()->where('paint_brand_id', $sw->id)->count())->toBe(24);
});

it('has correct Benjamin Moore product count', function () {
    $bm = PaintBrand::where('code', 'BM')->first();

    expect(PaintProduct::active()->where('paint_brand_id', $bm->id)->count())->toBe(17);
});

it('has correct Behr product count', function () {
    $behr = PaintBrand::where('code', 'BEHR')->first();

    expect(PaintProduct::active()->where('paint_brand_id', $behr->id)->count())->toBe(14);
});

it('has correct PPG product count', function () {
    $ppg = PaintBrand::where('code', 'PPG')->first();

    expect(PaintProduct::active()->where('paint_brand_id', $ppg->id)->count())->toBe(15);
});

it('has correct quality_tier values for sample products', function () {
    expect(PaintProduct::where('code', 'SW-DUR-HOME')->first()->quality_tier)->toBe('better')
        ->and(PaintProduct::where('code', 'SW-EMER-INT')->first()->quality_tier)->toBe('best')
        ->and(PaintProduct::where('code', 'SW-PM200')->first()->quality_tier)->toBe('good')
        ->and(PaintProduct::where('code', 'BM-AURA')->first()->quality_tier)->toBe('best')
        ->and(PaintProduct::where('code', 'BM-REGAL')->first()->quality_tier)->toBe('better')
        ->and(PaintProduct::where('code', 'BM-BEN')->first()->quality_tier)->toBe('good')
        ->and(PaintProduct::where('code', 'BEHR-DYN')->first()->quality_tier)->toBe('best')
        ->and(PaintProduct::where('code', 'BEHR-MARQ')->first()->quality_tier)->toBe('better')
        ->and(PaintProduct::where('code', 'BEHR-PP')->first()->quality_tier)->toBe('good')
        ->and(PaintProduct::where('code', 'PPG-ULAST')->first()->quality_tier)->toBe('best')
        ->and(PaintProduct::where('code', 'PPG-DIAM-INT')->first()->quality_tier)->toBe('better')
        ->and(PaintProduct::where('code', 'PPG-GP-INT')->first()->quality_tier)->toBe('good');
});

it('filters by tier using scopeForTier', function () {
    $goodCount = PaintProduct::active()->forTier('good')->count();
    $betterCount = PaintProduct::active()->forTier('better')->count();
    $bestCount = PaintProduct::active()->forTier('best')->count();

    expect($goodCount)->toBeGreaterThan(0)
        ->and($betterCount)->toBeGreaterThan(0)
        ->and($bestCount)->toBeGreaterThan(0)
        ->and($goodCount + $betterCount + $bestCount)->toBeLessThan(PaintProduct::active()->count());
});

it('has null quality_tier for primers', function () {
    $primers = PaintProduct::active()->where('product_type', PaintProduct::TYPE_PRIMER)->get();

    expect($primers)->not->toBeEmpty();

    $primers->each(function (PaintProduct $primer) {
        expect($primer->quality_tier)->toBeNull();
    });
});

it('has products for each tier covering walls and exterior siding', function () {
    $tiers = ['good', 'better', 'best'];

    foreach ($tiers as $tier) {
        // Every tier must have wall paints
        expect(PaintProduct::active()->forTier($tier)->where('surface_type', 'walls')->count())
            ->toBeGreaterThan(0, "Missing {$tier} tier products for walls");

        // Every tier must have exterior siding paints
        expect(PaintProduct::active()->forTier($tier)->where('surface_type', 'siding')->where('scope_type', PaintProduct::SCOPE_EXTERIOR)->count())
            ->toBeGreaterThan(0, "Missing {$tier} tier products for siding");
    }

    // Ceiling paints exist for good and better tiers
    expect(PaintProduct::active()->forTier('good')->where('surface_type', 'ceiling')->count())->toBeGreaterThan(0);
    expect(PaintProduct::active()->forTier('better')->where('surface_type', 'ceiling')->count())->toBeGreaterThan(0);

    // Trim paints exist for better and best tiers
    expect(PaintProduct::active()->forTier('better')->where('surface_type', 'trim')->count())->toBeGreaterThan(0);
    expect(PaintProduct::active()->forTier('best')->where('surface_type', 'trim')->count())->toBeGreaterThan(0);
});

it('filters paints only using scopePaintsOnly', function () {
    $paintsOnly = PaintProduct::active()->paintsOnly()->count();
    $totalActive = PaintProduct::active()->count();
    $primers = PaintProduct::active()->where('product_type', PaintProduct::TYPE_PRIMER)->count();

    expect($paintsOnly)->toBe($totalActive - $primers);
});

it('has updated prices for existing products', function () {
    expect((float) PaintProduct::where('code', 'SW-CASH')->first()->price_per_gallon)->toBe(72.99)
        ->and((float) PaintProduct::where('code', 'SW-PM200')->first()->price_per_gallon)->toBe(81.99)
        ->and((float) PaintProduct::where('code', 'SW-EMER-TRIM')->first()->price_per_gallon)->toBe(99.99)
        ->and((float) PaintProduct::where('code', 'BM-AURA')->first()->price_per_gallon)->toBe(103.99)
        ->and((float) PaintProduct::where('code', 'BM-ADV')->first()->price_per_gallon)->toBe(83.99)
        ->and((float) PaintProduct::where('code', 'BEHR-ULTRA')->first()->price_per_gallon)->toBe(38.99);
});

it('changed SuperPaint and Behr Ultra to interior only scope', function () {
    expect(PaintProduct::where('code', 'SW-SUPER')->first()->scope_type)->toBe(PaintProduct::SCOPE_INTERIOR)
        ->and(PaintProduct::where('code', 'BEHR-ULTRA')->first()->scope_type)->toBe(PaintProduct::SCOPE_INTERIOR);
});
