<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EditModal component', function () {
    it('renders the edit modal component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('properties.edit-modal')
            ->assertStatus(200);
    });

    it('loads property data on mount with property id', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Test Property',
            'address' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'CA',
            'zip' => '90210',
            'type' => 'commercial',
            'square_footage' => 5000,
            'year_built' => 2000,
        ]);

        Livewire::actingAs($user)
            ->test('properties.edit-modal', ['propertyId' => $property->id])
            ->assertSet('propertyId', $property->id)
            ->assertSet('customerId', (string) $customer->id)
            ->assertSet('name', 'Test Property')
            ->assertSet('address', '123 Main St')
            ->assertSet('city', 'Anytown')
            ->assertSet('state', 'CA')
            ->assertSet('zip', '90210')
            ->assertSet('type', 'commercial')
            ->assertSet('squareFootage', '5000')
            ->assertSet('yearBuilt', '2000');
    });

    it('opens modal with property data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create([
            'address' => '456 Oak Ave',
        ]);

        Livewire::actingAs($user)
            ->test('properties.edit-modal')
            ->call('openModal', $property->id)
            ->assertSet('showModal', true)
            ->assertSet('propertyId', $property->id)
            ->assertSet('address', '456 Oak Ave');
    });

    it('closes modal and resets data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test('properties.edit-modal', ['propertyId' => $property->id])
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false)
            ->assertSet('propertyId', null)
            ->assertSet('address', '');
    });

    it('validates required fields on save', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test('properties.edit-modal', ['propertyId' => $property->id])
            ->set('customerId', '')
            ->set('address', '')
            ->set('city', '')
            ->set('state', '')
            ->set('zip', '')
            ->call('save')
            ->assertHasErrors(['customerId', 'address', 'city', 'state', 'zip']);
    });

    it('validates property type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test('properties.edit-modal', ['propertyId' => $property->id])
            ->set('type', 'invalid')
            ->call('save')
            ->assertHasErrors(['type']);
    });

    it('updates property successfully', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $newCustomer = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Original Address',
            'type' => 'residential',
        ]);

        Livewire::actingAs($user)
            ->test('properties.edit-modal', ['propertyId' => $property->id])
            ->set('customerId', (string) $newCustomer->id)
            ->set('name', 'Updated Name')
            ->set('address', 'Updated Address')
            ->set('city', 'New City')
            ->set('state', 'NY')
            ->set('zip', '10001')
            ->set('type', 'commercial')
            ->set('squareFootage', '10000')
            ->set('yearBuilt', '2010')
            ->set('notes', 'Updated notes')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('property-updated')
            ->assertSet('showModal', false);

        $property->refresh();

        expect($property->customer_id)->toBe($newCustomer->id);
        expect($property->name)->toBe('Updated Name');
        expect($property->address)->toBe('Updated Address');
        expect($property->city)->toBe('New City');
        expect($property->state)->toBe('NY');
        expect($property->zip)->toBe('10001');
        expect($property->type)->toBe('commercial');
        expect($property->square_footage)->toBe(10000);
        expect($property->year_built)->toBe(2010);
        expect($property->notes)->toBe('Updated notes');
    });

    it('clears optional fields when set to empty', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create([
            'name' => 'Test Name',
            'notes' => 'Some notes',
            'square_footage' => 5000,
        ]);

        Livewire::actingAs($user)
            ->test('properties.edit-modal', ['propertyId' => $property->id])
            ->set('name', '')
            ->set('notes', '')
            ->set('squareFootage', '')
            ->call('save')
            ->assertHasNoErrors();

        $property->refresh();

        expect($property->name)->toBeNull();
        expect($property->notes)->toBeNull();
        expect($property->square_footage)->toBeNull();
    });

    it('displays customers in dropdown', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->create(['first_name' => 'Alice']);
        $customer2 = Customer::factory()->create(['first_name' => 'Bob']);

        $component = Livewire::actingAs($user)
            ->test('properties.edit-modal');

        expect($component->get('customers'))->toHaveCount(2);
    });
});
