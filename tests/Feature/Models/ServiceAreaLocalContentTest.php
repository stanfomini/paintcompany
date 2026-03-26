<?php

use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts local_content as array', function () {
    $area = ServiceArea::factory()->create([
        'local_content' => [
            'neighborhoods' => ['Downtown', 'LoDo', 'RiNo'],
            'housing_types' => ['Victorian', 'Modern Loft'],
        ],
    ]);

    $area->refresh();

    expect($area->local_content)->toBeArray()
        ->and($area->local_content['neighborhoods'])->toBe(['Downtown', 'LoDo', 'RiNo'])
        ->and($area->local_content['housing_types'])->toBe(['Victorian', 'Modern Loft']);
});

it('allows null local_content', function () {
    $area = ServiceArea::factory()->create([
        'local_content' => null,
    ]);

    $area->refresh();

    expect($area->local_content)->toBeNull();
});

it('generates default meta title with city name', function () {
    $area = ServiceArea::factory()->create([
        'city' => 'Aurora',
        'meta_title' => null,
    ]);

    expect($area->meta_title)->toBe('House Painting Aurora CO | Interior & Exterior | Painting Made Easy Denver');
});

it('uses custom meta title when set', function () {
    $area = ServiceArea::factory()->create([
        'city' => 'Aurora',
        'meta_title' => 'Custom Title Here',
    ]);

    expect($area->meta_title)->toBe('Custom Title Here');
});

it('generates meta description with neighborhoods from local_content', function () {
    $area = ServiceArea::factory()->create([
        'city' => 'Denver',
        'meta_description' => null,
        'local_content' => [
            'neighborhoods' => ['Highlands', 'Cherry Creek', 'Washington Park', 'Congress Park'],
        ],
    ]);

    $description = $area->meta_description;

    expect($description)->toContain('Professional house painting in Denver, Colorado')
        ->and($description)->toContain('Serving Highlands, Cherry Creek, Washington Park and more.');
});

it('generates meta description without neighborhoods when local_content is null', function () {
    $area = ServiceArea::factory()->create([
        'city' => 'Lakewood',
        'meta_description' => null,
        'local_content' => null,
    ]);

    $description = $area->meta_description;

    expect($description)->toBe('Professional house painting in Lakewood, Colorado. Interior & exterior with real-time tracking. Free estimates.');
});

it('generates meta description without neighborhoods when neighborhoods array is empty', function () {
    $area = ServiceArea::factory()->create([
        'city' => 'Lakewood',
        'meta_description' => null,
        'local_content' => ['neighborhoods' => []],
    ]);

    $description = $area->meta_description;

    expect($description)->toBe('Professional house painting in Lakewood, Colorado. Interior & exterior with real-time tracking. Free estimates.');
});

it('uses custom meta description when set', function () {
    $area = ServiceArea::factory()->create([
        'meta_description' => 'A custom description',
        'local_content' => [
            'neighborhoods' => ['ShouldNotAppear'],
        ],
    ]);

    expect($area->meta_description)->toBe('A custom description');
});

it('includes local_content in fillable', function () {
    $fillable = (new ServiceArea)->getFillable();

    expect($fillable)->toContain('local_content');
});

it('has a services() BelongsToMany relationship', function () {
    $area = ServiceArea::factory()->create();
    $service = \App\Models\Service::factory()->create();

    $area->services()->attach($service->id, ['sort_order' => 1]);

    expect($area->services)->toHaveCount(1)
        ->and($area->services->first()->id)->toBe($service->id)
        ->and($area->services->first()->pivot->sort_order)->toBe(1);
});

it('stores pivot data on services relationship', function () {
    $area = ServiceArea::factory()->create();
    $service = \App\Models\Service::factory()->create();

    $area->services()->attach($service->id, [
        'custom_h1' => 'Custom Heading',
        'custom_intro' => 'Custom intro text',
        'is_featured' => true,
        'sort_order' => 5,
    ]);

    $pivot = $area->services->first()->pivot;

    expect($pivot->custom_h1)->toBe('Custom Heading')
        ->and($pivot->custom_intro)->toBe('Custom intro text')
        ->and($pivot->is_featured)->toBeTruthy()
        ->and($pivot->sort_order)->toBe(5);
});

it('generates url attribute', function () {
    $area = ServiceArea::factory()->create(['slug' => 'test-city-co']);

    expect($area->url)->toBe(route('areas.show', 'test-city-co'));
});

it('stores complex local_content structure', function () {
    $content = [
        'neighborhoods' => ['Downtown', 'LoDo', 'RiNo'],
        'housing_types' => ['Victorian homes', 'Modern condos'],
        'challenges' => ['UV exposure at altitude', 'Hail damage'],
        'landmarks' => ['Union Station', 'Coors Field'],
        'seasonal_notes' => 'Best painting season is May-October.',
        'why_local' => 'Denver altitude requires UV-resistant paint.',
    ];

    $area = ServiceArea::factory()->create(['local_content' => $content]);
    $area->refresh();

    expect($area->local_content)->toBe($content)
        ->and($area->local_content['neighborhoods'])->toHaveCount(3)
        ->and($area->local_content['challenges'])->toHaveCount(2);
});
