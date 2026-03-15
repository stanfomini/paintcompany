<?php

use App\Models\Customer;
use App\Services\AppointmentBookingService;

it('generates an encrypted prefill token for a customer', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '3035551234',
    ]);

    $service = app(AppointmentBookingService::class);
    $token = $service->generatePrefillToken($customer);

    expect($token)->toBeString()->not->toBeEmpty();
});

it('decodes a valid prefill token back to customer data', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane@example.com',
        'phone' => '3035559999',
    ]);

    $service = app(AppointmentBookingService::class);
    $token = $service->generatePrefillToken($customer);
    $data = $service->decodePrefillToken($token);

    expect($data)->not->toBeNull()
        ->and($data['first_name'])->toBe('Jane')
        ->and($data['last_name'])->toBe('Smith')
        ->and($data['email'])->toBe('jane@example.com')
        ->and($data['phone'])->toBe('3035559999');
});

it('returns null for a tampered prefill token', function () {
    $service = app(AppointmentBookingService::class);
    $data = $service->decodePrefillToken('tampered-invalid-token');

    expect($data)->toBeNull();
});
