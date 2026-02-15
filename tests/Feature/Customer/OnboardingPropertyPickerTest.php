<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internal = User::factory()->internal()->withFullAccess()->create();
});

describe('Property picker display', function () {
    it('shows property cards when customer has existing properties', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
            'type' => 'residential',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertSee('123 Main St')
            ->assertSee('Denver, CO 80202')
            ->assertSee('Add a New Property');
    });

    it('shows multiple property cards for customer with multiple properties', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '456 Oak Ave',
            'city' => 'Boulder',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertSee('123 Main St')
            ->assertSee('456 Oak Ave');
    });

    it('shows blank form when customer has no properties', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertDontSee('Add a New Property')
            ->assertSee('Property Details');
    });
});

describe('Selecting existing property', function () {
    it('can select an existing property and advance to step 3', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->call('selectProperty', $property->id)
            ->assertSet('selectedPropertyId', $property->id)
            ->assertSet('step', 3);
    });

    it('uses selected property for deal on completion', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('selectProperty', $property->id)
            ->assertSet('step', 3)
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $deal = $customer->deals()->first();
        expect($deal)->not->toBeNull();
        expect($deal->property_id)->toBe($property->id);
    });

    it('rejects selecting a property that does not belong to customer', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
        $otherProperty = Property::factory()->create();

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('selectProperty', $otherProperty->id)
            ->assertSet('selectedPropertyId', null)
            ->assertSet('step', 2);
    });
});

describe('Adding new property via form', function () {
    it('can toggle new property form and hide cards', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertSet('showNewPropertyForm', false)
            ->call('showAddPropertyForm')
            ->assertSet('showNewPropertyForm', true);
    });

    it('creates new property when form is submitted', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('showAddPropertyForm')
            ->set('address', '789 New Rd')
            ->set('city', 'Aurora')
            ->set('state', 'CO')
            ->set('zip', '80010')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->call('nextStep')
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        expect($customer->properties()->count())->toBe(2);
        expect($customer->properties()->where('address', '789 New Rd')->exists())->toBeTrue();
    });

    it('can cancel new property form and return to cards', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('showAddPropertyForm')
            ->assertSet('showNewPropertyForm', true)
            ->call('cancelAddPropertyForm')
            ->assertSet('showNewPropertyForm', false)
            ->assertSee('123 Main St');
    });
});

describe('New project flow with property picker', function () {
    it('shows property picker for returning customer requesting new project', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => false]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::withQueryParams(['new_project' => true])
            ->actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->assertSet('step', 2)
            ->assertSee('123 Main St')
            ->assertSee('Add a New Property');
    });
});
