<?php

use App\Livewire\Customer\Properties\Index;
use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Properties Index component', function () {
    it('renders the properties index for authenticated customers', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertStatus(200)
            ->assertSee('My Properties');
    });

    it('displays customer properties', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '456 Oak Avenue',
            'city' => 'Chicago',
            'state' => 'IL',
            'type' => 'residential',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('456 Oak Avenue')
            ->assertSee('Chicago')
            ->assertSee('residential');
    });

    it('does not display other customers properties', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 My Street',
        ]);

        Property::factory()->create([
            'customer_id' => $otherCustomer->id,
            'address' => '789 Other Street',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('123 My Street')
            ->assertDontSee('789 Other Street');
    });

    it('can search properties by address', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main Street',
        ]);

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '456 Oak Avenue',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('search', 'Main')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('can search properties by city', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main Street',
            'city' => 'Chicago',
        ]);

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '456 Oak Avenue',
            'city' => 'New York',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('search', 'Chicago')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('shows empty state when no properties exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('No properties found')
            ->assertSee('Your properties will appear here once added');
    });

    it('shows empty state when search yields no results', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main Street',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('search', 'NonexistentAddress')
            ->assertSee('No properties found')
            ->assertSee('Try adjusting your search criteria');
    });

    it('resets pagination when search changes', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Property::factory()->count(20)->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('displays photo and project counts', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main Street',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('photos')
            ->assertSee('projects');
    });
});
