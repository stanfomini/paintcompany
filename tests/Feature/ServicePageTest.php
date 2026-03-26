<?php

use App\Models\CompanySetting;
use App\Models\Service;
use App\Models\ServiceArea;

it('renders a residential service page', function () {
    $service = Service::factory()->residential()->create([
        'title' => 'Interior House Painting',
        'slug' => 'interior-painting',
        'tagline' => 'Transform any room with fresh paint',
    ]);

    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Interior House Painting')
        ->assertSee('Residential')
        ->assertSee('Transform any room');
});

it('renders a commercial service page', function () {
    $service = Service::factory()->commercial()->create([
        'title' => 'Commercial Interior Painting',
        'slug' => 'commercial-interior',
    ]);

    $this->get('/services/commercial-interior')
        ->assertOk()
        ->assertSee('Commercial Interior Painting')
        ->assertSee('Commercial');
});

it('renders a specialty service page', function () {
    $service = Service::factory()->specialty()->create([
        'title' => 'Line Striping',
        'slug' => 'line-striping',
    ]);

    $this->get('/services/line-striping')
        ->assertOk()
        ->assertSee('Line Striping')
        ->assertSee('Specialty');
});

it('returns 404 for invalid service slug', function () {
    $this->get('/services/nonexistent-service')
        ->assertNotFound();
});

it('returns 404 for inactive service', function () {
    Service::factory()->inactive()->create(['slug' => 'disabled-service']);

    $this->get('/services/disabled-service')
        ->assertNotFound();
});

it('displays step by step guide section', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'steps' => [
            ['title' => 'Room Assessment', 'description' => 'Evaluate the space'],
            ['title' => 'Color Selection', 'description' => 'Choose your colors'],
        ],
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Step-by-Step Guide')
        ->assertSee('Room Assessment')
        ->assertSee('Color Selection');
});

it('displays materials and tools section', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'materials' => [
            'Paint and Primers' => ['Latex paint', 'Primer'],
            'Application Tools' => ['Roller', 'Brush'],
        ],
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Materials')
        ->assertSee('Paint and Primers')
        ->assertSee('Application Tools');
});

it('displays pro tips section', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'tips' => [
            ['title' => 'Use Floetrol', 'description' => 'Helps in dry Denver climate'],
        ],
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Denver Pro Tips')
        ->assertSee('Floetrol');
});

it('displays faq section', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'faqs' => [
            ['q' => 'How long does interior paint last?', 'a' => 'About 5-7 years with proper care.'],
        ],
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Frequently Asked Questions')
        ->assertSee('How long does interior paint last');
});

it('displays cost factors section', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'cost_factors' => ['Room size', 'Wall condition'],
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('What Affects Pricing')
        ->assertSee('Room size');
});

it('displays related services section', function () {
    $main = Service::factory()->residential()->create(['slug' => 'main-service']);
    Service::factory()->residential()->create(['title' => 'Related One']);
    Service::factory()->residential()->create(['title' => 'Related Two']);

    $this->get('/services/main-service')
        ->assertOk()
        ->assertSee('Related Services')
        ->assertSee('Related One')
        ->assertSee('Related Two');
});

it('includes structured data', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'faqs' => [
            ['q' => 'A question?', 'a' => 'An answer.'],
        ],
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type": "Service"', false)
        ->assertSee('"@type": "HowTo"', false)
        ->assertSee('"@type": "FAQPage"', false);
});

it('includes meta description from model', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'meta_description' => 'Professional painting services in Denver CO area',
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Professional painting services in Denver CO area');
});

it('includes breadcrumb navigation', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'title' => 'Test Painting Service',
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Home')
        ->assertSee('Services')
        ->assertSee('Test Painting Service');
});

it('includes cta section', function () {
    CompanySetting::create(['company_phone' => '(720) 999-9725']);
    Service::factory()->create(['slug' => 'test-service']);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('(720) 999-9725')
        ->assertSee('Get Your Free Estimate');
});

it('displays H1 with Denver and Front Range', function () {
    Service::factory()->create([
        'slug' => 'test-service',
        'title' => 'Interior Painting',
    ]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Interior Painting in Denver', false);
});

it('displays area cross-links section', function () {
    $service = Service::factory()->create(['slug' => 'test-service', 'title' => 'Interior Painting']);
    ServiceArea::factory()->create(['city' => 'Aurora', 'slug' => 'aurora', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Boulder', 'slug' => 'boulder', 'is_active' => true]);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('Get Interior Painting in Your City')
        ->assertSee('Interior Painting in Aurora')
        ->assertSee('Interior Painting in Boulder');
});

it('does not show area cross-links when no areas exist', function () {
    Service::factory()->create(['slug' => 'test-service', 'title' => 'Exterior Painting']);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertDontSee('Get Exterior Painting in Your City');
});

it('shows related services from same category first', function () {
    $main = Service::factory()->residential()->create(['slug' => 'main-service', 'sort_order' => 1]);
    $sameCategory = Service::factory()->residential()->create(['title' => 'Same Cat Service', 'sort_order' => 2]);
    $differentCategory = Service::factory()->commercial()->create(['title' => 'Different Cat Service', 'sort_order' => 3]);

    $this->get('/services/main-service')
        ->assertOk()
        ->assertSee('Same Cat Service');
});

it('uses design system classes', function () {
    Service::factory()->create(['slug' => 'test-service']);

    $this->get('/services/test-service')
        ->assertOk()
        ->assertSee('bg-brand-900', false)
        ->assertSee('font-heading', false);
});
