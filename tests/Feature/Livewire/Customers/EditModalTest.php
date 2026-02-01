<?php

use App\Livewire\Customers\EditModal;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EditModal component', function () {
    it('renders the edit modal component', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(EditModal::class)
            ->assertStatus(200);
    });

    it('loads customer data on mount with customer id', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '555-1234',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->assertSet('customerId', $customer->id)
            ->assertSet('firstName', 'John')
            ->assertSet('lastName', 'Doe')
            ->assertSet('email', 'john@example.com')
            ->assertSet('phone', '555-1234');
    });

    it('opens modal with customer data', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class)
            ->call('openModal', $customer->id)
            ->assertSet('showModal', true)
            ->assertSet('customerId', $customer->id)
            ->assertSet('firstName', 'Jane')
            ->assertSet('lastName', 'Smith');
    });

    it('closes modal and resets data', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false)
            ->assertSet('customerId', null)
            ->assertSet('firstName', '')
            ->assertSet('lastName', '');
    });

    it('validates required fields on save', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->set('firstName', '')
            ->set('lastName', '')
            ->set('email', '')
            ->call('save')
            ->assertHasErrors(['firstName', 'lastName', 'email']);
    });

    it('validates email format', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->set('email', 'invalid-email')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('validates unique email excluding current customer', function () {
        $user = User::factory()->internal()->create();
        $customer1 = Customer::factory()->create(['email' => 'first@example.com']);
        $customer2 = Customer::factory()->create(['email' => 'second@example.com']);

        // Should allow same email for same customer
        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer1->id])
            ->set('email', 'first@example.com')
            ->call('save')
            ->assertHasNoErrors(['email']);

        // Should not allow email that belongs to another customer
        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer1->id])
            ->set('email', 'second@example.com')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('updates customer successfully', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Original',
            'last_name' => 'Name',
            'email' => 'original@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->set('firstName', 'Updated')
            ->set('lastName', 'Customer')
            ->set('email', 'updated@example.com')
            ->set('phone', '555-9999')
            ->set('address', '456 New St')
            ->set('city', 'New City')
            ->set('state', 'NY')
            ->set('zip', '10001')
            ->set('notes', 'Updated notes')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('customer-updated')
            ->assertSet('showModal', false);

        $customer->refresh();

        expect($customer->first_name)->toBe('Updated');
        expect($customer->last_name)->toBe('Customer');
        expect($customer->email)->toBe('updated@example.com');
        expect($customer->phone)->toBe('555-9999');
        expect($customer->address)->toBe('456 New St');
        expect($customer->city)->toBe('New City');
        expect($customer->state)->toBe('NY');
        expect($customer->zip)->toBe('10001');
        expect($customer->notes)->toBe('Updated notes');
    });

    it('clears optional fields when set to empty', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'phone' => '555-1234',
            'notes' => 'Some notes',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->set('phone', '')
            ->set('notes', '')
            ->call('save')
            ->assertHasNoErrors();

        $customer->refresh();

        expect($customer->phone)->toBeNull();
        expect($customer->notes)->toBeNull();
    });
});
