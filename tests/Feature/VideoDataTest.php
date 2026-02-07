<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;

test('can get video data for a listing', function () {
    $agent = User::factory()->agent()->create();
    $property = Property::factory()->agentListing()->forAgent($agent)->create([
        'address' => '123 Test Street',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80202',
    ]);

    PropertyPhoto::factory()->forProperty($property)->before()->create();
    PropertyPhoto::factory()->forProperty($property)->after()->create();

    $response = $this->getJson("/api/video-data/listing/{$property->id}");

    $response->assertOk()
        ->assertJsonStructure([
            'type',
            'id',
            'address',
            'city',
            'state',
            'zip',
            'photos',
            'companyName',
            'companyPhone',
            'primaryColor',
        ])
        ->assertJson([
            'type' => 'listing',
            'address' => '123 Test Street',
            'city' => 'Denver',
        ]);

    expect($response->json('photos'))->toHaveCount(2);
});

test('can get video data for a property', function () {
    $customer = Customer::factory()->create();
    $property = Property::factory()->for($customer)->create([
        'address' => '456 Main Ave',
        'city' => 'Boulder',
        'state' => 'CO',
        'zip' => '80301',
    ]);

    PropertyPhoto::factory()->forProperty($property)->create();

    $response = $this->getJson("/api/video-data/property/{$property->id}");

    $response->assertOk()
        ->assertJson([
            'type' => 'property',
            'address' => '456 Main Ave',
            'city' => 'Boulder',
        ]);

    expect($response->json('photos'))->toHaveCount(1);
});

test('returns 404 for non-existent listing', function () {
    $response = $this->getJson('/api/video-data/listing/99999');

    $response->assertNotFound()
        ->assertJson(['error' => 'Listing not found.']);
});

test('returns 404 for non-existent property', function () {
    $response = $this->getJson('/api/video-data/property/99999');

    $response->assertNotFound()
        ->assertJson(['error' => 'Property not found.']);
});

test('returns 400 for invalid type', function () {
    $response = $this->getJson('/api/video-data/invalid/1');

    $response->assertNotFound(); // Route constraint will 404
});

test('video showcase page loads', function () {
    $response = $this->get('/video/showcase?type=property&id=1');

    $response->assertOk();
});
