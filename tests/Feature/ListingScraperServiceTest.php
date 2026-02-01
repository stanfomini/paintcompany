<?php

use App\Services\ListingScraperService;
use App\Services\PythonBridgeService;

it('returns unavailable when python service is disabled', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(false);
    $python->shouldReceive('healthCheck')->andReturn(false);

    $service = new ListingScraperService($python);

    expect($service->isAvailable())->toBeFalse();
});

it('returns unavailable when health check fails', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(false);

    $service = new ListingScraperService($python);

    expect($service->isAvailable())->toBeFalse();
});

it('returns available when python service is ready', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);

    $service = new ListingScraperService($python);

    expect($service->isAvailable())->toBeTrue();
});

it('validates zillow urls', function () {
    $python = $this->mock(PythonBridgeService::class);
    $service = new ListingScraperService($python);

    expect($service->isValidListingUrl('https://www.zillow.com/homedetails/123'))->toBeTrue();
    expect($service->isValidListingUrl('https://zillow.com/homes/123'))->toBeTrue();
    expect($service->isValidListingUrl('https://ZILLOW.COM/homes/123'))->toBeTrue();
});

it('validates realtor urls', function () {
    $python = $this->mock(PythonBridgeService::class);
    $service = new ListingScraperService($python);

    expect($service->isValidListingUrl('https://www.realtor.com/realestateandhomes-detail/123'))->toBeTrue();
    expect($service->isValidListingUrl('https://realtor.com/property/123'))->toBeTrue();
});

it('rejects invalid urls', function () {
    $python = $this->mock(PythonBridgeService::class);
    $service = new ListingScraperService($python);

    expect($service->isValidListingUrl('https://example.com'))->toBeFalse();
    expect($service->isValidListingUrl('https://google.com'))->toBeFalse();
    expect($service->isValidListingUrl('not-a-url'))->toBeFalse();
});

it('detects source from url', function () {
    $python = $this->mock(PythonBridgeService::class);
    $service = new ListingScraperService($python);

    expect($service->detectSource('https://www.zillow.com/homedetails/123'))->toBe('zillow');
    expect($service->detectSource('https://www.realtor.com/property/123'))->toBe('realtor');
    expect($service->detectSource('https://example.com'))->toBeNull();
});

it('returns error when service is unavailable', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(false);
    $python->shouldReceive('healthCheck')->andReturn(false);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('Scraping service is not available.');
});

it('returns error for invalid url', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://example.com/property/123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('Please enter a valid Zillow or Realtor.com listing URL.');
});

it('parses full address correctly', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);
    $python->shouldReceive('scrapeListing')->andReturn([
        'success' => true,
        'data' => [
            'address' => '123 Main St, Austin, TX 78701',
            'price' => 450000,
        ],
    ]);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['address'])->toBe('123 Main St');
    expect($result['data']['city'])->toBe('Austin');
    expect($result['data']['state'])->toBe('TX');
    expect($result['data']['zip'])->toBe('78701');
});

it('parses address without comma before state', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);
    $python->shouldReceive('scrapeListing')->andReturn([
        'success' => true,
        'data' => [
            'address' => '456 Oak Ave, Houston TX 77001',
        ],
    ]);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/456');

    expect($result['data']['address'])->toBe('456 Oak Ave');
    expect($result['data']['city'])->toBe('Houston');
    expect($result['data']['state'])->toBe('TX');
    expect($result['data']['zip'])->toBe('77001');
});

it('maps property type to residential by default', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);
    $python->shouldReceive('scrapeListing')->andReturn([
        'success' => true,
        'data' => [
            'address' => '123 Main St',
            'property_type' => 'Single Family',
        ],
    ]);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/123');

    expect($result['data']['type'])->toBe('residential');
});

it('maps commercial property types correctly', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);
    $python->shouldReceive('scrapeListing')->andReturn([
        'success' => true,
        'data' => [
            'address' => '100 Business Pkwy',
            'property_type' => 'Commercial Office',
        ],
    ]);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/100');

    expect($result['data']['type'])->toBe('commercial');
});

it('maps industrial property types correctly', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);
    $python->shouldReceive('scrapeListing')->andReturn([
        'success' => true,
        'data' => [
            'address' => '200 Factory Ln',
            'property_type' => 'Industrial Warehouse',
        ],
    ]);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/200');

    expect($result['data']['type'])->toBe('industrial');
});

it('handles python scraping failure', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);
    $python->shouldReceive('scrapeListing')->andReturn([
        'success' => false,
        'error' => 'Page not found.',
    ]);

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/404');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('Page not found.');
});

it('handles exceptions gracefully', function () {
    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('healthCheck')->andReturn(true);
    $python->shouldReceive('scrapeListing')->andThrow(new Exception('Network error'));

    $service = new ListingScraperService($python);
    $result = $service->scrapeAndMap('https://zillow.com/homedetails/error');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('An error occurred while scraping. Please try again.');
});
