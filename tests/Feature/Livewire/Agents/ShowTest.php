<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Show component', function () {
    it('renders the show component with agent data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create([
            'name' => 'John Agent',
            'email' => 'john.agent@example.com',
        ]);
        $agent->agentProfile->update([
            'company_name' => 'Apex Realty',
            'license_number' => 'LIC12345',
            'phone' => '555-1234',
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->assertStatus(200)
            ->assertSee('John Agent')
            ->assertSee('john.agent@example.com')
            ->assertSee('Apex Realty')
            ->assertSee('LIC12345')
            ->assertSee('555-1234');
    });

    it('does not allow access without authentication', function () {
        $agent = User::factory()->agent()->create();

        $this->get(route('agents.show', $agent))
            ->assertRedirect(route('login'));
    });

    it('returns 404 for non-agent users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create(['name' => 'Internal User']);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $internalUser])
            ->assertStatus(404);
    });

    it('displays agent status badge', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $activeAgent = User::factory()->agent()->create(['is_active' => true]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $activeAgent])
            ->assertStatus(200)
            ->assertSee('Active');
    });

    it('displays inactive status badge', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $inactiveAgent = User::factory()->agent()->inactive()->create();

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $inactiveAgent])
            ->assertStatus(200)
            ->assertSee('Inactive');
    });

    it('displays stats overview', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        Property::factory()->agentListing()->count(3)->forAgent($agent)->create();
        Customer::factory()->count(2)->create(['referred_by' => $agent->id, 'created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->assertSee('Total Listings')
            ->assertSee('Customers Referred')
            ->assertSee('Active Deals')
            ->assertSee('Open Tasks');
    });

    it('displays bio when available', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update([
            'bio' => 'This is the agent bio text.',
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->assertSee('This is the agent bio text.');
    });

    it('can switch to listings tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '123 Test Street',
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->assertSet('activeTab', 'overview')
            ->call('setTab', 'listings')
            ->assertSet('activeTab', 'listings')
            ->assertSee('123 Test Street');
    });

    it('can switch to customers tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Customer',
            'referred_by' => $agent->id,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'customers')
            ->assertSet('activeTab', 'customers')
            ->assertSee('John Customer');
    });

    it('displays empty state for listings when none exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'listings')
            ->assertSee('No listings')
            ->assertSee('This agent has no listings yet');
    });

    it('displays empty state for customers when none exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'customers')
            ->assertSee('No referred customers')
            ->assertSee('This agent has not referred any customers yet');
    });

    it('loads relationships correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '789 Relationship Lane',
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->assertStatus(200)
            ->assertSee($agent->name)
            ->call('setTab', 'listings')
            ->assertSee('789 Relationship Lane');
    });

    it('displays listing details correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '456 Main Ave',
            'city' => 'Testville',
            'state' => 'CA',
            'zip' => '90210',
            'listing_price' => 500000.00,
            'listing_status' => 'active',
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'listings')
            ->assertSee('456 Main Ave')
            ->assertSee('Testville')
            ->assertSee('CA')
            ->assertSee('90210')
            ->assertSee('$500,000.00');
    });

    it('uses the app layout', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent]);

        $component->assertStatus(200);
    });
});

describe('Agent Show - Deals', function () {
    it('shows agent deals with stage and customer name on deals tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Homeowner',
            'created_by' => $user->id,
        ]);
        Deal::factory()->create([
            'agent_id' => $agent->id,
            'customer_id' => $customer->id,
            'title' => 'Exterior Paint Job',
            'stage' => 'quoted',
            'estimated_value' => 5000,
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'deals')
            ->assertSee('Exterior Paint Job')
            ->assertSee('Jane Homeowner')
            ->assertSee('Quoted')
            ->assertSee('$5,000');
    });

    it('shows deals count in tab and stats', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        Deal::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'stage' => 'lead',
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->assertSee('Active Deals')
            ->assertSee('Deals (2)');
    });

    it('shows empty state when no deals exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'deals')
            ->assertSee('No deals')
            ->assertSee('This agent has no deals yet');
    });
});

describe('Agent Show - Activities', function () {
    it('shows agent activities with completion status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Activity::factory()->task()->forUser($agent)->byUser($user)->create([
            'title' => 'Onboard new agent',
            'is_completed' => false,
            'due_at' => today(),
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'activities')
            ->assertSee('Onboard new agent');
    });

    it('can toggle activity completion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        $activity = Activity::factory()->task()->forUser($agent)->byUser($user)->create([
            'title' => 'Complete agent setup',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('toggleActivityComplete', $activity->id);

        expect($activity->fresh()->is_completed)->toBeTrue();
    });

    it('can toggle completed activity back to incomplete', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        $activity = Activity::factory()->task()->forUser($agent)->byUser($user)->completed()->create([
            'title' => 'Setup task',
        ]);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('toggleActivityComplete', $activity->id);

        expect($activity->fresh()->is_completed)->toBeFalse();
    });

    it('can add new activity via form', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('openAddActivity')
            ->assertSet('showAddActivity', true)
            ->set('activityTitle', 'Follow up with agent')
            ->set('activityType', 'call')
            ->set('activityDueAt', today()->format('Y-m-d'))
            ->call('saveActivity');

        $activity = Activity::where('subject_type', User::class)
            ->where('subject_id', $agent->id)
            ->where('title', 'Follow up with agent')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('call')
            ->and($activity->user_id)->toBe($user->id)
            ->and($activity->subject_type)->toBe(User::class)
            ->and($activity->subject_id)->toBe($agent->id);
    });

    it('validates activity title is required', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('openAddActivity')
            ->set('activityTitle', '')
            ->call('saveActivity')
            ->assertHasErrors('activityTitle');
    });

    it('shows quick contact buttons', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->assertSeeHtml('tel:303-555-1234')
            ->assertSeeHtml('mailto:'.$agent->email);
    });

    it('shows empty state for activities when none exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('agents.show', ['user' => $agent])
            ->call('setTab', 'activities')
            ->assertSee('No activities')
            ->assertSee('No activities for this agent yet');
    });
});
