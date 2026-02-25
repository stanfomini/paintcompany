<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\NewProjectRequested;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Test: customer can view dashboard
it('shows dashboard to authenticated customer', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user, 'customer')
        ->get(route('customer.dashboard'))
        ->assertSuccessful();
});

// Test: dashboard shows deals for this customer (project switcher visible with 2+ deals)
it('shows customer deals on dashboard', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal1 = Deal::factory()->create(['customer_id' => $customer->id, 'title' => 'Living Room Paint']);
    $deal2 = Deal::factory()->create(['customer_id' => $customer->id, 'title' => 'Exterior Refresh']);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Living Room Paint')
        ->assertSee('Exterior Refresh');
});

// Test: dashboard does not show other customer's deals
it('does not show other customer deals', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    $otherCustomer = Customer::factory()->create();
    $otherDeal = Deal::factory()->create(['customer_id' => $otherCustomer->id, 'title' => 'Secret Project']);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertDontSee('Secret Project');
});

// Test: dashboard shows action items for sent estimates
it('shows action items for pending estimates', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->create([
        'status' => 'sent',
        'sent_at' => now(),
        'total' => 5000,
    ]);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Review your estimate');
});

// Test: dashboard shows no action items when everything is handled
it('shows no action items when all clear', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->create([
        'status' => 'draft',
    ]);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertDontSee('Review your estimate');
});

// Test: project switcher works
it('switches between projects', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal1 = Deal::factory()->create(['customer_id' => $customer->id, 'title' => 'Kitchen Repaint']);
    $deal2 = Deal::factory()->create(['customer_id' => $customer->id, 'title' => 'Exterior Refresh']);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Kitchen Repaint')
        ->assertSee('Exterior Refresh')
        ->call('selectDeal', $deal2->id)
        ->assertSet('selectedDealId', $deal2->id);
});

// Test: guest cannot access dashboard
it('redirects guests to login', function () {
    $this->get(route('customer.dashboard'))
        ->assertRedirect();
});

// Test: new customer without profile sees welcome callout
it('shows welcome callout for customer without profile', function () {
    $user = User::factory()->customer()->create();

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Welcome');
});

// Test: sidebar has only 3 items
it('shows simplified sidebar', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user, 'customer')
        ->get(route('customer.dashboard'))
        ->assertSee('Dashboard')
        ->assertSee('Messages')
        ->assertSee('Referrals')
        ->assertDontSee('My Projects')
        ->assertDontSee('My Properties')
        ->assertSee('Invoices');
});

// Issue 7: Past projects section
it('separates active and past deals', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    // Active deal (signed = active, job in progress)
    Deal::factory()->create([
        'customer_id' => $customer->id,
        'title' => 'Kitchen Repaint',
        'stage' => 'signed',
    ]);

    // Past deal (has a completed job)
    $pastDeal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'title' => 'Old Bedroom Job',
        'stage' => 'signed',
    ]);
    $estimate = Estimate::factory()->forDeal($pastDeal)->create(['status' => 'accepted']);
    \App\Models\Job::factory()->create([
        'estimate_id' => $estimate->id,
        'deal_id' => $pastDeal->id,
        'stage' => 'completed',
    ]);

    $component = Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard');

    // Active deals include all non-lost/cancelled deals
    $activeDeals = $component->instance()->deals;
    expect($activeDeals)->toHaveCount(2);

    // Past deals only include those with completed jobs
    $pastDeals = $component->instance()->pastDeals;
    expect($pastDeals)->toHaveCount(1);
    expect($pastDeals->first()->title)->toBe('Old Bedroom Job');
});

it('shows past projects section when job is completed', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'title' => 'Completed Exterior',
        'stage' => 'signed',
    ]);
    $estimate = Estimate::factory()->forDeal($deal)->create(['status' => 'accepted']);
    \App\Models\Job::factory()->create([
        'estimate_id' => $estimate->id,
        'deal_id' => $deal->id,
        'stage' => 'completed',
    ]);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Past Projects');
});

it('hides past projects section when no jobs are completed', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'stage' => 'lead',
    ]);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertDontSee('Past Projects');
});

