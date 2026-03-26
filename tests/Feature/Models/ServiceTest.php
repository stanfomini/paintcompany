<?php

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a service with all fields', function () {
    $service = Service::factory()->create([
        'title' => 'Interior House Painting',
        'slug' => 'interior-painting',
        'category' => 'Residential',
    ]);

    expect($service->title)->toBe('Interior House Painting')
        ->and($service->slug)->toBe('interior-painting')
        ->and($service->category)->toBe('Residential')
        ->and($service->is_active)->toBeTrue();
});

it('casts json fields as arrays', function () {
    $service = Service::factory()->create([
        'materials' => ['Paint' => ['Primer', 'Latex']],
        'steps' => [['title' => 'Step 1', 'description' => 'Do this']],
        'tips' => [['title' => 'Tip 1', 'description' => 'Try this']],
        'cost_factors' => ['Size', 'Condition'],
        'faqs' => [['q' => 'Why?', 'a' => 'Because.']],
    ]);

    $service->refresh();

    expect($service->materials)->toBeArray()
        ->and($service->steps)->toBeArray()
        ->and($service->tips)->toBeArray()
        ->and($service->cost_factors)->toBeArray()
        ->and($service->faqs)->toBeArray();
});

it('casts sort_order as integer', function () {
    $service = Service::factory()->create(['sort_order' => '5']);

    expect($service->sort_order)->toBeInt()->toBe(5);
});

it('casts is_active as boolean', function () {
    $service = Service::factory()->create(['is_active' => 1]);

    expect($service->is_active)->toBeBool()->toBeTrue();
});

it('enforces unique slug', function () {
    Service::factory()->create(['slug' => 'interior-painting']);

    expect(fn () => Service::factory()->create(['slug' => 'interior-painting']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('scopes active services', function () {
    Service::factory()->create(['is_active' => true]);
    Service::factory()->create(['is_active' => true]);
    Service::factory()->inactive()->create();

    expect(Service::active()->count())->toBe(2);
});

it('scopes by category', function () {
    Service::factory()->residential()->create();
    Service::factory()->residential()->create();
    Service::factory()->commercial()->create();
    Service::factory()->specialty()->create();

    expect(Service::byCategory('Residential')->count())->toBe(2)
        ->and(Service::byCategory('Commercial')->count())->toBe(1)
        ->and(Service::byCategory('Specialty')->count())->toBe(1);
});

it('generates url attribute', function () {
    $service = Service::factory()->create(['slug' => 'interior-painting']);

    expect($service->url)->toBe(route('services.show', 'interior-painting'));
});

it('seeds all 17 services', function () {
    $this->artisan('db:seed', ['--class' => 'ServiceSeeder', '--no-interaction' => true]);

    expect(Service::count())->toBe(17);
});

it('seeds correct category counts', function () {
    $this->artisan('db:seed', ['--class' => 'ServiceSeeder', '--no-interaction' => true]);

    expect(Service::byCategory('Residential')->count())->toBe(9)
        ->and(Service::byCategory('Commercial')->count())->toBe(4)
        ->and(Service::byCategory('Specialty')->count())->toBe(4);
});

it('seeds services with sort order', function () {
    $this->artisan('db:seed', ['--class' => 'ServiceSeeder', '--no-interaction' => true]);

    $first = Service::where('slug', 'interior-painting')->first();
    $last = Service::where('slug', 'stucco-coating')->first();

    expect($first->sort_order)->toBe(1)
        ->and($last->sort_order)->toBe(17);
});

it('seeds services with all required fields populated', function () {
    $this->artisan('db:seed', ['--class' => 'ServiceSeeder', '--no-interaction' => true]);

    Service::all()->each(function (Service $service) {
        expect($service->title)->not->toBeEmpty()
            ->and($service->slug)->not->toBeEmpty()
            ->and($service->category)->toBeIn(['Residential', 'Commercial', 'Specialty'])
            ->and($service->tagline)->not->toBeEmpty()
            ->and($service->meta_description)->not->toBeEmpty()
            ->and($service->overview)->not->toBeEmpty()
            ->and($service->steps)->toBeArray()->not->toBeEmpty()
            ->and($service->faqs)->toBeArray()->not->toBeEmpty();
    });
});

it('uses factory inactive state', function () {
    $service = Service::factory()->inactive()->create();

    expect($service->is_active)->toBeFalse();
});

it('allows nullable materials and tips', function () {
    $service = Service::factory()->create([
        'materials' => null,
        'tips' => null,
        'cost_factors' => null,
    ]);

    $service->refresh();

    expect($service->materials)->toBeNull()
        ->and($service->tips)->toBeNull()
        ->and($service->cost_factors)->toBeNull();
});

it('has a serviceAreas() BelongsToMany relationship', function () {
    $service = Service::factory()->create();
    $area = \App\Models\ServiceArea::factory()->create();

    $service->serviceAreas()->attach($area->id, ['sort_order' => 1]);

    expect($service->serviceAreas)->toHaveCount(1)
        ->and($service->serviceAreas->first()->id)->toBe($area->id);
});

it('stores pivot data on serviceAreas relationship', function () {
    $service = Service::factory()->create();
    $area = \App\Models\ServiceArea::factory()->create();

    $service->serviceAreas()->attach($area->id, [
        'custom_h1' => 'Custom Service in City',
        'custom_intro' => 'Intro paragraph',
        'is_featured' => true,
        'sort_order' => 3,
    ]);

    $pivot = $service->serviceAreas->first()->pivot;

    expect($pivot->custom_h1)->toBe('Custom Service in City')
        ->and($pivot->custom_intro)->toBe('Intro paragraph')
        ->and($pivot->is_featured)->toBeTruthy()
        ->and($pivot->sort_order)->toBe(3);
});
