<?php

use App\Livewire\AgentListings\CreateModal;
use App\Models\User;
use App\Services\ListingScraperService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
});

it('shows url import section when scraping is available', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true);

    Livewire::actingAs($this->user)
        ->test(CreateModal::class)
        ->call('openModal')
        ->assertSee('Import from Listing URL');
});

it('hides url import section when scraping is unavailable', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(false);

    Livewire::actingAs($this->user)
        ->test(CreateModal::class)
        ->call('openModal')
        ->assertDontSee('Import from Listing URL');
});

it('shows error when import url is empty', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true);

    Livewire::actingAs($this->user)
        ->test(CreateModal::class)
        ->set('importUrl', '')
        ->call('importFromUrl')
        ->assertSet('importError', 'Please enter a listing URL.');
});

it('populates form fields from scraped data', function () {
    $mockData = [
        'success' => true,
        'data' => [
            'address' => '456 Oak Ave',
            'city' => 'Houston',
            'state' => 'TX',
            'zip' => '77001',
            'listing_price' => 525000,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'square_footage' => 1800,
            'year_built' => 2010,
            'description' => 'Modern townhouse',
            'source' => 'realtor',
            'photos' => ['https://example.com/photo1.jpg', 'https://example.com/photo2.jpg'],
        ],
    ];

    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true)
        ->shouldReceive('scrapeAndMap')
        ->with('https://realtor.com/property/456')
        ->andReturn($mockData);

    Livewire::actingAs($this->user)
        ->test(CreateModal::class)
        ->set('importUrl', 'https://realtor.com/property/456')
        ->call('importFromUrl')
        ->assertSet('address', '456 Oak Ave')
        ->assertSet('city', 'Houston')
        ->assertSet('state', 'TX')
        ->assertSet('zip', '77001')
        ->assertSet('listingPrice', '525000')
        ->assertSet('description', 'Modern townhouse')
        ->assertSet('importSource', 'realtor')
        ->assertSet('importedPhotos', ['https://example.com/photo1.jpg', 'https://example.com/photo2.jpg']);
});

it('adds property details to notes when importing', function () {
    $mockData = [
        'success' => true,
        'data' => [
            'address' => '789 Pine Rd',
            'city' => 'Dallas',
            'state' => 'TX',
            'zip' => '75201',
            'bedrooms' => 5,
            'bathrooms' => 4,
            'square_footage' => 3200,
            'year_built' => 2015,
            'mls_number' => 'MLS12345',
            'source' => 'zillow',
            'photos' => [],
        ],
    ];

    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true)
        ->shouldReceive('scrapeAndMap')
        ->with('https://zillow.com/homedetails/789')
        ->andReturn($mockData);

    $component = Livewire::actingAs($this->user)
        ->test(CreateModal::class)
        ->set('importUrl', 'https://zillow.com/homedetails/789')
        ->call('importFromUrl');

    expect($component->get('notes'))
        ->toContain('Imported from zillow')
        ->toContain('Bedrooms: 5')
        ->toContain('Bathrooms: 4')
        ->toContain('Square footage: 3,200')
        ->toContain('Year built: 2015')
        ->toContain('MLS #: MLS12345');
});

it('shows error when scraping fails', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true)
        ->shouldReceive('scrapeAndMap')
        ->with('https://realtor.com/invalid')
        ->andReturn([
            'success' => false,
            'error' => 'Could not find listing data.',
        ]);

    Livewire::actingAs($this->user)
        ->test(CreateModal::class)
        ->set('importUrl', 'https://realtor.com/invalid')
        ->call('importFromUrl')
        ->assertSet('importError', 'Could not find listing data.');
});

it('resets import fields when modal is closed', function () {
    $this->mock(ListingScraperService::class)
        ->shouldReceive('isAvailable')
        ->andReturn(true);

    Livewire::actingAs($this->user)
        ->test(CreateModal::class)
        ->set('importUrl', 'https://zillow.com/test')
        ->set('importError', 'Some error')
        ->set('importSource', 'zillow')
        ->set('importedPhotos', ['photo1.jpg', 'photo2.jpg'])
        ->call('closeModal')
        ->assertSet('importUrl', '')
        ->assertSet('importError', null)
        ->assertSet('importSource', null)
        ->assertSet('importedPhotos', []);
});