// Issue 6: New project and estimate request buttons link to onboarding
it('shows request estimate CTA when no projects exist', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Request a Free Estimate');
});

it('shows new project button in project switcher', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    Deal::factory()->create(['customer_id' => $customer->id, 'stage' => 'lead']);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('New Project');
});

it('shows collaborate button for draft estimates', function () {
    $user = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    Estimate::factory()->forDeal($deal)->create(['status' => 'draft']);

    Livewire::actingAs($user, 'customer')
        ->test('customer.dashboard')
        ->assertSee('Collaborate');
});

// Issue 6: Onboarding creates new project via the onboarding flow
it('creates a new project via onboarding flow', function () {
    Notification::fake();

    $user = User::factory()->customer()->create(['needs_onboarding' => false]);
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $internalUser = User::factory()->internal()->create();

    Livewire::withQueryParams(['new_project' => true])
        ->actingAs($user, 'customer')
        ->test('customer.onboarding')
        ->assertSet('isNewProject', true)
        ->assertSet('step', 2)
        ->set('address', '456 Oak Ave')
        ->set('city', 'Denver')
        ->set('state', 'CO')
        ->set('zip', '80202')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->set('projectNotes', 'Need the living room and hallway painted')
        ->call('nextStep')
        ->assertSet('step', 4)
        ->call('selectEstimatePreference', 'virtual');

    // Verify deal was created
    $deal = Deal::where('customer_id', $customer->id)->where('stage', 'lead')->latest('id')->first();
    expect($deal)->not->toBeNull();
    expect($deal->description)->toBe('Need the living room and hallway painted');

    // Verify property was created and linked
    expect($deal->property)->not->toBeNull();
    expect($deal->property->address)->toBe('456 Oak Ave');
    expect($deal->property->city)->toBe('Denver');

    // Verify draft estimate was created
    expect($deal->estimates)->toHaveCount(1);
    expect($deal->estimates->first()->status)->toBe('draft');

    // Verify notification was sent
    Notification::assertSentTo($internalUser, NewProjectRequested::class);
});

it('reuses bare deal when customer completes onboarding', function () {
    Notification::fake();

    $user = User::factory()->customer()->create(['needs_onboarding' => false]);
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $internalUser = User::factory()->internal()->create();

    // Team creates a bare deal (no property, no estimate)
    $bareDeal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'property_id' => null,
        'title' => 'Stanford Marketing - Lead',
        'stage' => 'lead',
        'created_by' => $internalUser->id,
    ]);

    Livewire::withQueryParams(['new_project' => true])
        ->actingAs($user, 'customer')
        ->test('customer.onboarding')
        ->assertSet('isNewProject', true)
        ->set('address', '789 Elm St')
        ->set('city', 'Boulder')
        ->set('state', 'CO')
        ->set('zip', '80301')
        ->call('nextStep')
        ->set('projectNotes', 'Full exterior repaint')
        ->call('nextStep')
        ->call('selectEstimatePreference', 'in_person');

    // Should NOT create a second deal — reuses the bare one
    $deals = Deal::where('customer_id', $customer->id)->get();
    expect($deals)->toHaveCount(1);

    // Bare deal should now have property + estimate attached
    $bareDeal->refresh();
    expect($bareDeal->property)->not->toBeNull();
    expect($bareDeal->property->address)->toBe('789 Elm St');
    expect($bareDeal->description)->toBe('Full exterior repaint');
    expect($bareDeal->estimates)->toHaveCount(1);
    expect($bareDeal->estimates->first()->status)->toBe('draft');
});

it('redirects returning customer to step 2 for new project', function () {
    $user = User::factory()->customer()->create(['needs_onboarding' => false]);
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    Livewire::withQueryParams(['new_project' => true])
        ->actingAs($user, 'customer')
        ->test('customer.onboarding')
        ->assertSet('step', 2)
        ->assertSet('isNewProject', true);
});

it('redirects onboarded customer away from onboarding without new_project flag', function () {
    $user = User::factory()->customer()->create(['needs_onboarding' => false]);
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user, 'customer')
        ->test('customer.onboarding')
        ->assertRedirect(route('customer.dashboard'));
});
