<?php

it('loads the services index page', function () {
    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Painting Services Guide');
});

it('displays all three category sections', function () {
    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Residential Painting')
        ->assertSee('Commercial Painting')
        ->assertSee('Specialty Services');
});

it('displays all 9 residential services', function () {
    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('Interior House Painting')
        ->assertSee('Exterior House Painting')
        ->assertSee('Cabinet Painting')
        ->assertSee('Deck &amp; Fence Staining', false)
        ->assertSee('Garage Floor Coating')
        ->assertSee('Popcorn Ceiling Removal')
        ->assertSee('Drywall Repair')
        ->assertSee('Trim, Door &amp; Molding Painting', false)
        ->assertSee('Wallpaper Removal');
});

it('displays all 4 commercial services', function () {
    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('Commercial Interior Painting')
        ->assertSee('Commercial Exterior Painting')
        ->assertSee('Warehouse &amp; Industrial Painting', false)
        ->assertSee('HOA &amp; Multi-Unit Painting', false);
});

it('displays all 4 specialty services', function () {
    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('Parking Lot &amp; Line Striping', false)
        ->assertSee('Pressure Washing')
        ->assertSee('Color Consultation')
        ->assertSee('Elastomeric &amp; Stucco Coating', false);
});

it('includes links to individual service pages', function () {
    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('/services/interior-painting', false)
        ->assertSee('/services/exterior-painting', false)
        ->assertSee('/services/cabinet-painting', false)
        ->assertSee('/services/commercial-interior', false)
        ->assertSee('/services/line-striping', false);
});

it('includes structured data with ItemList schema', function () {
    $response = $this->get('/services');

    $response->assertSuccessful()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type": "ItemList"', false)
        ->assertSee('"numberOfItems": 17', false);
});

it('includes meta description', function () {
    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Complete painting services guide for Denver homeowners', false);
});

it('includes the CTA section', function () {
    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Not Sure What You Need?')
        ->assertSee('Get Your Free Estimate');
});

it('includes social proof strip', function () {
    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('4.9/5')
        ->assertSee('(720) 999-9725');
});

it('has correct page title', function () {
    $this->get('/services')
        ->assertSuccessful()
        ->assertSee('Painting Services Guide | Painting Made Easy Denver', false);
});
