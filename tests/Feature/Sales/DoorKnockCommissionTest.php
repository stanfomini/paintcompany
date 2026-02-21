<?php

use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\DoorKnockService;

it('sets origin_door_knock_id when converting knock to lead', function () {
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    $knock = DoorKnock::factory()->appointmentSet()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
    ]);

    $service = app(DoorKnockService::class);
    $deal = $service->convertToLead($knock);

    expect($deal->origin_door_knock_id)->toBe($knock->id);
    expect($deal->originDoorKnock->id)->toBe($knock->id);
});
