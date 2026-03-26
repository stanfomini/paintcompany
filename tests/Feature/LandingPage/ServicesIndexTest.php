<?php

use App\Models\Service;

it('loads the services index page', function () {
    Service::factory()->residential()->create(['title' => 'Test Service']);

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Painting Services Guide');
});

it('displays category sections from database', function () {
    Service::factory()->residential()->create(['title' => 'Interior House Painting']);
    Service::factory()->commercial()->create(['title' => 'Commercial Interior Painting']);
    Service::factory()->specialty()->create(['title' => 'Pressure Washing']);

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Residential Painting')
        ->assertSee('Commercial Painting')
        ->assertSee('Specialty Services');
});

it('displays services from the Service model', function () {
    Service::factory()->residential()->create(['title' => 'Interior House Painting', 'slug' => 'interior-painting']);
    Service::factory()->residential()->create(['title' => 'Exterior House Painting', 'slug' => 'exterior-painting']);
    Service::factory()->commercial()->create(['title' => 'Commercial Interior Painting', 'slug' => 'commercial-interior']);

    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('Interior House Painting')
        ->assertSee('Exterior House Painting')
        ->assertSee('Commercial Interior Painting');
});

it('does not display inactive services', function () {
    Service::factory()->residential()->create(['title' => 'Active Service']);
    Service::factory()->residential()->inactive()->create(['title' => 'Hidden Service']);

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Active Service')
        ->assertDontSee('Hidden Service');
});

it('includes links to individual service pages', function () {
    Service::factory()->create(['slug' => 'interior-painting']);
    Service::factory()->create(['slug' => 'commercial-interior']);

    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('/services/interior-painting', false)
        ->assertSee('/services/commercial-interior', false);
});

it('includes structured data with ItemList schema', function () {
    Service::factory()->count(5)->create();

    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type": "ItemList"', false)
        ->assertSee('"numberOfItems": 5', false);
});

it('includes meta description', function () {
    Service::factory()->create();

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Denver painting services guide', false);
});

it('includes the CTA section', function () {
    Service::factory()->create();

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Not Sure What You Need?')
        ->assertSee('Get Your Free Estimate');
});

it('includes the trust strip', function () {
    Service::factory()->create();

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Licensed')
        ->assertSee('Insured');
});

it('has correct page title', function () {
    Service::factory()->create();

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Painting Services Guide | Painting Made Easy Denver', false);
});

it('displays service taglines', function () {
    Service::factory()->create([
        'title' => 'Test Painting',
        'tagline' => 'Transform your home with a fresh coat of paint',
    ]);

    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Transform your home with a fresh coat of paint');
});

it('groups services by category', function () {
    Service::factory()->residential()->count(3)->create();
    Service::factory()->commercial()->count(2)->create();

    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('Residential Painting')
        ->assertSee('Commercial Painting');
});
