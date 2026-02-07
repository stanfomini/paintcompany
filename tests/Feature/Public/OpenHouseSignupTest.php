<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('OpenHouseSignup component', function () {
    it('renders the open house signup page for valid active listing', function () {
        $property = Property::factory()->agentListing()->active()->create();

        $this->get(route('open-house.signup', $property))
            ->assertStatus(200)
            ->assertSeeLivewire('public.open-house-signup');
    });

    it('returns 404 for non-existent listing', function () {
        $this->get(route('open-house.signup', 999999))
            ->assertNotFound();
    });

    it('returns 404 for inactive listing', function () {
        $property = Property::factory()->agentListing()->create(['listing_status' => 'inactive']);

        $this->get(route('open-house.signup', $property))
            ->assertNotFound();
    });

    it('returns 404 for pending listing', function () {
        $property = Property::factory()->agentListing()->pending()->create();

        $this->get(route('open-house.signup', $property))
            ->assertNotFound();
    });

    it('returns 404 for sold listing', function () {
        $property = Property::factory()->agentListing()->sold()->create();

        $this->get(route('open-house.signup', $property))
            ->assertNotFound();
    });

    it('displays listing address on the page', function () {
        $property = Property::factory()->agentListing()->active()->create([
            'address' => '123 Test Street',
            'city' => 'Test City',
            'state' => 'TX',
            'zip' => '12345',
        ]);

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->assertSee('123 Test Street')
            ->assertSee('Test City')
            ->assertSee('TX')
            ->assertSee('12345');
    });

    it('displays agent name when available', function () {
        $agent = User::factory()->agent()->create(['name' => 'John Agent']);
        $property = Property::factory()->agentListing()->active()->forAgent($agent)->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->assertSee('John Agent');
    });

    it('validates required fields', function () {
        $property = Property::factory()->agentListing()->active()->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->call('submit')
            ->assertHasErrors(['firstName', 'lastName', 'email']);
    });

    it('validates email format', function () {
        $property = Property::factory()->agentListing()->active()->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'invalid-email')
            ->call('submit')
            ->assertHasErrors(['email']);
    });

    it('creates customer on valid submission', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->active()->forAgent($agent)->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john.doe@example.com')
            ->set('phone', '555-1234')
            ->set('address', '456 Buyer Lane')
            ->set('city', 'Buyer City')
            ->set('state', 'CA')
            ->set('zip', '90210')
            ->set('notes', 'Interested in the backyard')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('customers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-1234',
            'address' => '456 Buyer Lane',
            'city' => 'Buyer City',
            'state' => 'CA',
            'zip' => '90210',
            'notes' => 'Interested in the backyard',
            'referred_by' => $agent->id,
            'property_id' => $property->id,
            'source' => 'open_house',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
    });

    it('creates customer with only required fields', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->active()->forAgent($agent)->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'Jane')
            ->set('lastName', 'Smith')
            ->set('email', 'jane.smith@example.com')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('customers', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'referred_by' => $agent->id,
            'property_id' => $property->id,
            'source' => 'open_house',
        ]);
    });

    it('links customer to the listing agent as referred_by', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->active()->forAgent($agent)->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'Test')
            ->set('lastName', 'Customer')
            ->set('email', 'test@example.com')
            ->call('submit');

        $customer = Customer::where('email', 'test@example.com')->first();

        expect($customer->referred_by)->toBe($agent->id)
            ->and($customer->property_id)->toBe($property->id)
            ->and($customer->created_by)->toBe($agent->id);
    });

    it('sets source as open_house', function () {
        $property = Property::factory()->agentListing()->active()->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'Source')
            ->set('lastName', 'Test')
            ->set('email', 'source@example.com')
            ->call('submit');

        $customer = Customer::where('email', 'source@example.com')->first();

        expect($customer->source)->toBe('open_house');
    });

    it('displays thank you message after submission', function () {
        $property = Property::factory()->agentListing()->active()->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'Thank')
            ->set('lastName', 'You')
            ->set('email', 'thankyou@example.com')
            ->call('submit')
            ->assertSet('submitted', true)
            ->assertSee('Thank You!');
    });

    it('rejects submission when honeypot field is filled (spam protection)', function () {
        $property = Property::factory()->agentListing()->active()->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'Spam')
            ->set('lastName', 'Bot')
            ->set('email', 'spam@example.com')
            ->set('honeypot', 'I am a bot')
            ->call('submit')
            ->assertSet('submitted', true);

        // Customer should NOT be created when honeypot is filled
        $this->assertDatabaseMissing('customers', [
            'email' => 'spam@example.com',
        ]);
    });

    it('accepts submission when honeypot field is empty', function () {
        $property = Property::factory()->agentListing()->active()->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', 'Real')
            ->set('lastName', 'User')
            ->set('email', 'realuser@example.com')
            ->set('honeypot', '')
            ->call('submit')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('customers', [
            'email' => 'realuser@example.com',
        ]);
    });

    it('validates maximum lengths for fields', function () {
        $property = Property::factory()->agentListing()->active()->create();

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->set('firstName', str_repeat('a', 256))
            ->set('lastName', 'Doe')
            ->set('email', 'test@example.com')
            ->call('submit')
            ->assertHasErrors(['firstName']);
    });

    it('does not require authentication to access', function () {
        $property = Property::factory()->agentListing()->active()->create();

        // Test without any authentication
        $this->get(route('open-house.signup', $property))
            ->assertStatus(200);
    });

    it('shows listing price when available', function () {
        $property = Property::factory()->agentListing()->active()->create([
            'listing_price' => 450000,
        ]);

        Livewire::test('public.open-house-signup', ['property' => $property])
            ->assertSee('$450,000');
    });
});
