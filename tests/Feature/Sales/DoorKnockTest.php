<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Invitation;
use App\Models\Property;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\DoorKnockService;
use App\Services\LocationIQService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('records a door knock', function () {
    $user = User::factory()->internal()->create();
    $territory = SalesTerritory::factory()->create();

    $service = app(DoorKnockService::class);
    $knock = $service->recordKnock($user, [
        'territory_id' => $territory->id,
        'address' => '123 Main St',
        'city' => 'Springfield',
        'state' => 'IL',
        'zip' => '62701',
        'lat' => 39.7817,
        'lng' => -89.6501,
        'status' => 'not_home',
    ]);

    expect($knock)->toBeInstanceOf(DoorKnock::class);
    $this->assertDatabaseHas('door_knocks', [
        'id' => $knock->id,
        'user_id' => $user->id,
        'territory_id' => $territory->id,
        'address' => '123 Main St',
        'status' => 'not_home',
    ]);
});

it('auto-resolves territory from coordinates', function () {
    // Create territory with known boundary around (35.0, -90.0)
    $territory = SalesTerritory::factory()->create([
        'status' => 'active',
        'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-90.05, 34.95],
                [-89.95, 34.95],
                [-89.95, 35.05],
                [-90.05, 35.05],
                [-90.05, 34.95],
            ]],
        ],
        'center_lat' => 35.0,
        'center_lng' => -90.0,
    ]);

    $user = User::factory()->internal()->create();
    $service = app(DoorKnockService::class);

    // Record knock inside the territory boundary (no territory_id provided)
    $knock = $service->recordKnock($user, [
        'address' => '456 Elm St',
        'city' => 'Memphis',
        'state' => 'TN',
        'zip' => '38101',
        'lat' => 35.0,
        'lng' => -90.0,
        'status' => 'interested',
    ]);

    expect($knock->territory_id)->toBe($territory->id);
});

it('filters knocks by viewport bounds', function () {
    $territory = SalesTerritory::factory()->create();
    $user = User::factory()->internal()->create();

    // Knock inside viewport
    $insideKnock = DoorKnock::factory()->forTerritory($territory)->forUser($user)->create([
        'lat' => 35.0,
        'lng' => -90.0,
    ]);

    // Knock outside viewport
    DoorKnock::factory()->forTerritory($territory)->forUser($user)->create([
        'lat' => 40.0,
        'lng' => -80.0,
    ]);

    $service = app(DoorKnockService::class);
    $results = $service->getKnocksInViewport(
        neLat: 36.0,
        neLng: -89.0,
        swLat: 34.0,
        swLng: -91.0,
    );

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($insideKnock->id);
});

it('converts a knock with email to lead and sends invitation', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    $knock = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '789 Oak Ave',
        'city' => 'Memphis',
        'state' => 'TN',
        'zip' => '38103',
        'lat' => 35.1495,
        'lng' => -90.049,
        'status' => 'interested',
        'customer_name' => 'Jane Smith',
        'phone' => '555-123-4567',
        'email' => 'jane@example.com',
        'notes' => 'Needs exterior paint',
    ]);

    $service = app(DoorKnockService::class);
    $deal = $service->convertToLead($knock);

    // Assert deal created
    expect($deal)->toBeInstanceOf(Deal::class);
    expect($deal->title)->toContain('789 Oak Ave');
    expect($deal->stage)->toBe('lead');
    expect($deal->assigned_to)->toBe($user->id);

    // Assert customer created with door_knock source
    $this->assertDatabaseHas('customers', [
        'email' => 'jane@example.com',
        'phone' => '555-123-4567',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'source' => 'door_knock',
    ]);

    // Assert property created
    $this->assertDatabaseHas('properties', [
        'address' => '789 Oak Ave',
        'city' => 'Memphis',
    ]);

    // Assert knock updated with references
    $knock->refresh();
    expect($knock->deal_id)->toBe($deal->id);
    expect($knock->property_id)->not->toBeNull();
    expect($knock->customer_id)->not->toBeNull();

    // Assert invitation was created and sent (has email)
    $this->assertDatabaseHas('invitations', [
        'email' => 'jane@example.com',
        'invited_user_type' => 'customer',
        'inviter_id' => $user->id,
        'deal_id' => $deal->id,
    ]);
    Notification::assertSentTo(
        Invitation::where('email', 'jane@example.com')->first(),
        InvitationNotification::class
    );
});

