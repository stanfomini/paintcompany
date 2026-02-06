<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('CreateModal component', function () {
    it('renders the create modal component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->assertStatus(200);
    });

    it('opens and closes the modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->assertSet('showModal', false)
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false);
    });

    it('resets form fields when closing modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john@example.com')
            ->call('closeModal')
            ->assertSet('firstName', '')
            ->assertSet('lastName', '')
            ->assertSet('email', '');
    });

    it('validates required fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->call('save')
            ->assertHasErrors(['firstName', 'lastName', 'email']);
    });

    it('validates email format', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'invalid-email')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('shows duplicate warning for existing email', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        Customer::factory()->create(['email' => 'existing@example.com']);

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('email', 'existing@example.com')
            ->assertSet('showDuplicateWarning', true);
    });

    it('creates a customer successfully', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john.doe@example.com')
            ->set('phone', '555-1234')
            ->set('address', '123 Main St')
            ->set('city', 'Anytown')
            ->set('state', 'CA')
            ->set('zip', '90210')
            ->set('notes', 'Test notes')
            ->set('sendInvite', false) // Disable to avoid invitation service
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('customer-created')
            ->assertSet('showModal', false);

        $this->assertDatabaseHas('customers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-1234',
            'address' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'CA',
            'zip' => '90210',
            'notes' => 'Test notes',
            'created_by' => $user->id,
            'status' => 'active',
        ]);
    });

    it('creates customer with only required fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'Jane')
            ->set('lastName', 'Smith')
            ->set('email', 'jane.smith@example.com')
            ->set('sendInvite', false) // Disable to avoid invitation service
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('customer-created');

        $this->assertDatabaseHas('customers', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'created_by' => $user->id,
        ]);
    });

    it('sets created_by to authenticated user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'Test')
            ->set('lastName', 'Customer')
            ->set('email', 'test@example.com')
            ->set('sendInvite', false)
            ->call('save');

        $customer = Customer::where('email', 'test@example.com')->first();

        expect($customer->created_by)->toBe($user->id);
    });
});
