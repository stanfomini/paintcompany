<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

describe('People Index component', function () {
    it('renders the people index component for internal users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->assertStatus(200)
            ->assertSee('People');
    });

    it('does not allow access without authentication', function () {
        $this->get(route('people.index'))
            ->assertRedirect(route('login'));
    });

    it('redirects non-internal users to their dashboard', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->get(route('people.index'))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('shows activity tab by default', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->assertSet('activeTab', 'activity');
    });

    it('can switch to customers tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'customers')
            ->assertSet('activeTab', 'customers');
    });

    it('can switch to agents tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'agents')
            ->assertSet('activeTab', 'agents');
    });

    it('can switch to crews tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'crews')
            ->assertSet('activeTab', 'crews');
    });

    it('displays customers in the customers tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'customers')
            ->assertSee('John Doe');
    });

    it('displays agents in the agents tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create(['name' => 'Jane Agent']);

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'agents')
            ->assertSee('Jane Agent');
    });

    it('displays crews in the crews tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create(['name' => 'Mike Painter']);

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'crews')
            ->assertSee('Mike Painter');
    });

    it('can search customers within customers tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'created_by' => $user->id,
        ]);
        Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'customers')
            ->set('customerSearch', 'John')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith');
    });

    it('can search agents within agents tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        User::factory()->agent()->create(['name' => 'John Agent']);
        User::factory()->agent()->create(['name' => 'Jane Agent']);

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'agents')
            ->set('agentSearch', 'John')
            ->assertSee('John Agent')
            ->assertDontSee('Jane Agent');
    });

    it('can search crews within crews tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        User::factory()->crew()->create(['name' => 'John Painter']);
        User::factory()->crew()->create(['name' => 'Jane Painter']);

        Livewire::actingAs($user)
            ->test('people.index')
            ->call('setTab', 'crews')
            ->set('crewSearch', 'John')
            ->assertSee('John Painter')
            ->assertDontSee('Jane Painter');
    });

    it('global search finds customers', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        Customer::factory()->create([
            'first_name' => 'SearchableCustomer',
            'last_name' => 'Name',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('people.index')
            ->set('globalSearch', 'SearchableCustomer')
            ->assertSee('SearchableCustomer');
    });

    it('global search finds agents', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        User::factory()->agent()->create(['name' => 'SearchableAgent']);

        Livewire::actingAs($user)
            ->test('people.index')
            ->set('globalSearch', 'SearchableAgent')
            ->assertSee('SearchableAgent');
    });

    it('global search finds crews', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        User::factory()->crew()->create(['name' => 'SearchableCrew']);

        Livewire::actingAs($user)
            ->test('people.index')
            ->set('globalSearch', 'SearchableCrew')
            ->assertSee('SearchableCrew');
    });

    it('shows no results message when global search yields no results', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->set('globalSearch', 'NonexistentPerson12345')
            ->assertSee('No results found');
    });

    it('shows empty state in activity tab when no activity', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->assertSee('All caught up');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('people.index')
            ->assertSet('activeTab', 'activity')
            ->assertSet('globalSearch', '')
            ->assertSet('timeFilter', 'today')
            ->assertSet('customerSearch', '')
            ->assertSet('agentSearch', '')
            ->assertSet('crewSearch', '');
    });
});