it('converts a knock with name and phone only - no invitation sent', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    $knock = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '101 Pine St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80202',
        'lat' => 39.75,
        'lng' => -105.0,
        'status' => 'interested',
        'customer_name' => 'Bob Jones',
        'phone' => '555-999-8888',
        'email' => null,
    ]);

    $service = app(DoorKnockService::class);
    $deal = $service->convertToLead($knock);

    // Assert customer created by phone lookup
    $this->assertDatabaseHas('customers', [
        'phone' => '555-999-8888',
        'first_name' => 'Bob',
        'last_name' => 'Jones',
        'source' => 'door_knock',
    ]);

    // Assert deal created
    expect($deal->customer_id)->not->toBeNull();

    // Assert NO invitation was sent (no email)
    $this->assertDatabaseMissing('invitations', [
        'inviter_id' => $user->id,
        'invited_user_type' => 'customer',
    ]);
    Notification::assertNothingSent();
});

it('converts a knock with email only - sends invitation', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    $knock = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '202 Birch Ln',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80203',
        'lat' => 39.73,
        'lng' => -104.98,
        'status' => 'appointment_set',
        'customer_name' => null,
        'phone' => null,
        'email' => 'newlead@example.com',
    ]);

    $service = app(DoorKnockService::class);
    $deal = $service->convertToLead($knock);

    // Assert customer created
    $this->assertDatabaseHas('customers', [
        'email' => 'newlead@example.com',
        'source' => 'door_knock',
    ]);

    // Assert invitation sent
    $this->assertDatabaseHas('invitations', [
        'email' => 'newlead@example.com',
        'invited_user_type' => 'customer',
    ]);
    Notification::assertSentTo(
        Invitation::where('email', 'newlead@example.com')->first(),
        InvitationNotification::class
    );
});

it('does not send duplicate invitation if user already exists', function () {
    $user = User::factory()->internal()->create();
    $existingCustomerUser = User::factory()->customer()->create(['email' => 'existing@example.com']);

    // Fake after user creation to avoid catching NewAccountCreated from factory
    Notification::fake();

    $knock = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '303 Cedar Dr',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80204',
        'lat' => 39.74,
        'lng' => -105.01,
        'status' => 'interested',
        'customer_name' => 'Existing Person',
        'email' => 'existing@example.com',
    ]);

    $service = app(DoorKnockService::class);
    $deal = $service->convertToLead($knock);

    // Deal should still be created
    expect($deal)->toBeInstanceOf(Deal::class);

    // But no invitation should be created (user already exists)
    $this->assertDatabaseMissing('invitations', [
        'email' => 'existing@example.com',
        'inviter_id' => $user->id,
    ]);
    Notification::assertNothingSent();
});

it('detects address without dropping a pin', function () {
    $user = User::factory()->internal()->create();

    $mockService = Mockery::mock(LocationIQService::class);
    $mockService->shouldReceive('getCachedOrFetch')
        ->once()
        ->with(39.7817, -89.6501)
        ->andReturn([
            'address' => '123 Main St',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
        ]);

    app()->instance(LocationIQService::class, $mockService);

    Livewire::actingAs($user)
        ->test('sales.door-knock-map')
        ->call('detectAddress', 39.7817, -89.6501)
        ->assertDispatched('address-detected', fn ($name, $params) => $params['address'] === '123 Main St')
        ->assertSet('pinDropped', false)
        ->assertSet('showForm', false);
});

it('detects address gracefully when geocoding fails', function () {
    $user = User::factory()->internal()->create();

    $mockService = Mockery::mock(LocationIQService::class);
    $mockService->shouldReceive('getCachedOrFetch')
        ->once()
        ->andThrow(new \Exception('API timeout'));

    app()->instance(LocationIQService::class, $mockService);

    Livewire::actingAs($user)
        ->test('sales.door-knock-map')
        ->call('detectAddress', 39.7817, -89.6501)
        ->assertDispatched('address-detected')
        ->assertSet('pinDropped', false)
        ->assertSet('showForm', false);
});
