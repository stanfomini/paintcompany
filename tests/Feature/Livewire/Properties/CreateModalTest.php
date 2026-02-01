<?php

use App\Livewire\Properties\CreateModal;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('CreateModal component', function () {
    it('renders the create modal component', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->assertStatus(200);
    });

    it('opens and closes the modal', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->assertSet('showModal', false)
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false);
    });

    it('resets form fields when closing modal', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', (string) $customer->id)
            ->set('address', '123 Main St')
            ->set('city', 'Los Angeles')
            ->call('closeModal')
            ->assertSet('customerId', '')
            ->assertSet('address', '')
            ->assertSet('city', '');
    });

    it('validates required fields', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->call('save')
            ->assertHasErrors(['customerId', 'address', 'city', 'state', 'zip']);
    });

    it('validates customer exists', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', '99999')
            ->set('address', '123 Main St')
            ->call('save')
            ->assertHasErrors(['customerId']);
    });

    it('validates property type', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', (string) $customer->id)
            ->set('address', '123 Main St')
            ->set('type', 'invalid')
            ->call('save')
            ->assertHasErrors(['type']);
    });

    it('creates a property successfully', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', (string) $customer->id)
            ->set('name', 'Main Office')
            ->set('address', '123 Main St')
            ->set('city', 'Anytown')
            ->set('state', 'CA')
            ->set('zip', '90210')
            ->set('type', 'commercial')
            ->set('squareFootage', '5000')
            ->set('yearBuilt', '2000')
            ->set('notes', 'Test notes')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('property-created')
            ->assertSet('showModal', false);

        $this->assertDatabaseHas('properties', [
            'customer_id' => $customer->id,
            'name' => 'Main Office',
            'address' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'CA',
            'zip' => '90210',
            'type' => 'commercial',
            'square_footage' => 5000,
            'year_built' => 2000,
            'notes' => 'Test notes',
        ]);
    });

    it('creates property with only required fields', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', (string) $customer->id)
            ->set('address', '456 Oak Ave')
            ->set('city', 'Test City')
            ->set('state', 'TX')
            ->set('zip', '75001')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('property-created');

        $this->assertDatabaseHas('properties', [
            'customer_id' => $customer->id,
            'address' => '456 Oak Ave',
            'city' => 'Test City',
            'state' => 'TX',
            'zip' => '75001',
            'type' => 'residential',
        ]);
    });

    it('displays customers in dropdown', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $component = Livewire::actingAs($user)
            ->test(CreateModal::class);

        expect($component->get('customers'))->toHaveCount(1);
        expect($component->get('customers')->first()->first_name)->toBe('John');
    });

    it('validates year built range', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', (string) $customer->id)
            ->set('address', '123 Main St')
            ->set('city', 'Test City')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->set('yearBuilt', '1700')
            ->call('save')
            ->assertHasErrors(['yearBuilt']);
    });
});
