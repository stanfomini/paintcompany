<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('renders the media browser', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->assertOk();
});

it('shows deals in the deals tab', function () {
    $user = User::factory()->internal()->create();
    Deal::factory()->create(['title' => 'Johnson Kitchen']);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->assertSee('Johnson Kitchen');
});

it('shows deal photos when a deal is selected', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);
    PropertyPhoto::factory()->create(['property_id' => $property->id, 'caption' => 'Kitchen wall']);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->call('selectDeal', $deal->id)
        ->assertSee('Kitchen wall');
});

it('shows estimate photos when a deal is selected', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    PropertyPhoto::factory()->create(['estimate_id' => $estimate->id, 'caption' => 'Estimate photo']);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->call('selectDeal', $deal->id)
        ->assertSee('Estimate photo');
});

it('dispatches media-selected event', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->call('selectMedia', 'property-photo', 1)
        ->assertDispatched('media-selected');
});

it('closes modal after selecting media', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->set('show', true)
        ->call('selectMedia', 'property-photo', 1)
        ->assertSet('show', false);
});

it('shows media hub videos', function () {
    $user = User::factory()->internal()->create();
    VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'available_in_hub' => true,
        'title' => 'Hub Promo',
    ]);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->set('browserTab', 'hub')
        ->assertSee('Hub Promo');
});

it('shows my recordings in recordings tab', function () {
    $user = User::factory()->internal()->create();
    VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'title' => 'My Recording',
    ]);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->set('browserTab', 'recordings')
        ->assertSee('My Recording');
});

it('goes back from deal detail to deal list', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->call('selectDeal', $deal->id)
        ->assertSet('selectedDealId', $deal->id)
        ->call('backToDeals')
        ->assertSet('selectedDealId', null);
});

it('resets search and selectedDealId when switching tabs', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->set('search', 'test')
        ->call('selectDeal', $deal->id)
        ->set('browserTab', 'hub')
        ->assertSet('search', '')
        ->assertSet('selectedDealId', null);
});

it('filters deals by search', function () {
    $user = User::factory()->internal()->create();
    Deal::factory()->create(['title' => 'Johnson Kitchen']);
    Deal::factory()->create(['title' => 'Smith Exterior']);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->set('search', 'Johnson')
        ->assertSee('Johnson Kitchen')
        ->assertDontSee('Smith Exterior');
});
