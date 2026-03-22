<?php

use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the area index page with all active service areas', function () {
    $active1 = ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver-co', 'is_active' => true]);
    $active2 = ServiceArea::factory()->create(['city' => 'Aurora', 'slug' => 'aurora-co', 'is_active' => true]);
    $inactive = ServiceArea::factory()->create(['city' => 'Hidden City', 'slug' => 'hidden-city-co', 'is_active' => false]);

    $response = $this->get(route('areas.index'));

    $response->assertSuccessful();
    $response->assertSee('Denver');
    $response->assertSee('Aurora');
    $response->assertDontSee('Hidden City');
});

it('renders an individual area page with correct city name', function () {
    $area = ServiceArea::factory()->create([
        'city' => 'Broomfield',
        'state' => 'CO',
        'slug' => 'broomfield-co',
        'hero_text' => 'Our Broomfield team is amazing.',
        'specialties_text' => 'Stucco exteriors, HOA-compliant colors',
    ]);

    $response = $this->get(route('areas.show', 'broomfield-co'));

    $response->assertSuccessful();
    $response->assertSee('Broomfield');
    $response->assertSee('Our Broomfield team is amazing.');
    $response->assertSee('Stucco exteriors, HOA-compliant colors');
});

it('returns 404 for non-existent area slug', function () {
    $response = $this->get(route('areas.show', 'nonexistent-city-co'));

    $response->assertNotFound();
});

it('returns 404 for inactive area', function () {
    ServiceArea::factory()->create([
        'slug' => 'hidden-co',
        'is_active' => false,
    ]);

    $response = $this->get(route('areas.show', 'hidden-co'));

    $response->assertNotFound();
});

it('renders area page with meta title and description', function () {
    ServiceArea::factory()->create([
        'city' => 'Boulder',
        'slug' => 'boulder-co',
    ]);

    $response = $this->get(route('areas.show', 'boulder-co'));

    $response->assertSuccessful();
    $response->assertSee('Professional Painting Services in Boulder', false);
});

it('renders area page with JSON-LD structured data', function () {
    ServiceArea::factory()->create([
        'city' => 'Lakewood',
        'slug' => 'lakewood-co',
    ]);

    $response = $this->get(route('areas.show', 'lakewood-co'));

    $response->assertSuccessful();
    $response->assertSee('application/ld+json', false);
    $response->assertSee('HousePainter', false);
});

it('renders other service areas links on area page', function () {
    ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver-co']);
    ServiceArea::factory()->create(['city' => 'Aurora', 'slug' => 'aurora-co']);

    $response = $this->get(route('areas.show', 'denver-co'));

    $response->assertSuccessful();
    $response->assertSee('Aurora');
});

it('renders services list on area page', function () {
    ServiceArea::factory()->create(['city' => 'Golden', 'slug' => 'golden-co']);

    $response = $this->get(route('areas.show', 'golden-co'));

    $response->assertSuccessful();
    $response->assertSee('Interior Painting');
    $response->assertSee('Exterior Painting');
    $response->assertSee('Cabinet Painting');
});

it('area model has correct default meta attributes', function () {
    $area = ServiceArea::factory()->create([
        'city' => 'Arvada',
        'state' => 'CO',
        'slug' => 'arvada-co',
        'meta_title' => null,
        'meta_description' => null,
    ]);

    expect($area->meta_title)->toContain('Arvada');
    expect($area->meta_description)->toContain('Arvada');
});

it('area index page has SEO meta tags', function () {
    ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver-co']);

    $response = $this->get(route('areas.index'));

    $response->assertSuccessful();
    $response->assertSee('Service Areas');
});

it('findByZip scope works correctly', function () {
    ServiceArea::factory()->create([
        'city' => 'Broomfield',
        'slug' => 'broomfield-co',
        'zip_codes' => ['80020', '80021', '80023'],
    ]);

    ServiceArea::factory()->create([
        'city' => 'Denver',
        'slug' => 'denver-co',
        'zip_codes' => ['80201', '80202'],
    ]);

    $result = ServiceArea::findByZip('80020')->get();
    expect($result)->toHaveCount(1);
    expect($result->first()->city)->toBe('Broomfield');
});

it('renders area pages for I-25 corridor expansion cities', function () {
    $corridorCities = [
        ['city' => 'Fort Collins', 'slug' => 'fort-collins-co'],
        ['city' => 'Loveland', 'slug' => 'loveland-co'],
        ['city' => 'Windsor', 'slug' => 'windsor-co'],
    ];

    foreach ($corridorCities as $data) {
        ServiceArea::factory()->create([
            'city' => $data['city'],
            'slug' => $data['slug'],
            'is_active' => true,
            'latitude' => 40.0,
            'longitude' => -105.0,
        ]);
    }

    foreach ($corridorCities as $data) {
        $this->get('/areas/'.$data['slug'])
            ->assertSuccessful()
            ->assertSee($data['city']);
    }
});
