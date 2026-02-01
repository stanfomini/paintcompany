<?php

use App\Livewire\Properties\Show;
use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Show component', function () {
    it('renders the show component with property data', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'CA',
            'zip' => '90210',
            'type' => 'residential',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertStatus(200)
            ->assertSee('123 Main St')
            ->assertSee('Anytown')
            ->assertSee('CA')
            ->assertSee('90210');
    });

    it('displays property type badge', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create(['type' => 'commercial']);

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertStatus(200);
    });

    it('displays customer information', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '555-1234',
        ]);
        $property = Property::factory()->create(['customer_id' => $customer->id]);

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertSee('Jane Smith')
            ->assertSee('jane@example.com')
            ->assertSee('555-1234');
    });

    it('displays notes when available', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create([
            'notes' => 'This is a test note for the property.',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertSee('This is a test note for the property.');
    });

    it('displays square footage when available', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create([
            'square_footage' => 2500,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertSee('2,500 sq ft');
    });

    it('displays year built when available', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create([
            'year_built' => 1995,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertSee('1995');
    });

    it('loads relationships correctly', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->create(['customer_id' => $customer->id]);

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property]);

        expect($component->get('property')->relationLoaded('customer'))->toBeTrue();
        expect($component->get('property')->relationLoaded('photos'))->toBeTrue();
        expect($component->get('property')->relationLoaded('deals'))->toBeTrue();
    });

    it('displays property with no photos or deals', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertStatus(200);

        expect($property->photos)->toHaveCount(0);
        expect($property->deals)->toHaveCount(0);
    });

    it('uses the app layout', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create(['address' => 'Test Address']);

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property]);

        $component->assertStatus(200);
    });

    it('displays property name when available', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create([
            'name' => 'Main Office Building',
            'address' => '123 Business St',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['property' => $property])
            ->assertStatus(200);

        expect($property->display_name)->toBe('Main Office Building');
    });

    it('uses address as display name when no name set', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create([
            'name' => null,
            'address' => '456 Residential Ave',
        ]);

        expect($property->display_name)->toBe('456 Residential Ave');
    });
});
