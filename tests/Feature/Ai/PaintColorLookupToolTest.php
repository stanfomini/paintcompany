<?php

use App\Ai\Tools\PaintColorLookupTool;
use App\Models\PaintBrand;
use App\Models\PaintColor;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->sw = PaintBrand::factory()->create(['name' => 'Sherwin-Williams', 'code' => 'SW']);
    $this->bm = PaintBrand::factory()->create(['name' => 'Benjamin Moore', 'code' => 'BM']);

    PaintColor::factory()->forBrand($this->sw)->create([
        'name' => 'Iron Ore',
        'code' => 'SW-7069',
        'hex_value' => '#433F3E',
    ]);

    PaintColor::factory()->forBrand($this->sw)->create([
        'name' => 'Alabaster',
        'code' => 'SW-7008',
        'hex_value' => '#F0EDE3',
    ]);

    PaintColor::factory()->forBrand($this->bm)->create([
        'name' => 'White Dove',
        'code' => 'OC-17',
        'hex_value' => '#F3EFE0',
    ]);

    PaintColor::factory()->forBrand($this->bm)->create([
        'name' => 'Hale Navy',
        'code' => 'HC-154',
        'hex_value' => '#3F4955',
    ]);
});

it('resolves a color by exact name', function () {
    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'White Dove']));

    expect((string) $result)->toContain('White Dove')
        ->toContain('#F3EFE0')
        ->toContain('Benjamin Moore');
});

it('resolves a color by code', function () {
    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'SW-7069']));

    expect((string) $result)->toContain('Iron Ore')
        ->toContain('#433F3E')
        ->toContain('Sherwin-Williams');
});

it('handles fuzzy name matching', function () {
    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'iron ore']));

    expect((string) $result)->toContain('Iron Ore')
        ->toContain('#433F3E');
});

it('filters by brand name', function () {
    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'White', 'brand' => 'Benjamin Moore']));

    expect((string) $result)->toContain('White Dove')
        ->not->toContain('Sherwin-Williams');
});

it('filters by brand code', function () {
    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'Iron', 'brand' => 'SW']));

    expect((string) $result)->toContain('Iron Ore')
        ->toContain('Sherwin-Williams');
});

it('returns helpful message for non-existent colors', function () {
    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'Unicorn Rainbow Sparkle']));

    expect((string) $result)->toContain('No paint colors found');
});

it('limits results to 10', function () {
    // Create 15 colors with similar names
    foreach (range(1, 15) as $i) {
        PaintColor::factory()->forBrand($this->sw)->create([
            'name' => "Test Color {$i}",
            'code' => "TC-{$i}",
        ]);
    }

    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'Test Color']));

    $lines = array_filter(explode("\n", (string) $result));
    expect(count($lines))->toBeLessThanOrEqual(10);
});

it('excludes inactive colors', function () {
    PaintColor::factory()->forBrand($this->sw)->create([
        'name' => 'Inactive Gray',
        'code' => 'IG-001',
        'is_active' => false,
    ]);

    $tool = new PaintColorLookupTool;
    $result = $tool->handle(new Request(['query' => 'Inactive Gray']));

    expect((string) $result)->toContain('No paint colors found');
});

it('has a description', function () {
    $tool = new PaintColorLookupTool;
    expect((string) $tool->description())->not->toBeEmpty();
});

it('has a schema with query and brand parameters', function () {
    $tool = new PaintColorLookupTool;
    $schema = $tool->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('query')
        ->toHaveKey('brand');
});
