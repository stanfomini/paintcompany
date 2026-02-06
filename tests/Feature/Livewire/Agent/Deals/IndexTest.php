<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Deals Index component', function () {
    it('renders the deals index component for authenticated agents', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertStatus(200)
            ->assertSee('My Deals');
    });

    it('displays deals for customers referred by the authenticated agent', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $deal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Kitchen Painting',
            'estimated_value' => 5000,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertSee('Kitchen Painting')
            ->assertSee('John Doe')
            ->assertSee('$5,000');
    });

    it('does not display deals from other agents', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $ownDeal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Own Deal',
        ]);

        $otherCustomer = Customer::factory()->referredByAgent($otherAgent)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $otherDeal = Deal::factory()->referredByAgent($otherAgent)->forCustomer($otherCustomer)->create([
            'title' => 'Other Deal',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertSee('Own Deal')
            ->assertDontSee('Other Deal');
    });

    it('can search deals by title', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        $kitchenDeal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Kitchen Painting',
        ]);

        $exteriorDeal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Exterior Touch-up',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->set('search', 'Kitchen')
            ->assertSee('Kitchen Painting')
            ->assertDontSee('Exterior Touch-up');
    });

    it('can search deals by customer name', function () {
        $agent = User::factory()->agent()->create();

        $johnCustomer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $janeCustomer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $johnDeal = Deal::factory()->referredByAgent($agent)->forCustomer($johnCustomer)->create([
            'title' => 'Johns Deal',
        ]);

        $janeDeal = Deal::factory()->referredByAgent($agent)->forCustomer($janeCustomer)->create([
            'title' => 'Janes Deal',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->set('search', 'John')
            ->assertSee('Johns Deal')
            ->assertDontSee('Janes Deal');
    });

    it('can filter deals by stage', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        $leadDeal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->lead()->create([
            'title' => 'Lead Deal',
        ]);

        $quotedDeal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->quoted()->create([
            'title' => 'Quoted Deal',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->set('stageFilter', 'lead')
            ->assertSee('Lead Deal')
            ->assertDontSee('Quoted Deal');
    });

    it('displays commission amount based on deal value', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        $deal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Test Deal',
            'estimated_value' => 10000,
        ]);

        // Commission is 10% = $1,000
        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertSee('$10,000')
            ->assertSee('Commission: $1,000');
    });

    it('displays stage badges with correct labels', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        $deal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->inProgress()->create([
            'title' => 'In Progress Deal',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertSee('In Progress');
    });

    it('resets pagination when search changes', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();
        Deal::factory()->count(20)->referredByAgent($agent)->forCustomer($customer)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when stage filter changes', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();
        Deal::factory()->count(20)->referredByAgent($agent)->forCustomer($customer)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->set('stageFilter', 'lead')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no deals exist', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertSee('No deals found')
            ->assertSee('Deals from your referred customers will appear here.');
    });

    it('shows empty state with appropriate message when search yields no results', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Kitchen Painting',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->set('search', 'NonexistentDeal')
            ->assertSee('No deals found')
            ->assertSee('Try adjusting your search or filter criteria.');
    });

    it('displays expected start and end dates', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        $deal = Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Test Deal',
            'expected_start_date' => '2026-02-15',
            'expected_end_date' => '2026-03-01',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertSee('Feb 15, 2026')
            ->assertSee('Mar 01, 2026');
    });

    it('has correct default values', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index')
            ->assertSet('search', '')
            ->assertSet('stageFilter', '')
            ->assertSet('perPage', 15);
    });

    it('returns correct badge color for each stage', function () {
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index');

        expect($component->instance()->getStageBadgeColor('lead'))->toBe('blue');
        expect($component->instance()->getStageBadgeColor('quoted'))->toBe('purple');
        expect($component->instance()->getStageBadgeColor('scheduled'))->toBe('yellow');
        expect($component->instance()->getStageBadgeColor('in_progress'))->toBe('orange');
        expect($component->instance()->getStageBadgeColor('completed'))->toBe('green');
        expect($component->instance()->getStageBadgeColor('paid'))->toBe('emerald');
        expect($component->instance()->getStageBadgeColor('cancelled'))->toBe('red');
        expect($component->instance()->getStageBadgeColor('unknown'))->toBe('zinc');
    });

    it('calculates commission correctly', function () {
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.deals.index');

        expect($component->instance()->calculateCommission(10000))->toBe(1000.0);
        expect($component->instance()->calculateCommission(5000))->toBe(500.0);
        expect($component->instance()->calculateCommission(null))->toBe(0.0);
    });
});

describe('Agent Deals Index page access', function () {
    it('redirects guests to agent login page', function () {
        $this->get(route('agent.deals.index'))
            ->assertRedirect(route('agent.login'));
    });

    it('renders deals page for authenticated agent', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.deals.index'))
            ->assertSuccessful()
            ->assertSeeLivewire('agent.deals.index');
    });

    it('redirects internal users from agent deals to internal dashboard', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        // Now redirects to internal dashboard instead of showing 403
        $this->actingAs($internalUser, 'agent')
            ->get(route('agent.deals.index'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects customer users from agent deals to customer dashboard', function () {
        $customer = User::factory()->customer()->create();

        // Now redirects to customer dashboard instead of showing 403
        $this->actingAs($customer, 'agent')
            ->get(route('agent.deals.index'))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });
});
