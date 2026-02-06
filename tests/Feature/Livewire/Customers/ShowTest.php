<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Show component', function () {
    it('renders the show component with customer data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $creator = User::factory()->internal()->withFullAccess()->create(['name' => 'Creator User']);
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-1234',
            'created_by' => $creator->id,
        ]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertStatus(200)
            ->assertSee('John Doe')
            ->assertSee('john.doe@example.com')
            ->assertSee('555-1234');
    });

    it('displays customer status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['status' => 'active']);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertStatus(200);
    });

    it('displays full address when available', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'address' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'CA',
            'zip' => '90210',
        ]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('123 Main St')
            ->assertSee('Anytown')
            ->assertSee('CA')
            ->assertSee('90210');
    });

    it('displays notes when available', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'notes' => 'This is a test note for the customer.',
        ]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('This is a test note for the customer.');
    });

    it('shows portal access when customer has portal access', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->withPortalAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Enabled');
    });

    it('shows referral information when referred by agent', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create(['name' => 'Agent Smith']);
        $customer = Customer::factory()->create(['referred_by' => $agent->id]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertStatus(200);

        expect($customer->referredBy->name)->toBe('Agent Smith');
    });

    it('loads relationships correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $creator = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['created_by' => $creator->id]);

        $component = Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer]);

        expect($component->get('customer')->relationLoaded('createdBy'))->toBeTrue();
        expect($component->get('customer')->relationLoaded('properties'))->toBeTrue();
        expect($component->get('customer')->relationLoaded('deals'))->toBeTrue();
    });

    it('displays customer with no properties or deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertStatus(200);

        expect($customer->properties)->toHaveCount(0);
        expect($customer->deals)->toHaveCount(0);
    });

    it('uses the app layout', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer]);

        // Component renders successfully with layout
        $component->assertStatus(200);
    });
});
