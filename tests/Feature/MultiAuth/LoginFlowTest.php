<?php

use App\Models\User;

describe('Multi-Auth Login Flow', function () {
    it('customer can login successfully', function () {
        $customer = User::factory()->customer()->create();

        $this->post('/customer/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertRedirect(route('customer.dashboard'));

        $this->assertAuthenticatedAs($customer, 'customer');
    });

    it('agent can login successfully', function () {
        $agent = User::factory()->agent()->create();

        $this->post('/agent/login', [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertRedirect(route('agent.dashboard'));

        $this->assertAuthenticatedAs($agent, 'agent');
    });

    it('crew can login successfully', function () {
        $crew = User::factory()->crew()->create();

        $this->post('/crew/login', [
            'email' => $crew->email,
            'password' => 'password',
        ])->assertRedirect(route('crew.dashboard'));

        $this->assertAuthenticatedAs($crew, 'crew');
    });

    it('customer cannot login via agent login page', function () {
        $customer = User::factory()->customer()->create();

        $this->post('/agent/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('agent');
    });

    it('agent cannot login via customer login page', function () {
        $agent = User::factory()->agent()->create();

        $this->post('/customer/login', [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    });

    it('inactive customer cannot login', function () {
        $customer = User::factory()->customer()->create(['is_active' => false]);

        $this->post('/customer/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    });

    it('customer logout invalidates session', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'customer')
            ->post('/customer/logout')
            ->assertRedirect(route('customer.login'));

        $this->assertGuest('customer');
    });

    it('agent logout invalidates session', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->post('/agent/logout')
            ->assertRedirect(route('agent.login'));

        $this->assertGuest('agent');
    });

    it('crew logout invalidates session', function () {
        $crew = User::factory()->crew()->create();

        $this->actingAs($crew, 'crew')
            ->post('/crew/logout')
            ->assertRedirect(route('crew.login'));

        $this->assertGuest('crew');
    });

    it('clears cross-guard intended URL on customer login', function () {
        // Visit agent area first (stores intended URL)
        $this->get('/agent/dashboard');

        // Create and login as customer
        $customer = User::factory()->customer()->create();

        $response = $this->post('/customer/login', [
            'email' => $customer->email,
            'password' => 'password',
        ]);

        // Should redirect to customer dashboard, not agent dashboard
        $response->assertRedirect(route('customer.dashboard'));
        $this->assertAuthenticatedAs($customer, 'customer');
    });

    it('clears cross-guard intended URL on agent login', function () {
        // Visit customer area first (stores intended URL)
        $this->get('/customer/dashboard');

        // Create and login as agent
        $agent = User::factory()->agent()->create();

        $response = $this->post('/agent/login', [
            'email' => $agent->email,
            'password' => 'password',
        ]);

        // Should redirect to agent dashboard, not customer dashboard
        $response->assertRedirect(route('agent.dashboard'));
        $this->assertAuthenticatedAs($agent, 'agent');
    });

    it('login page is accessible for guests', function () {
        $this->get('/customer/login')->assertOk();
        $this->get('/agent/login')->assertOk();
        $this->get('/crew/login')->assertOk();
        $this->get('/login')->assertOk();
    });

    it('login page redirects authenticated user to dashboard', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'customer')
            ->get('/customer/login')
            ->assertRedirect(route('customer.dashboard'));
    });

    it('customer cannot login via admin login page', function () {
        $customer = User::factory()->customer()->create();

        $this->post('/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertSessionHasErrors();

        $this->assertGuest('web');
    });

    it('agent cannot login via admin login page', function () {
        $agent = User::factory()->agent()->create();

        $this->post('/login', [
            'email' => $agent->email,
            'password' => 'password',
        ])->assertSessionHasErrors();

        $this->assertGuest('web');
    });

    it('crew cannot login via admin login page', function () {
        $crew = User::factory()->crew()->create();

        $this->post('/login', [
            'email' => $crew->email,
            'password' => 'password',
        ])->assertSessionHasErrors();

        $this->assertGuest('web');
    });

    it('cross-guard authentication logs out and redirects to correct login', function () {
        $customer = User::factory()->customer()->create();

        // Simulate a customer somehow authenticated on the web guard (the bug scenario)
        $this->actingAs($customer, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('customer.login'))
            ->assertSessionHas('warning');
    });
});
