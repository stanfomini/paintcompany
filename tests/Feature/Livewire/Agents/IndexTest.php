<?php

use App\Livewire\Agents\Index;
use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Index component', function () {
    it('renders the agent index component for internal users', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertStatus(200)
            ->assertSee('Agents');
    });

    it('does not allow access without authentication', function () {
        $this->get(route('agents.index'))
            ->assertRedirect(route('login'));
    });

    it('does not allow access for non-internal users', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->get(route('agents.index'))
            ->assertForbidden();
    });

    it('displays agents in the list', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create([
            'name' => 'John Agent',
            'email' => 'john.agent@example.com',
        ]);
        $agent->agentProfile->update([
            'company_name' => 'Apex Realty',
            'license_number' => 'LIC12345',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('John Agent')
            ->assertSee('john.agent@example.com')
            ->assertSee('Apex Realty')
            ->assertSee('LIC12345');
    });

    it('can search agents by name', function () {
        $user = User::factory()->internal()->create();
        $john = User::factory()->agent()->create(['name' => 'John Agent']);
        $jane = User::factory()->agent()->create(['name' => 'Jane Agent']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'John')
            ->assertSee('John Agent')
            ->assertDontSee('Jane Agent');
    });

    it('can search agents by company name', function () {
        $user = User::factory()->internal()->create();
        $agent1 = User::factory()->agent()->create(['name' => 'Agent One']);
        $agent1->agentProfile->update(['company_name' => 'Apex Realty']);

        $agent2 = User::factory()->agent()->create(['name' => 'Agent Two']);
        $agent2->agentProfile->update(['company_name' => 'Other Company']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Apex')
            ->assertSee('Agent One')
            ->assertDontSee('Agent Two');
    });

    it('can search agents by license number', function () {
        $user = User::factory()->internal()->create();
        $agent1 = User::factory()->agent()->create(['name' => 'Agent One']);
        $agent1->agentProfile->update(['license_number' => 'LIC12345']);

        $agent2 = User::factory()->agent()->create(['name' => 'Agent Two']);
        $agent2->agentProfile->update(['license_number' => 'ABC99999']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'LIC12345')
            ->assertSee('Agent One')
            ->assertDontSee('Agent Two');
    });

    it('can filter agents by active status', function () {
        $user = User::factory()->internal()->create();
        $activeAgent = User::factory()->agent()->create([
            'name' => 'Active Agent',
            'is_active' => true,
        ]);
        $inactiveAgent = User::factory()->agent()->inactive()->create([
            'name' => 'Inactive Agent',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'active')
            ->assertSee('Active Agent')
            ->assertDontSee('Inactive Agent');
    });

    it('can filter agents by inactive status', function () {
        $user = User::factory()->internal()->create();
        $activeAgent = User::factory()->agent()->create([
            'name' => 'Active Agent',
            'is_active' => true,
        ]);
        $inactiveAgent = User::factory()->agent()->inactive()->create([
            'name' => 'Inactive Agent',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'inactive')
            ->assertSee('Inactive Agent')
            ->assertDontSee('Active Agent');
    });

    it('shows all statuses by default', function () {
        $user = User::factory()->internal()->create();
        $activeAgent = User::factory()->agent()->create([
            'name' => 'Active Agent',
            'is_active' => true,
        ]);
        $inactiveAgent = User::factory()->agent()->inactive()->create([
            'name' => 'Inactive Agent',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('statusFilter', 'all')
            ->assertSee('Active Agent')
            ->assertSee('Inactive Agent');
    });

    it('can activate an agent', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->inactive()->create([
            'name' => 'Inactive Agent',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('activate', $agent->id)
            ->assertSee('Agent activated successfully');

        expect($agent->fresh()->is_active)->toBeTrue();
    });

    it('can deactivate an agent', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create([
            'name' => 'Active Agent',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('deactivate', $agent->id)
            ->assertSee('Agent deactivated successfully');

        expect($agent->fresh()->is_active)->toBeFalse();
    });

    it('displays listings count', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create(['name' => 'Test Agent']);
        AgentListing::factory()->count(3)->forAgent($agent)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Test Agent')
            ->assertSee('3');
    });

    it('displays referred customers count', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create(['name' => 'Test Agent']);
        Customer::factory()->count(5)->create(['referred_by' => $agent->id, 'created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Test Agent')
            ->assertSee('5');
    });

    it('resets pagination when search changes', function () {
        $user = User::factory()->internal()->create();
        User::factory()->agent()->count(20)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when status filter changes', function () {
        $user = User::factory()->internal()->create();
        User::factory()->agent()->count(20)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'active')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no agents exist', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('No agents found');
    });

    it('shows empty state with appropriate message when search yields no results', function () {
        $user = User::factory()->internal()->create();
        User::factory()->agent()->create(['name' => 'John Agent']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'NonexistentAgent')
            ->assertSee('No agents found')
            ->assertSee('Try adjusting your search or filter criteria');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('search', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('perPage', 15);
    });

    it('only shows agents, not internal users or customers', function () {
        $internalUser = User::factory()->internal()->create(['name' => 'Internal User']);
        $agent = User::factory()->agent()->create(['name' => 'Agent User']);
        $customer = User::factory()->customer()->create(['name' => 'Customer User']);

        Livewire::actingAs($internalUser)
            ->test(Index::class)
            ->assertSee('Agent User')
            ->assertDontSee('Internal User')
            ->assertDontSee('Customer User');
    });
});
