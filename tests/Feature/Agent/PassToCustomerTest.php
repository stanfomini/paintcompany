<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Invitation;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AgentPassedEstimate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Pass to Customer Handoff', function () {
    it('opens the pass to customer modal', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->assertSet('showPassModal', true)
            ->assertSet('selectedDealId', $deal->id);
    });

    it('passes deal to a new customer and creates invitation', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', 'newcustomer@example.com')
            ->set('passFirstName', 'Jane')
            ->set('passLastName', 'Smith')
            ->call('passToCustomer')
            ->assertHasNoErrors()
            ->assertSet('showPassModal', false)
            ->assertDispatched('customer-assigned');

        // Customer record created
        $customer = Customer::where('email', 'newcustomer@example.com')->first();
        expect($customer)->not->toBeNull()
            ->and($customer->first_name)->toBe('Jane')
            ->and($customer->last_name)->toBe('Smith')
            ->and($customer->referred_by)->toBe($agent->id)
            ->and($customer->source)->toBe('agent');

        // Deal assigned to customer
        expect($deal->fresh()->customer_id)->toBe($customer->id);

        // Invitation created with deal_id
        $invitation = Invitation::where('email', 'newcustomer@example.com')->first();
        expect($invitation)->not->toBeNull()
            ->and($invitation->invited_user_type)->toBe('customer')
            ->and($invitation->deal_id)->toBe($deal->id)
            ->and($invitation->inviter_id)->toBe($agent->id);
    });

    it('passes deal to an existing customer user and sends notification', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $customerUser = User::factory()->customer()->create(['email' => 'existing@example.com']);
        $customerRecord = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'email' => 'existing@example.com',
            'first_name' => 'Existing',
            'last_name' => 'Customer',
        ]);

        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', 'existing@example.com')
            ->set('passFirstName', 'Existing')
            ->set('passLastName', 'Customer')
            ->call('passToCustomer')
            ->assertHasNoErrors()
            ->assertSet('showPassModal', false);

        // Deal assigned to existing customer
        expect($deal->fresh()->customer_id)->toBe($customerRecord->id);

        // Notification sent to existing customer user
        Notification::assertSentTo($customerUser, AgentPassedEstimate::class);

        // No invitation created for existing user
        expect(Invitation::where('email', 'existing@example.com')->exists())->toBeFalse();
    });

    it('validates required fields', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', '')
            ->set('passFirstName', '')
            ->set('passLastName', '')
            ->call('passToCustomer')
            ->assertHasErrors(['passEmail', 'passFirstName', 'passLastName']);
    });

    it('validates email format', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', 'not-an-email')
            ->set('passFirstName', 'Test')
            ->set('passLastName', 'User')
            ->call('passToCustomer')
            ->assertHasErrors(['passEmail']);
    });

    it('prevents passing when deal already has a customer', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->forCustomer($customer)->lead()->create();
        Estimate::factory()->forDeal($deal)->draft()->create();

        // openPassModal should not open if deal has customer
        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->assertSet('showPassModal', false);
    });

    it('prevents passing to a non-customer user type email', function () {
        $agent = User::factory()->agent()->create();
        // Create an internal user with this email
        User::factory()->internal()->create(['email' => 'internal@example.com']);

        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', 'internal@example.com')
            ->set('passFirstName', 'Test')
            ->set('passLastName', 'User')
            ->call('passToCustomer')
            ->assertHasErrors(['passEmail']);
    });

    it('only allows agent who owns deal to pass it', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($otherAgent)->create();
        $deal = Deal::factory()->referredByAgent($otherAgent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        // Agent trying to open modal for another agent's deal - should not open
        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->assertSet('showPassModal', false);
    });

    it('closes modal and resets fields after successful pass', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', 'test@example.com')
            ->set('passFirstName', 'Test')
            ->set('passLastName', 'User')
            ->call('passToCustomer')
            ->assertSet('showPassModal', false)
            ->assertSet('passEmail', '')
            ->assertSet('passFirstName', '')
            ->assertSet('passLastName', '');
    });
});
