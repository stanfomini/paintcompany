<?php

use App\Models\SalesTerritory;
use App\Models\TerritoryOffer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
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

it('renders the territory queue page', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('territory_dispatch.claim');

    $this->actingAs($user)
        ->get(route('territory-queue'))
        ->assertSuccessful();
});

it('shows pending offers for the user', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('territory_dispatch.claim');

    $territory = SalesTerritory::factory()->create(['name' => 'Northside District']);
    $offer = TerritoryOffer::factory()->pending()->forTerritory($territory)->create();
    $offer->eligibleUsers()->attach($user->id, [
        'queue_position' => 1,
        'notified_at' => now(),
        'response' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('sales.territory-queue')
        ->assertSee('Northside District');
});

it('can claim an offer via the component', function () {
    Event::fake();

    $user = User::factory()->internal()->create();
    $user->givePermissionTo('territory_dispatch.claim');

    $territory = SalesTerritory::factory()->create();
    $offer = TerritoryOffer::factory()->pending()->forTerritory($territory)->create();
    $offer->eligibleUsers()->attach($user->id, [
        'queue_position' => 1,
        'notified_at' => now(),
        'response' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('sales.territory-queue')
        ->call('claimOffer', $offer->id)
        ->assertHasNoErrors();

    $offer->refresh();
    expect($offer->status)->toBe('claimed');
    expect($offer->claimed_by)->toBe($user->id);
});

it('can decline an offer via the component', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('territory_dispatch.claim');

    $territory = SalesTerritory::factory()->create();
    $offer = TerritoryOffer::factory()->pending()->forTerritory($territory)->create();
    $offer->eligibleUsers()->attach($user->id, [
        'queue_position' => 1,
        'notified_at' => now(),
        'response' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('sales.territory-queue')
        ->call('declineOffer', $offer->id)
        ->assertHasNoErrors();

    $pivot = \Illuminate\Support\Facades\DB::table('territory_offer_users')
        ->where('territory_offer_id', $offer->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot->response)->toBe('declined');
});

it('requires authentication', function () {
    $this->get(route('territory-queue'))
        ->assertRedirect(route('login'));
});
