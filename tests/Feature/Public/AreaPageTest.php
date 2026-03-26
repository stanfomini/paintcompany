<?php

use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the area page for an active area', function () {
    $area = ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);

    $this->get('/areas/denver-co')
        ->assertSuccessful();
});

it('returns 404 for a nonexistent area slug', function () {
    $this->get('/areas/nonexistent-co')
        ->assertNotFound();
});

it('returns 404 for an inactive area', function () {
    ServiceArea::factory()->inactive()->create(['slug' => 'inactive-co']);

    $this->get('/areas/inactive-co')
        ->assertNotFound();
});

it('displays the H1 with city name in serif heading', function () {
    ServiceArea::factory()->create(['slug' => 'aurora-co', 'city' => 'Aurora']);

    $this->get('/areas/aurora-co')
        ->assertSuccessful()
        ->assertSee('House Painting in Aurora, Colorado');
});

it('displays neighborhood tags from local_content', function () {
    ServiceArea::factory()->create([
        'slug' => 'denver-co',
        'city' => 'Denver',
        'local_content' => [
            'neighborhoods' => ['Capitol Hill', 'Cherry Creek', 'Park Hill'],
        ],
    ]);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Capitol Hill')
        ->assertSee('Cherry Creek')
        ->assertSee('Park Hill');
});

it('displays local painting challenges from local_content', function () {
    ServiceArea::factory()->create([
        'slug' => 'denver-co',
        'city' => 'Denver',
        'local_content' => [
            'challenges' => ['Intense UV exposure at altitude', 'Hail damage prep'],
        ],
    ]);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Why Denver Homes Need Expert Painters')
        ->assertSee('Intense UV exposure at altitude')
        ->assertSee('Hail damage prep');
});

it('displays housing types from local_content', function () {
    ServiceArea::factory()->create([
        'slug' => 'denver-co',
        'city' => 'Denver',
        'local_content' => [
            'housing_types' => ['Victorian homes', 'Mid-century ranch'],
        ],
    ]);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Victorian homes')
        ->assertSee('Mid-century ranch');
});

it('hides the local content section when no local_content exists', function () {
    ServiceArea::factory()->create([
        'slug' => 'empty-co',
        'city' => 'Empty',
        'local_content' => null,
    ]);

    $this->get('/areas/empty-co')
        ->assertSuccessful()
        ->assertDontSee('Why Empty Homes Need Expert Painters');
});

it('displays services from the Service model in the grid', function () {
    $area = ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);
    Service::factory()->create(['title' => 'Interior House Painting', 'slug' => 'interior-painting', 'tagline' => 'Walls and ceilings']);
    Service::factory()->create(['title' => 'Exterior House Painting', 'slug' => 'exterior-painting', 'tagline' => 'Siding and trim']);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Interior House Painting')
        ->assertSee('Exterior House Painting');
});

it('links services to area+service combo pages', function () {
    $area = ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);
    Service::factory()->create(['slug' => 'interior-painting', 'title' => 'Interior Painting']);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('/areas/denver-co/interior-painting', escape: false);
});

it('prefers pivot-attached services over all services', function () {
    $area = ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);
    $attached = Service::factory()->create(['title' => 'Attached Service', 'slug' => 'attached']);
    $unattached = Service::factory()->create(['title' => 'Unattached Service', 'slug' => 'unattached']);

    $area->services()->attach($attached->id, ['sort_order' => 1]);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Attached Service')
        ->assertDontSee('Unattached Service');
});

it('displays the why choose us section', function () {
    ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Why Denver Homeowners Choose Us')
        ->assertSee('Satisfaction Guaranteed')
        ->assertSee('Real-Time Tracking');
});

it('displays other service area links', function () {
    ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);
    ServiceArea::factory()->create(['slug' => 'aurora-co', 'city' => 'Aurora']);
    ServiceArea::factory()->create(['slug' => 'boulder-co', 'city' => 'Boulder']);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Aurora')
        ->assertSee('Boulder')
        ->assertSee('Other Service Areas');
});

it('does not include the current area in other areas links', function () {
    $area = ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);
    ServiceArea::factory()->create(['slug' => 'aurora-co', 'city' => 'Aurora']);

    $response = $this->get('/areas/denver-co');
    $response->assertSuccessful();

    // The "Other Service Areas" section should not link back to the current area
    $content = $response->getContent();
    $otherAreasSection = substr($content, strpos($content, 'Other Service Areas'));
    expect($otherAreasSection)->not->toContain('/areas/denver-co');
});

it('includes schema.org structured data with neighborhoods', function () {
    ServiceArea::factory()->create([
        'slug' => 'denver-co',
        'city' => 'Denver',
        'local_content' => [
            'neighborhoods' => ['Capitol Hill', 'LoDo'],
        ],
    ]);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('"@type": "HousePainter"', escape: false)
        ->assertSee('"@type": "BreadcrumbList"', escape: false)
        ->assertSee('"@type": "Neighborhood"', escape: false)
        ->assertSee('Capitol Hill', escape: false);
});

it('includes the shared CTA section', function () {
    ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Ready to Transform Your Denver Home?');
});

it('includes the trust strip', function () {
    ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Licensed')
        ->assertSee('5-Star Rated');
});

it('does not render an inline footer', function () {
    ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);

    $content = $this->get('/areas/denver-co')->getContent();

    // The old page had a <footer class="bg-[#1B2D4F]"> inline. That should no longer exist.
    expect($content)->not->toContain('bg-[#1B2D4F]');
});

it('uses brand-900 hero instead of blue gradient', function () {
    ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);

    $content = $this->get('/areas/denver-co')->getContent();

    expect($content)->toContain('bg-brand-900');
    expect($content)->not->toContain('from-[#1B2D4F]');
    expect($content)->not->toContain('bg-gradient-to-br');
});

it('uses Google Maps not Leaflet', function () {
    ServiceArea::factory()->create([
        'slug' => 'denver-co',
        'city' => 'Denver',
        'latitude' => 39.7392,
        'longitude' => -104.9903,
    ]);

    $content = $this->get('/areas/denver-co')->getContent();

    expect($content)->toContain('google.maps.Map')
        ->and($content)->not->toContain('leaflet.js')
        ->and($content)->not->toContain('L.map');
});

it('uses font-heading for H1', function () {
    ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);

    $content = $this->get('/areas/denver-co')->getContent();

    expect($content)->toContain('font-heading');
});

it('displays why_local from local_content', function () {
    ServiceArea::factory()->create([
        'slug' => 'denver-co',
        'city' => 'Denver',
        'local_content' => [
            'why_local' => 'Denver altitude requires UV-resistant paint formulations.',
        ],
    ]);

    $this->get('/areas/denver-co')
        ->assertSuccessful()
        ->assertSee('Why Denver Homes Need Expert Painters')
        ->assertSee('Denver altitude requires UV-resistant paint formulations.');
});
