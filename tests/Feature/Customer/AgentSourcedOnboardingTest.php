<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

it('detects agent-sourced customer from deal with agent_intent and property', function () {
    $agent = User::factory()->agent()->create(['name' => 'Jane Realtor']);
    $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    $property = Property::factory()->forCustomer($customer)->create([
        'address' => '123 Elm St',
        'city' => 'Denver',
    ]);

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'agent_id' => $agent->id,
        'agent_intent' => 'selling',
        'stage' => 'lead',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.onboarding')
        ->assertSet('isAgentSourced', true)
        ->assertSet('agentName', 'Jane Realtor')
        ->assertSet('propertyAddress', '123 Elm St');
});

it('shows single-step onboarding for agent-sourced customer', function () {
    $agent = User::factory()->agent()->create(['name' => 'Jane Realtor']);
    $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    $property = Property::factory()->forCustomer($customer)->create([
        'address' => '456 Oak Ave',
    ]);

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'agent_id' => $agent->id,
        'agent_intent' => 'selling',
        'stage' => 'lead',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.onboarding')
        ->assertSee('Jane Realtor')
        ->assertSee('456 Oak Ave')
        ->assertSee('Get Started')
        ->assertDontSee('Property Details')
        ->assertDontSee('Project Info');
});

it('saves phone and contact preference for agent-sourced customer', function () {
    $agent = User::factory()->agent()->create();
    $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
    $customer = Customer::factory()->create([
        'user_id' => $customerUser->id,
        'phone' => null,
    ]);

    $property = Property::factory()->forCustomer($customer)->create();

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'agent_id' => $agent->id,
        'agent_intent' => 'buying',
        'stage' => 'lead',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.onboarding')
        ->assertSet('isAgentSourced', true)
        ->set('phone', '555-123-4567')
        ->set('preferredContact', 'text')
        ->call('completeAgentSourced')
        ->assertRedirect(route('customer.dashboard'));

    $customer->refresh();
    expect($customer->phone)->toBe('555-123-4567');
    expect($customer->estimate_preference)->toBe('text');

    $customerUser->refresh();
    expect($customerUser->needs_onboarding)->toBeFalse();
});

it('redirects to dashboard with deal visible after agent-sourced completion', function () {
    $agent = User::factory()->agent()->create();
    $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    $property = Property::factory()->forCustomer($customer)->create();

    $deal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'agent_id' => $agent->id,
        'agent_intent' => 'selling',
        'title' => 'Agent Painting Project',
        'stage' => 'lead',
    ]);

    // Complete onboarding
    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.onboarding')
        ->set('phone', '555-000-1111')
        ->call('completeAgentSourced')
        ->assertRedirect(route('customer.dashboard'));

    // The deal should still exist (not duplicated) and be accessible on dashboard
    expect(Deal::where('customer_id', $customer->id)->count())->toBe(1);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Agent Painting Project');
});

it('shows standard 4-step onboarding for non-agent-sourced customer', function () {
    $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    // No deal with agent_intent
    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.onboarding')
        ->assertSet('isAgentSourced', false)
        ->assertSet('step', 1)
        ->assertDontSee('Get Started')
        ->assertSee('Continue');
});

it('does not create new deals or properties for agent-sourced customer', function () {
    $agent = User::factory()->agent()->create();
    $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    $property = Property::factory()->forCustomer($customer)->create();

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'agent_id' => $agent->id,
        'agent_intent' => 'selling',
        'stage' => 'lead',
    ]);

    $dealCountBefore = Deal::where('customer_id', $customer->id)->count();
    $propertyCountBefore = Property::where('customer_id', $customer->id)->count();

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.onboarding')
        ->set('phone', '555-999-0000')
        ->set('preferredContact', 'email')
        ->call('completeAgentSourced');

    expect(Deal::where('customer_id', $customer->id)->count())->toBe($dealCountBefore);
    expect(Property::where('customer_id', $customer->id)->count())->toBe($propertyCountBefore);
});
