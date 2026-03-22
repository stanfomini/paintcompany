<?php

it('renders the interior painting service page', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Interior House Painting')
        ->assertSee('Residential')
        ->assertSee('Transform any room');
});

it('renders the exterior painting service page', function () {
    $this->get('/services/exterior-painting')
        ->assertOk()
        ->assertSee('Exterior House Painting')
        ->assertSee('Residential');
});

it('renders the cabinet painting service page', function () {
    $this->get('/services/cabinet-painting')
        ->assertOk()
        ->assertSee('Cabinet Painting')
        ->assertSee('Residential');
});

it('renders the deck fence staining service page', function () {
    $this->get('/services/deck-fence-staining')
        ->assertOk()
        ->assertSee('Deck')
        ->assertSee('Fence Staining');
});

it('renders the garage floor coating service page', function () {
    $this->get('/services/garage-floor-coating')
        ->assertOk()
        ->assertSee('Garage Floor Coating')
        ->assertSee('Residential');
});

it('renders the popcorn ceiling removal service page', function () {
    $this->get('/services/popcorn-ceiling-removal')
        ->assertOk()
        ->assertSee('Popcorn Ceiling Removal');
});

it('renders the drywall repair service page', function () {
    $this->get('/services/drywall-repair')
        ->assertOk()
        ->assertSee('Drywall Repair');
});

it('renders the trim door painting service page', function () {
    $this->get('/services/trim-door-painting')
        ->assertOk()
        ->assertSee('Trim, Door');
});

it('renders the wallpaper removal service page', function () {
    $this->get('/services/wallpaper-removal')
        ->assertOk()
        ->assertSee('Wallpaper Removal');
});

it('renders the commercial interior service page', function () {
    $this->get('/services/commercial-interior')
        ->assertOk()
        ->assertSee('Commercial Interior Painting')
        ->assertSee('Commercial');
});

it('renders the commercial exterior service page', function () {
    $this->get('/services/commercial-exterior')
        ->assertOk()
        ->assertSee('Commercial Exterior Painting');
});

it('renders the warehouse industrial service page', function () {
    $this->get('/services/warehouse-industrial')
        ->assertOk()
        ->assertSee('Warehouse')
        ->assertSee('Industrial');
});

it('renders the hoa multiunit service page', function () {
    $this->get('/services/hoa-multiunit')
        ->assertOk()
        ->assertSee('HOA')
        ->assertSee('Multi-Unit');
});

it('renders the line striping service page', function () {
    $this->get('/services/line-striping')
        ->assertOk()
        ->assertSee('Line Striping')
        ->assertSee('Specialty');
});

it('renders the pressure washing service page', function () {
    $this->get('/services/pressure-washing')
        ->assertOk()
        ->assertSee('Pressure Washing');
});

it('renders the color consultation service page', function () {
    $this->get('/services/color-consultation')
        ->assertOk()
        ->assertSee('Color Consultation');
});

it('renders the stucco coating service page', function () {
    $this->get('/services/stucco-coating')
        ->assertOk()
        ->assertSee('Elastomeric')
        ->assertSee('Stucco');
});

it('returns 404 for invalid service slug', function () {
    $this->get('/services/nonexistent-service')
        ->assertNotFound();
});

it('displays step by step guide section', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Step-by-Step Guide')
        ->assertSee('Room Assessment and Planning')
        ->assertSee('Color Selection and Paint Purchase');
});

it('displays materials and tools section', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Materials')
        ->assertSee('Paint and Primers')
        ->assertSee('Application Tools');
});

it('displays pro tips section', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Denver Pro Tips')
        ->assertSee('Floetrol');
});

it('displays faq section', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Frequently Asked Questions')
        ->assertSee('How long does interior paint last');
});

it('displays cost factors section', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('What Affects Pricing');
});

it('displays related services section', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Related Services');
});

it('includes structured data', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type": "Service"', false)
        ->assertSee('"@type": "HowTo"', false)
        ->assertSee('"@type": "FAQPage"', false);
});

it('includes meta description', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Professional interior house painting in Denver');
});

it('includes breadcrumb navigation', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('Home')
        ->assertSee('Services')
        ->assertSee('Interior House Painting');
});

it('includes cta with phone number', function () {
    $this->get('/services/interior-painting')
        ->assertOk()
        ->assertSee('(720) 999-9725')
        ->assertSee('Get Your Free Estimate');
});
