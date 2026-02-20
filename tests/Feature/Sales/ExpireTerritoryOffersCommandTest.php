<?php

use App\Models\TerritoryOffer;

it('expires past-due territory offers', function () {
    $offer = TerritoryOffer::factory()->create([
        'status' => 'pending',
        'offered_at' => now()->subHours(3),
        'expires_at' => now()->subHour(),
    ]);

    $this->artisan('territory:expire-offers')
        ->assertSuccessful();

    $offer->refresh();
    expect($offer->status)->toBe('expired');
});

it('does not expire future offers', function () {
    $offer = TerritoryOffer::factory()->pending()->create([
        'expires_at' => now()->addHours(2),
    ]);

    $this->artisan('territory:expire-offers')
        ->assertSuccessful();

    $offer->refresh();
    expect($offer->status)->toBe('pending');
});
