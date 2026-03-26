<?php

use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the area+service route', function () {
    $area = ServiceArea::factory()->create(['slug' => 'test-city-co', 'city' => 'Test City']);
    $service = Service::factory()->create(['slug' => 'test-service', 'title' => 'Test Service']);

    $this->get('/areas/test-city-co/test-service')
        ->assertSuccessful();
});

it('returns 404 for invalid area slug', function () {
    $service = Service::factory()->create(['slug' => 'test-service']);

    $this->get('/areas/nonexistent-co/test-service')
        ->assertNotFound();
});

it('returns 404 for invalid service slug', function () {
    $area = ServiceArea::factory()->create(['slug' => 'test-city-co']);

    $this->get('/areas/test-city-co/nonexistent-service')
        ->assertNotFound();
});

it('returns 404 for inactive area', function () {
    $area = ServiceArea::factory()->inactive()->create(['slug' => 'inactive-co']);
    $service = Service::factory()->create(['slug' => 'test-service']);

    $this->get('/areas/inactive-co/test-service')
        ->assertNotFound();
});

it('returns 404 for inactive service', function () {
    $area = ServiceArea::factory()->create(['slug' => 'test-city-co']);
    $service = Service::factory()->inactive()->create(['slug' => 'inactive-service']);

    $this->get('/areas/test-city-co/inactive-service')
        ->assertNotFound();
});

it('displays the default H1 with service title and city', function () {
    $area = ServiceArea::factory()->create(['slug' => 'denver-test-co', 'city' => 'Denver']);
    $service = Service::factory()->create(['slug' => 'interior-test', 'title' => 'Interior House Painting']);

    $this->get('/areas/denver-test-co/interior-test')
        ->assertSuccessful()
        ->assertSee('Interior House Painting in Denver, Colorado');
});

it('displays custom H1 from pivot when set', function () {
    $area = ServiceArea::factory()->create(['slug' => 'boulder-test-co', 'city' => 'Boulder']);
    $service = Service::factory()->create(['slug' => 'exterior-test', 'title' => 'Exterior House Painting']);

    $area->services()->attach($service->id, [
        'custom_h1' => 'Boulder Exterior Painting Experts',
    ]);

    $this->get('/areas/boulder-test-co/exterior-test')
        ->assertSuccessful()
        ->assertSee('Boulder Exterior Painting Experts');
});

it('includes the service title and city in structured data', function () {
    $area = ServiceArea::factory()->create(['slug' => 'arvada-test-co', 'city' => 'Arvada']);
    $service = Service::factory()->create(['slug' => 'cabinet-test', 'title' => 'Cabinet Painting']);

    $this->get('/areas/arvada-test-co/cabinet-test')
        ->assertSuccessful()
        ->assertSee('Cabinet Painting in Arvada, Colorado');
});

it('renders cross-links for other services in the same city', function () {
    $area = ServiceArea::factory()->create(['slug' => 'crosslink-city-co', 'city' => 'Crosslink City']);
    $primary = Service::factory()->create(['slug' => 'primary-service', 'title' => 'Primary Service']);
    $other = Service::factory()->create(['slug' => 'other-service', 'title' => 'Other Service']);

    $area->services()->attach([$primary->id => ['sort_order' => 1], $other->id => ['sort_order' => 2]]);

    $this->get('/areas/crosslink-city-co/primary-service')
        ->assertSuccessful()
        ->assertSee('Other Service');
});

it('renders cross-links for the same service in other cities', function () {
    $area1 = ServiceArea::factory()->create(['slug' => 'city-a-co', 'city' => 'City A']);
    $area2 = ServiceArea::factory()->create(['slug' => 'city-b-co', 'city' => 'City B']);
    $service = Service::factory()->create(['slug' => 'shared-service', 'title' => 'Shared Service']);

    $area1->services()->attach($service->id);
    $area2->services()->attach($service->id);

    $this->get('/areas/city-a-co/shared-service')
        ->assertSuccessful()
        ->assertSee('City B');
});

