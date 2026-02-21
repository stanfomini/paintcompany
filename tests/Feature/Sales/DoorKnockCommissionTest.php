<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\CommissionService;
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

it('creates flat commission when knock status is appointment_set', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)
        ->create();

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create([
        'territory_id' => SalesTerritory::factory(),
    ]);

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toHaveCount(1);
    expect((float) $entries[0]->amount)->toBe(25.0);
    expect($entries[0]->commission_type)->toBe('flat');
    expect($entries[0]->status)->toBe('pending');
    expect($entries[0]->source_type)->toBe(DoorKnock::class);
    expect($entries[0]->source_id)->toBe($knock->id);
});

it('creates percentage commission for door knock', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('revenue_percentage', 0.04)
        ->create();

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create([
        'territory_id' => SalesTerritory::factory(),
    ]);

    // Create a deal linked to this knock with estimated value
    $this->actingAs($knocker);
    $deal = app(DoorKnockService::class)->convertToLead($knock);
    $deal->update(['estimated_value' => 5000]);
    $knock->refresh();

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toHaveCount(1);
    expect((float) $entries[0]->basis_amount)->toBe(5000.0);
    expect((float) $entries[0]->amount)->toBe(200.0); // 4% of 5000
});

it('skips commission when knocker has no door_knock rules', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()->for($knocker, 'user')->create(); // no commission rules

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create();

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toBeEmpty();
});

it('skips commission for non-qualifying knock statuses', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)
        ->create();

    $knock = DoorKnock::factory()->notHome()->forUser($knocker)->create();

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toBeEmpty();
});

it('prevents duplicate door knock commissions', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)
        ->create();

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create();

    $service = app(CommissionService::class);
    $service->createFromDoorKnock($knock);
    $entries = $service->createFromDoorKnock($knock); // second call

    expect($entries)->toBeEmpty();
    expect(CommissionEntry::where('source_type', DoorKnock::class)->where('source_id', $knock->id)->count())->toBe(1);
});
