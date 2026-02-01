<?php

use App\Livewire\Customer\Properties\Show;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Properties Show component', function () {
    it('renders the property details for authenticated customer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main Street',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['property' => $property])
            ->assertStatus(200)
            ->assertSee('123 Main Street');
    });

    it('displays property details', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '456 Oak Avenue',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
            'type' => 'residential',
            'square_footage' => 2500,
            'year_built' => 1990,
            'notes' => 'Corner lot property',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['property' => $property])
            ->assertSee('456 Oak Avenue')
            ->assertSee('Springfield')
            ->assertSee('IL')
            ->assertSee('62701')
            ->assertSee('residential')
            ->assertSee('2,500 sq ft')
            ->assertSee('1990')
            ->assertSee('Corner lot property');
    });

    it('cannot view another customers property', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();
        $otherProperty = Property::factory()->create(['customer_id' => $otherCustomer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['property' => $otherProperty])
            ->assertNotFound();
    });

    it('displays associated deals', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $property = Property::factory()->create(['customer_id' => $customer->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'title' => 'Interior Painting',
            'stage' => 'in_progress',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['property' => $property])
            ->assertSee('Associated Projects')
            ->assertSee('Interior Painting')
            ->assertSee('In Progress');
    });

    it('shows empty state when no deals exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $property = Property::factory()->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['property' => $property])
            ->assertSee('No projects for this property yet');
    });

    it('shows empty state when no photos exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $property = Property::factory()->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['property' => $property])
            ->assertSee('No photos available yet');
    });

    it('displays back to properties button', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $property = Property::factory()->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['property' => $property])
            ->assertSee('Back to Properties');
    });
});
