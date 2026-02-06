<?php

use App\Models\User;
use App\Services\ListingScraperService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('shows url import section when scraping is available', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true);

    Livewire::actingAs($this->user)
        ->test('properties.create-modal')
        ->call('openModal')
        ->assertSee('Import from Listing URL');
});

it('hides url import section when scraping is unavailable', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(false);

    Livewire::actingAs($this->user)
        ->test('properties.create-modal')
        ->call('openModal')
        ->assertDontSee('Import from Listing URL');
});

it('shows error when import url is empty', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true);

    Livewire::actingAs($this->user)
        ->test('properties.create-modal')
        ->set('importUrl', '')
        ->call('importFromUrl')
        ->assertSet('importError', 'Please enter a listing URL.');
});

it('populates form fields from scraped data', function () {
    $mockData = [
        'success' => true,
        'data' => [
            'address' => '123 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'name' => '123 Main St',
            'type' => 'residential',
            'square_footage' => 2500,
            'year_built' => 1995,
            'bedrooms' => 4,
            'bathrooms' => 3,
            'listing_price' => 450000,
            'description' => 'Beautiful home',
            'source' => 'zillow',
            'photos' => ['https://example.com/photo1.jpg'],
        ],
    ];

    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true)
        ->shouldReceive('scrapeAndMap')
        ->with('https://zillow.com/homedetails/123')
        ->andReturn($mockData);

    Livewire::actingAs($this->user)
        ->test('properties.create-modal')
        ->set('importUrl', 'https://zillow.com/homedetails/123')
        ->call('importFromUrl')
        ->assertSet('address', '123 Main St')
        ->assertSet('city', 'Austin')
        ->assertSet('state', 'TX')
        ->assertSet('zip', '78701')
        ->assertSet('name', '123 Main St')
        ->assertSet('type', 'residential')
        ->assertSet('squareFootage', '2500')
        ->assertSet('yearBuilt', '1995')
        ->assertSet('importSource', 'zillow')
        ->assertSet('importedPhotos', ['https://example.com/photo1.jpg']);
});

it('shows error when scraping fails', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true)
        ->shouldReceive('scrapeAndMap')
        ->with('https://zillow.com/invalid')
        ->andReturn([
            'success' => false,
            'error' => 'Failed to scrape listing.',
        ]);

    Livewire::actingAs($this->user)
        ->test('properties.create-modal')
        ->set('importUrl', 'https://zillow.com/invalid')
        ->call('importFromUrl')
        ->assertSet('importError', 'Failed to scrape listing.');
});

it('resets import fields when modal is closed', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true);

    Livewire::actingAs($this->user)
        ->test('properties.create-modal')
        ->set('importUrl', 'https://zillow.com/test')
        ->set('importError', 'Some error')
        ->set('importSource', 'zillow')
        ->set('importedPhotos', ['photo.jpg'])
        ->call('closeModal')
        ->assertSet('importUrl', '')
        ->assertSet('importError', null)
        ->assertSet('importSource', null)
        ->assertSet('importedPhotos', []);
});