it('includes schema.org structured data', function () {
    $area = ServiceArea::factory()->create(['slug' => 'schema-city-co', 'city' => 'Schema City']);
    $service = Service::factory()->create(['slug' => 'schema-service', 'title' => 'Schema Service']);

    $this->get('/areas/schema-city-co/schema-service')
        ->assertSuccessful()
        ->assertSee('schema.org', escape: false)
        ->assertSee('"@type": "Service"', escape: false)
        ->assertSee('"@type": "BreadcrumbList"', escape: false);
});

it('includes FAQ structured data when service has FAQs', function () {
    $area = ServiceArea::factory()->create(['slug' => 'faq-city-co', 'city' => 'FAQ City']);
    $service = Service::factory()->create([
        'slug' => 'faq-service',
        'title' => 'FAQ Service',
        'faqs' => [
            ['q' => 'How long does it take?', 'a' => 'It depends on the scope.'],
        ],
    ]);

    $this->get('/areas/faq-city-co/faq-service')
        ->assertSuccessful()
        ->assertSee('"@type": "FAQPage"', escape: false)
        ->assertSee('How long does it take?', escape: false);
});

it('has correct meta title format', function () {
    $area = ServiceArea::factory()->create(['slug' => 'denver-meta-co', 'city' => 'Denver']);
    $service = Service::factory()->create(['slug' => 'meta-service', 'title' => 'Interior Painting']);

    $this->get('/areas/denver-meta-co/meta-service')
        ->assertSuccessful()
        ->assertSee('Interior Painting Denver CO | Painting Made Easy Denver', escape: false);
});

it('uses design system classes', function () {
    $area = ServiceArea::factory()->create(['slug' => 'design-co', 'city' => 'Design City']);
    $service = Service::factory()->create(['slug' => 'design-service']);

    $content = $this->get('/areas/design-co/design-service')->getContent();

    expect($content)->toContain('bg-brand-900')
        ->and($content)->toContain('font-heading');
});

it('displays the shared CTA section', function () {
    $area = ServiceArea::factory()->create(['slug' => 'cta-city-co', 'city' => 'CTA City']);
    $service = Service::factory()->create(['slug' => 'cta-service', 'title' => 'Cabinet Painting']);

    $this->get('/areas/cta-city-co/cta-service')
        ->assertSuccessful()
        ->assertSee('Get Cabinet Painting in CTA City')
        ->assertSee('Get Your Free Estimate');
});

it('includes the trust strip', function () {
    $area = ServiceArea::factory()->create(['slug' => 'trust-city-co', 'city' => 'Trust City']);
    $service = Service::factory()->create(['slug' => 'trust-service']);

    $this->get('/areas/trust-city-co/trust-service')
        ->assertSuccessful()
        ->assertSee('Licensed')
        ->assertSee('5-Star Rated');
});

it('displays service overview content', function () {
    $area = ServiceArea::factory()->create(['slug' => 'overview-city-co']);
    $service = Service::factory()->create([
        'slug' => 'overview-service',
        'title' => 'Interior Painting',
        'overview' => 'We provide excellent interior painting for all homes.',
    ]);

    $this->get('/areas/overview-city-co/overview-service')
        ->assertSuccessful()
        ->assertSee('Interior Painting Overview')
        ->assertSee('We provide excellent interior painting for all homes.');
});

it('includes breadcrumb navigation', function () {
    $area = ServiceArea::factory()->create(['slug' => 'bread-city-co', 'city' => 'Bread City']);
    $service = Service::factory()->create(['slug' => 'bread-service', 'title' => 'Deck Staining']);

    $this->get('/areas/bread-city-co/bread-service')
        ->assertSuccessful()
        ->assertSee('Service Areas')
        ->assertSee('Bread City')
        ->assertSee('Deck Staining');
});
