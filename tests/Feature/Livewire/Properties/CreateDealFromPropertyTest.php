<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->customer = Customer::factory()->create();
    $this->property = Property::factory()->create(['customer_id' => $this->customer->id]);
});

it('shows create deal button on property page', function () {
    $this->actingAs($this->user)
        ->get(route('properties.show', $this->property))
        ->assertSuccessful()
        ->assertSee('Add Deal');
});

it('property show component contains deals create modal', function () {
    Livewire::actingAs($this->user)
        ->test('properties.show', ['property' => $this->property])
        ->assertSeeLivewire('deals.create-modal');
});

it('pre-populates customer and property when opening modal from property page', function () {
    Livewire::actingAs($this->user)
        ->test('deals.create-modal', [
            'customerId' => $this->customer->id,
            'propertyId' => $this->property->id,
        ])
        ->call('openModal')
        ->assertSet('customerId', $this->customer->id)
        ->assertSet('propertyId', $this->property->id);
});

it('creates deal with pre-populated property and customer', function () {
    Livewire::actingAs($this->user)
        ->test('deals.create-modal', [
            'customerId' => $this->customer->id,
            'propertyId' => $this->property->id,
        ])
        ->call('openModal')
        ->set('title', 'Exterior Painting')
        ->set('description', 'Full exterior paint job')
        ->call('save')
        ->assertDispatched('deal-created');

    $this->assertDatabaseHas('deals', [
        'customer_id' => $this->customer->id,
        'property_id' => $this->property->id,
        'title' => 'Exterior Painting',
    ]);
});

it('refreshes deals list after deal is created', function () {
    Livewire::actingAs($this->user)
        ->test('properties.show', ['property' => $this->property])
        ->assertDontSee('New Test Deal')
        ->dispatch('deal-created');

    // Create a deal
    Deal::factory()->create([
        'customer_id' => $this->customer->id,
        'property_id' => $this->property->id,
        'title' => 'New Test Deal',
    ]);

    // Refresh and check
    Livewire::actingAs($this->user)
        ->test('properties.show', ['property' => $this->property])
        ->assertSee('New Test Deal');
});

it('shows empty state when no deals exist', function () {
    Livewire::actingAs($this->user)
        ->test('properties.show', ['property' => $this->property])
        ->assertSee('No deals yet')
        ->assertSee('Create a deal to start tracking work for this property.');
});

it('shows deals table when deals exist', function () {
    $deal = Deal::factory()->create([
        'customer_id' => $this->customer->id,
        'property_id' => $this->property->id,
        'title' => 'Existing Deal',
        'estimated_value' => 5000,
    ]);

    $this->property->refresh();

    Livewire::actingAs($this->user)
        ->test('properties.show', ['property' => $this->property])
        ->assertSee('Existing Deal')
        ->assertSee('$5,000.00');
});

it('links deals to deal show page', function () {
    $deal = Deal::factory()->create([
        'customer_id' => $this->customer->id,
        'property_id' => $this->property->id,
        'title' => 'Linked Deal',
    ]);

    $this->property->refresh();

    $this->actingAs($this->user)
        ->get(route('properties.show', $this->property))
        ->assertSee('Linked Deal')
        ->assertSee(route('deals.show', $deal));
});
