<?php

use App\Events\TerritoryClaimed;
use App\Events\TerritoryOffered;
use App\Events\TerritoryOfferExpired;
use App\Models\SalesTerritory;
use App\Models\TerritoryOffer;
use App\Models\User;
use App\Services\TerritoryDispatchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'territory_dispatch.claim', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'territory_dispatch.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'sales_territories.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'sales_territories.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'door_knocks.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'door_knocks.record', 'guard_name' => 'web']);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

it('gets eligible reps for a territory', function () {
    $service = app(TerritoryDispatchService::class);
    $territory = SalesTerritory::factory()->create();

    $eligible = User::factory()->internal()->count(2)->create();
    $eligible->each(fn ($u) => $u->givePermissionTo('territory_dispatch.claim'));

    // Create an ineligible user (no permission)
    User::factory()->internal()->create();

    $reps = $service->getEligibleReps($territory, Carbon::tomorrow());

    expect($reps)->toHaveCount(2);
    expect($reps->pluck('id')->sort()->values()->toArray())
        ->toBe($eligible->pluck('id')->sort()->values()->toArray());
});

it('excludes users already claimed for that date', function () {
    $service = app(TerritoryDispatchService::class);
    $territory = SalesTerritory::factory()->create();
    $workDate = Carbon::tomorrow();

    // Create a manager for offer's created_by (won't have claim permission)
    $manager = User::factory()->internal()->create();

    $user1 = User::factory()->internal()->create();
    $user1->givePermissionTo('territory_dispatch.claim');

    $user2 = User::factory()->internal()->create();
    $user2->givePermissionTo('territory_dispatch.claim');

    // user2 already has a claimed offer for the same date
    TerritoryOffer::factory()->create([
        'territory_id' => $territory->id,
        'work_date' => $workDate,
        'status' => 'claimed',
        'claimed_by' => $user2->id,
        'claimed_at' => now(),
        'created_by' => $manager->id,
    ]);

    $reps = $service->getEligibleReps($territory, $workDate);

    expect($reps)->toHaveCount(1);
    expect($reps->first()->id)->toBe($user1->id);
});

it('calculates priority queue with round-robin scoring', function () {
    $service = app(TerritoryDispatchService::class);
    $territory = SalesTerritory::factory()->create();

    // User A: claimed recently (low days_since score), many assignments this week
    $userA = User::factory()->internal()->create();
    $userA->givePermissionTo('territory_dispatch.claim');
    TerritoryOffer::factory()->create([
        'status' => 'claimed',
        'claimed_by' => $userA->id,
        'claimed_at' => now(),
    ]);

    // User B: no claims at all (high days_since score), no assignments this week
    $userB = User::factory()->internal()->create();
    $userB->givePermissionTo('territory_dispatch.claim');

    $users = new \Illuminate\Database\Eloquent\Collection([$userA, $userB]);
    $queue = $service->calculatePriorityQueue($users, $territory);

    // User B should have higher priority (position 1) since no recent claims
    expect($queue[$userB->id])->toBe(1);
    expect($queue[$userA->id])->toBe(2);
});

it('creates an offer and notifies eligible users', function () {
    Event::fake([TerritoryOffered::class]);

    $territory = SalesTerritory::factory()->create();
    $manager = User::factory()->internal()->create();
    $manager->givePermissionTo('territory_dispatch.manage');
    $this->actingAs($manager);

    $user1 = User::factory()->internal()->create();
    $user1->givePermissionTo('territory_dispatch.claim');
    $user2 = User::factory()->internal()->create();
    $user2->givePermissionTo('territory_dispatch.claim');

    $service = app(TerritoryDispatchService::class);
    $offer = $service->createOffer(
        $territory,
        Carbon::tomorrow(),
        [$user1->id, $user2->id],
    );

    expect($offer)->toBeInstanceOf(TerritoryOffer::class);
    expect($offer->status)->toBe('pending');
    expect($offer->territory_id)->toBe($territory->id);
    expect($offer->eligibleUsers)->toHaveCount(2);

    Event::assertDispatched(TerritoryOffered::class, 2);
});

it('claims an offer with row locking', function () {
    Event::fake([TerritoryClaimed::class]);

    $territory = SalesTerritory::factory()->create();
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('territory_dispatch.claim');

    $offer = TerritoryOffer::factory()->pending()->forTerritory($territory)->create();
    $offer->eligibleUsers()->attach($user->id, [
        'queue_position' => 1,
        'notified_at' => now(),
        'response' => 'pending',
    ]);

    $service = app(TerritoryDispatchService::class);
    $result = $service->claimOffer($offer, $user);

    expect($result)->toBeTrue();

    $offer->refresh();
    expect($offer->status)->toBe('claimed');
    expect($offer->claimed_by)->toBe($user->id);
    expect($offer->claimed_at)->not->toBeNull();

    Event::assertDispatched(TerritoryClaimed::class);
});

it('prevents double-claiming an offer', function () {
    Event::fake([TerritoryClaimed::class]);

    $territory = SalesTerritory::factory()->create();
    $user1 = User::factory()->internal()->create();
    $user2 = User::factory()->internal()->create();

    $offer = TerritoryOffer::factory()->pending()->forTerritory($territory)->create();
    $offer->eligibleUsers()->attach($user1->id, [
        'queue_position' => 1,
        'notified_at' => now(),
        'response' => 'pending',
    ]);
    $offer->eligibleUsers()->attach($user2->id, [
        'queue_position' => 2,
        'notified_at' => now(),
        'response' => 'pending',
    ]);

    $service = app(TerritoryDispatchService::class);

    // First claim succeeds
    $result1 = $service->claimOffer($offer, $user1);
    expect($result1)->toBeTrue();

    // Second claim fails (offer no longer pending)
    $result2 = $service->claimOffer($offer, $user2);
    expect($result2)->toBeFalse();

    // Only one TerritoryClaimed event
    Event::assertDispatched(TerritoryClaimed::class, 1);
});

it('declines an offer', function () {
    $territory = SalesTerritory::factory()->create();
    $user = User::factory()->internal()->create();

    $offer = TerritoryOffer::factory()->pending()->forTerritory($territory)->create();
    $offer->eligibleUsers()->attach($user->id, [
        'queue_position' => 1,
        'notified_at' => now(),
        'response' => 'pending',
    ]);

    $service = app(TerritoryDispatchService::class);
    $result = $service->declineOffer($offer, $user);

    expect($result)->toBeTrue();

    $pivot = \Illuminate\Support\Facades\DB::table('territory_offer_users')
        ->where('territory_offer_id', $offer->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot->response)->toBe('declined');
    expect($pivot->responded_at)->not->toBeNull();
});

it('expires past-due offers', function () {
    Event::fake([TerritoryOfferExpired::class]);

    // Create an expired offer (expires_at in the past, still pending)
    $offer = TerritoryOffer::factory()->create([
        'status' => 'pending',
        'offered_at' => now()->subHours(3),
        'expires_at' => now()->subHour(),
    ]);

    $user = User::factory()->internal()->create();
    $offer->eligibleUsers()->attach($user->id, [
        'queue_position' => 1,
        'notified_at' => now()->subHours(3),
        'response' => 'pending',
    ]);

    $service = app(TerritoryDispatchService::class);
    $count = $service->expireOffers();

    expect($count)->toBe(1);

    $offer->refresh();
    expect($offer->status)->toBe('expired');

    $pivot = \Illuminate\Support\Facades\DB::table('territory_offer_users')
        ->where('territory_offer_id', $offer->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot->response)->toBe('expired');

    Event::assertDispatched(TerritoryOfferExpired::class, 1);
});
