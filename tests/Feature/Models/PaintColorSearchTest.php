<?php

use App\Models\PaintBrand;
use App\Models\PaintColor;

beforeEach(function () {
    $this->sw = PaintBrand::factory()->sherwinWilliams()->create();
    $this->bm = PaintBrand::factory()->benjaminMoore()->create();
    $this->behr = PaintBrand::factory()->behr()->create();
    $this->ppg = PaintBrand::factory()->ppg()->create();

    // Sherwin-Williams colors
    PaintColor::factory()->forBrand($this->sw)->create([
        'name' => 'Agreeable Gray',
        'code' => 'SW 7029',
    ]);
    PaintColor::factory()->forBrand($this->sw)->create([
        'name' => 'Iron Ore',
        'code' => 'SW 7069',
    ]);
    PaintColor::factory()->forBrand($this->sw)->create([
        'name' => 'Repose Gray',
        'code' => 'SW 7015',
    ]);

    // Benjamin Moore colors
    PaintColor::factory()->forBrand($this->bm)->create([
        'name' => 'White Dove',
        'code' => 'OC-17',
    ]);
    PaintColor::factory()->forBrand($this->bm)->create([
        'name' => 'Simply White',
        'code' => 'OC-117',
    ]);
    PaintColor::factory()->forBrand($this->bm)->create([
        'name' => 'Chantilly Lace',
        'code' => 'OC-65',
    ]);

    // Behr colors
    PaintColor::factory()->forBrand($this->behr)->create([
        'name' => 'Ultra Pure White',
        'code' => 'PPU18-06',
    ]);

    // PPG colors
    PaintColor::factory()->forBrand($this->ppg)->create([
        'name' => 'Dusty Miller Grey',
        'code' => 'PPG1029-4',
    ]);
});

it('searches by color name', function () {
    $results = PaintColor::search('White Dove')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('White Dove');
});

it('searches by color code', function () {
    $results = PaintColor::search('OC-17')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('White Dove');
});

it('extracts BM brand prefix and filters results', function () {
    $results = PaintColor::search('BM White Dove')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('White Dove')
        ->and($results->first()->paint_brand_id)->toBe($this->bm->id);
});

it('extracts full brand name and filters results', function () {
    $results = PaintColor::search('Sherwin Williams Agreeable Gray')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Agreeable Gray')
        ->and($results->first()->paint_brand_id)->toBe($this->sw->id);
});

it('extracts hyphenated brand name', function () {
    $results = PaintColor::search('Sherwin-Williams Iron Ore')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Iron Ore');
});

it('normalizes hyphens to spaces in codes', function () {
    // SW-7069 should match code "SW 7069"
    $results = PaintColor::search('SW-7069')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Iron Ore');
});

it('matches code with original space format', function () {
    $results = PaintColor::search('SW 7069')->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Iron Ore');
});

it('normalizes grey to gray', function () {
    // "grey" should also find colors with "gray" in the name
    $results = PaintColor::search('grey')->get();

    $names = $results->pluck('name')->toArray();
    expect($names)->toContain('Agreeable Gray')
        ->and($names)->toContain('Repose Gray')
        ->and($names)->toContain('Dusty Miller Grey');
});

it('normalizes gray to grey', function () {
    // "gray" should also find colors with "grey" in the name
    $results = PaintColor::search('gray')->get();

    $names = $results->pluck('name')->toArray();
    expect($names)->toContain('Agreeable Gray')
        ->and($names)->toContain('Repose Gray')
        ->and($names)->toContain('Dusty Miller Grey');
});

it('filters by brand prefix with partial color name', function () {
    // "benjamin moore white" should return BM colors with "white" in name
    $results = PaintColor::search('benjamin moore white')->get();

    expect($results->every(fn ($c) => $c->paint_brand_id === $this->bm->id))->toBeTrue()
        ->and($results->pluck('name'))->toContain('White Dove')
        ->and($results->pluck('name'))->toContain('Simply White');
});

it('returns results for a generic term', function () {
    $results = PaintColor::search('White')->get();

    expect($results->count())->toBeGreaterThanOrEqual(3);
});

it('returns no results for empty string', function () {
    $results = PaintColor::search('')->get();

    expect($results)->toHaveCount(0);
});

it('returns no results for whitespace only', function () {
    $results = PaintColor::search('   ')->get();

    expect($results)->toHaveCount(0);
});

it('does not return inactive colors', function () {
    PaintColor::factory()->forBrand($this->sw)->inactive()->create([
        'name' => 'Discontinued Gray',
        'code' => 'SW 0001',
    ]);

    $results = PaintColor::active()->search('Discontinued Gray')->get();

    expect($results)->toHaveCount(0);
});

it('handles SW brand prefix with code search', function () {
    // "SW 7029" — brand prefix "SW" followed by " 7029" as search term
    $results = PaintColor::search('SW 7029')->get();

    expect($results->pluck('name'))->toContain('Agreeable Gray');
});

it('handles multi-word search with words across name and code', function () {
    // "Iron 7069" — "Iron" is in name, "7069" is in code
    $results = PaintColor::search('Iron 7069')->get();

    expect($results->pluck('name'))->toContain('Iron Ore');
});

it('filters by intended use with forIntendedUse scope', function () {
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Agreeable Gray Interior',
        'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Iron Ore Exterior',
        'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Alabaster Both',
        'intended_use' => 'both',
    ]);

    $interiorResults = PaintColor::active()->forIntendedUse('interior')->pluck('name');
    expect($interiorResults)->toContain('Agreeable Gray Interior', 'Alabaster Both')
        ->not->toContain('Iron Ore Exterior');

    $exteriorResults = PaintColor::active()->forIntendedUse('exterior')->pluck('name');
    expect($exteriorResults)->toContain('Iron Ore Exterior', 'Alabaster Both')
        ->not->toContain('Agreeable Gray Interior');
});

it('gets cached popular colors filtered by intended use', function () {
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Interior Only',
        'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Exterior Only',
        'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Works Everywhere',
        'intended_use' => 'both',
    ]);

    $interiorColors = PaintColor::getCachedPopularColors($this->sw->id, 'interior');
    expect($interiorColors->pluck('name')->toArray())
        ->toContain('Interior Only', 'Works Everywhere')
        ->not->toContain('Exterior Only');

    $exteriorColors = PaintColor::getCachedPopularColors($this->sw->id, 'exterior');
    expect($exteriorColors->pluck('name')->toArray())
        ->toContain('Exterior Only', 'Works Everywhere')
        ->not->toContain('Interior Only');
});
