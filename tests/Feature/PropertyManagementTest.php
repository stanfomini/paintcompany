<?php

use App\Livewire\Properties\CreateModal;
use App\Livewire\Properties\EditModal;
use App\Livewire\Properties\Index;
use App\Livewire\Properties\Show;
use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Property list access', function () {
    it('allows internal user to view property list', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->get(route('properties.index'));

        $response->assertOk();
        $response->assertSeeLivewire(Index::class);
    });

    it('denies agent access to property list', function () {
        $user = User::factory()->agent()->create();

        $response = $this->actingAs($user)
            ->get(route('properties.index'));

        $response->assertForbidden();
    });

    it('denies customer access to property list', function () {
        $user = User::factory()->customer()->create();

        $response = $this->actingAs($user)
            ->get(route('properties.index'));

        $response->assertForbidden();
    });

    it('redirects unauthenticated users to login', function () {
        $response = $this->get(route('properties.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('Property detail access', function () {
    it('allows internal user to view property detail', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('properties.show', $property));

        $response->assertOk();
        $response->assertSeeLivewire(Show::class);
    });

    it('denies agent access to property detail', function () {
        $user = User::factory()->agent()->create();
        $property = Property::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('properties.show', $property));

        $response->assertForbidden();
    });

    it('denies customer access to property detail', function () {
        $user = User::factory()->customer()->create();
        $property = Property::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('properties.show', $property));

        $response->assertForbidden();
    });
});

describe('Property creation', function () {
    it('allows internal user to create property', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', $customer->id)
            ->set('address', '123 Main St')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->set('type', 'residential')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('properties', [
            'customer_id' => $customer->id,
            'address' => '123 Main St',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'zip' => '90001',
            'type' => 'residential',
        ]);
    });

    it('validates required customer field', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', '')
            ->set('address', '123 Main St')
            ->call('save')
            ->assertHasErrors(['customerId']);
    });

    it('validates required address field', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', $customer->id)
            ->set('address', '')
            ->call('save')
            ->assertHasErrors(['address']);
    });

    it('validates customer exists', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', 99999)
            ->set('address', '123 Main St')
            ->call('save')
            ->assertHasErrors(['customerId']);
    });

    it('validates property type', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('customerId', $customer->id)
            ->set('address', '123 Main St')
            ->set('type', 'invalid-type')
            ->call('save')
            ->assertHasErrors(['type']);
    });
});

describe('Property editing', function () {
    it('allows internal user to update property', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Old St',
            'type' => 'residential',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['propertyId' => $property->id])
            ->set('address', '456 New Ave')
            ->set('type', 'commercial')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'address' => '456 New Ave',
            'type' => 'commercial',
        ]);
    });

    it('property belongs to correct customer', function () {
        $user = User::factory()->internal()->create();
        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer1->id,
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['propertyId' => $property->id])
            ->set('customerId', $customer2->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'customer_id' => $customer2->id,
        ]);
    });

    it('loads property data correctly when editing', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '789 Test Blvd',
            'city' => 'San Francisco',
            'state' => 'CA',
            'zip' => '94102',
            'type' => 'commercial',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['propertyId' => $property->id])
            ->assertSet('customerId', (string) $customer->id)
            ->assertSet('address', '789 Test Blvd')
            ->assertSet('city', 'San Francisco')
            ->assertSet('state', 'CA')
            ->assertSet('zip', '94102')
            ->assertSet('type', 'commercial');
    });
});

describe('Property search functionality', function () {
    it('can search properties by address', function () {
        $user = User::factory()->internal()->create();

        Property::factory()->create(['address' => '123 Maple Street']);
        Property::factory()->create(['address' => '456 Oak Avenue']);
        Property::factory()->create(['address' => '789 Pine Road']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Maple')
            ->assertSee('123 Maple Street')
            ->assertDontSee('456 Oak Avenue')
            ->assertDontSee('789 Pine Road');
    });

    it('can filter by property type', function () {
        $user = User::factory()->internal()->create();

        Property::factory()->residential()->create(['address' => '111 Residential Dr']);
        Property::factory()->commercial()->create(['address' => '222 Commercial Blvd']);
        Property::factory()->industrial()->create(['address' => '333 Industrial Way']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('typeFilter', 'commercial')
            ->assertSee('222 Commercial Blvd')
            ->assertDontSee('111 Residential Dr')
            ->assertDontSee('333 Industrial Way');
    });

    it('can filter by customer', function () {
        $user = User::factory()->internal()->create();

        $customer1 = Customer::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
        $customer2 = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith']);

        Property::factory()->create(['address' => '100 John Property', 'customer_id' => $customer1->id]);
        Property::factory()->create(['address' => '200 Jane Property', 'customer_id' => $customer2->id]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('customerFilter', $customer1->id)
            ->assertSee('100 John Property')
            ->assertDontSee('200 Jane Property');
    });

    it('shows all properties when no filters applied', function () {
        $user = User::factory()->internal()->create();

        Property::factory()->residential()->create(['address' => 'First Property']);
        Property::factory()->commercial()->create(['address' => 'Second Property']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', '')
            ->set('typeFilter', 'all')
            ->set('customerFilter', '')
            ->assertSee('First Property')
            ->assertSee('Second Property');
    });

    it('can search by city', function () {
        $user = User::factory()->internal()->create();

        Property::factory()->create(['address' => '111 Main St', 'city' => 'Los Angeles']);
        Property::factory()->create(['address' => '222 Oak Ave', 'city' => 'San Francisco']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Los Angeles')
            ->assertSee('111 Main St')
            ->assertDontSee('222 Oak Ave');
    });
});
