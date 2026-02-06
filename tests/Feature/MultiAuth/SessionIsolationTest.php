<?php

use App\Models\User;

describe('Multi-Auth Session Isolation', function () {
    it('redirects unauthenticated user to correct login page based on URL', function () {
        $this->get('/customer/dashboard')
            ->assertRedirect(route('customer.login'));

        $this->get('/agent/dashboard')
            ->assertRedirect(route('agent.login'));

        $this->get('/crew/dashboard')
            ->assertRedirect(route('crew.login'));

        $this->get('/dashboard')
            ->assertRedirect(route('login'));
    });

    it('redirects customer to their dashboard when accessing agent area', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'customer')
            ->get('/agent/dashboard')
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects agent to their dashboard when accessing customer area', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get('/customer/dashboard')
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects crew to their dashboard when accessing agent area', function () {
        $crew = User::factory()->crew()->create();

        $this->actingAs($crew, 'crew')
            ->get('/agent/dashboard')
            ->assertRedirect(route('crew.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects internal user to their dashboard when accessing customer area', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($internal, 'web')
            ->get('/customer/dashboard')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning');
    });

    it('allows customer to access customer routes', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'customer')
            ->get('/customer/dashboard')
            ->assertOk();
    });

    it('allows agent to access agent routes', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get('/agent/dashboard')
            ->assertOk();
    });

    it('allows crew to access crew routes', function () {
        $crew = User::factory()->crew()->create();

        $this->actingAs($crew, 'crew')
            ->get('/crew/dashboard')
            ->assertOk();
    });

    it('allows internal user to access internal routes', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($internal, 'web')
            ->get('/dashboard')
            ->assertOk();
    });

    it('handles deactivated customer account by redirecting to login', function () {
        $customer = User::factory()->customer()->create(['is_active' => false]);

        $this->actingAs($customer, 'customer')
            ->get('/customer/dashboard')
            ->assertRedirect(route('customer.login'))
            ->assertSessionHas('error');
    });

    it('handles deactivated agent account by redirecting to login', function () {
        $agent = User::factory()->agent()->create(['is_active' => false]);

        $this->actingAs($agent, 'agent')
            ->get('/agent/dashboard')
            ->assertRedirect(route('agent.login'))
            ->assertSessionHas('error');
    });

    it('shows appropriate warning message when redirecting wrong user type', function () {
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer, 'customer')
            ->get('/agent/dashboard');

        $response->assertRedirect(route('customer.dashboard'));
        $this->assertStringContainsString('do not have access', session('warning'));
    });
});
