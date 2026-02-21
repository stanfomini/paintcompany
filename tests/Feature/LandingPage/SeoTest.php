<?php

it('has proper page title', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Professional House Painting Denver', false);
});

it('includes meta description', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('<meta name="description"', false);
});

it('includes open graph tags', function () {
    $response = $this->get('/');

    $response->assertSuccessful()
        ->assertSee('<meta property="og:title"', false)
        ->assertSee('<meta property="og:description"', false)
        ->assertSee('<meta property="og:url"', false)
        ->assertSee('<meta property="og:type"', false);
});

it('includes twitter card tags', function () {
    $response = $this->get('/');

    $response->assertSuccessful()
        ->assertSee('<meta name="twitter:card"', false)
        ->assertSee('<meta name="twitter:title"', false)
        ->assertSee('<meta name="twitter:description"', false);
});

it('includes JSON-LD structured data', function () {
    $response = $this->get('/');

    $response->assertSuccessful()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type": "LocalBusiness"', false)
        ->assertSee('"@type": "FAQPage"', false);
});

it('includes canonical URL', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('<link rel="canonical"', false);
});

it('includes robots meta tag', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('<meta name="robots"', false);
});

it('renders the FAQ section', function () {
    $response = $this->get('/');

    $response->assertSuccessful()
        ->assertSee('Frequently Asked Questions')
        ->assertSee('How long does it take to paint a house?')
        ->assertSee('What paint brands do you use?')
        ->assertSee('Do I need to move my furniture?')
        ->assertSee('How do virtual estimates work?')
        ->assertSee('Are you licensed and insured?')
        ->assertSee('What is included in your guarantee?')
        ->assertSee('How much does it cost to paint a house in Denver?');
});

it('includes service structured data', function () {
    $response = $this->get('/');

    $response->assertSuccessful()
        ->assertSee('"@type": "Service"', false)
        ->assertSee('Interior House Painting', false)
        ->assertSee('Exterior House Painting', false);
});
