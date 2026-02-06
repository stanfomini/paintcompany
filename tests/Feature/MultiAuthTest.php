<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Internal user authentication', function () {
    it('can view the login page', function () {
        $this->get(route('login'))
            ->assertSuccessful();
    });

    it('can login with valid credentials', function () {
        $user = User::factory()->internal()->withFullAccess()->create([
            'email' => 'internal@test.com',
        ]);

        $this->post(route('login.store'), [
            'email' => 'internal@test.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user, 'web');
    });

    it('cannot login with invalid credentials', function () {
        User::factory()->internal()->withFullAccess()->create([
            'email' => 'internal@test.com',
        ]);

        $this->post(route('login.store'), [
            'email' => 'internal@test.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
    });
});

describe('Agent authentication', function () {
    it('can view the agent login page', function () {
        $this->get(route('agent.login'))
            ->assertSuccessful();
    });

    it('can login with valid agent credentials', function () {
        $user = User::factory()->agent()->create([
            'email' => 'agent@test.com',
        ]);

        $this->post(route('agent.login'), [
            'email' => 'agent@test.com',
            'password' => 'password',
        ])->assertRedirect(route('agent.dashboard'));

        $this->assertAuthenticatedAs($user, 'agent');
    });

    it('cannot login with internal user credentials', function () {
        User::factory()->internal()->withFullAccess()->create([
            'email' => 'internal@test.com',
        ]);

        $this->post(route('agent.login'), [
            'email' => 'internal@test.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('agent');
    });

    it('cannot login with inactive account', function () {
        User::factory()->agent()->inactive()->create([
            'email' => 'inactive-agent@test.com',
        ]);

        $this->post(route('agent.login'), [
            'email' => 'inactive-agent@test.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('agent');
    });

    it('can view the agent registration page', function () {
        $this->get(route('agent.register'))
            ->assertSuccessful();
    });

    it('can register as a new agent', function () {
        $this->post(route('agent.register'), [
            'name' => 'New Agent',
            'email' => 'newagent@test.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'company_name' => 'Test Realty',
            'license_number' => 'RE-123456',
            'phone' => '555-123-4567',
        ])->assertRedirect(route('agent.dashboard'));

        $user = User::where('email', 'newagent@test.com')->first();

        expect($user)->not->toBeNull()
            ->and($user->type)->toBe('agent')
            ->and($user->agentProfile->company_name)->toBe('Test Realty');

        $this->assertAuthenticatedAs($user, 'agent');
    });
});

describe('Customer authentication', function () {
    it('can view the customer login page', function () {
        $this->get(route('customer.login'))
            ->assertSuccessful();
    });

    it('can login with valid customer credentials', function () {
        $user = User::factory()->customer()->create([
            'email' => 'customer@test.com',
        ]);

        $this->post(route('customer.login'), [
            'email' => 'customer@test.com',
            'password' => 'password',
        ])->assertRedirect(route('customer.dashboard'));

        $this->assertAuthenticatedAs($user, 'customer');
    });

    it('cannot login with agent credentials', function () {
        User::factory()->agent()->create([
            'email' => 'agent@test.com',
        ]);

        $this->post(route('customer.login'), [
            'email' => 'agent@test.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    });

    it('cannot login with inactive account', function () {
        User::factory()->customer()->inactive()->create([
            'email' => 'inactive-customer@test.com',
        ]);

        $this->post(route('customer.login'), [
            'email' => 'inactive-customer@test.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    });
});

describe('Access control', function () {
    it('internal user can access internal dashboard', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user, 'web')
            ->get(route('dashboard'))
            ->assertSuccessful();
    });

    it('agent cannot access internal dashboard', function () {
        $user = User::factory()->agent()->create();

        // Now redirects to agent dashboard instead of showing 403
        $this->actingAs($user, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('customer cannot access internal dashboard', function () {
        $user = User::factory()->customer()->create();

        // Now redirects to customer dashboard instead of showing 403
        $this->actingAs($user, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });

    it('agent can access agent dashboard', function () {
        $user = User::factory()->agent()->create();

        $this->actingAs($user, 'agent')
            ->get(route('agent.dashboard'))
            ->assertSuccessful();
    });

    it('internal user cannot access agent dashboard', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        // Now redirects to internal dashboard instead of showing 403
        $this->actingAs($user, 'agent')
            ->get(route('agent.dashboard'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning');
    });

    it('customer can access customer dashboard', function () {
        $user = User::factory()->customer()->create();

        $this->actingAs($user, 'customer')
            ->get(route('customer.dashboard'))
            ->assertSuccessful();
    });

    it('agent cannot access customer dashboard', function () {
        $user = User::factory()->agent()->create();

        // Now redirects to agent dashboard instead of showing 403
        $this->actingAs($user, 'customer')
            ->get(route('customer.dashboard'))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });
});

describe('Logout', function () {
    it('agent can logout', function () {
        $user = User::factory()->agent()->create();

        $this->actingAs($user, 'agent')
            ->post(route('agent.logout'))
            ->assertRedirect(route('agent.login'));

        $this->assertGuest('agent');
    });

    it('customer can logout', function () {
        $user = User::factory()->customer()->create();

        $this->actingAs($user, 'customer')
            ->post(route('customer.logout'))
            ->assertRedirect(route('customer.login'));

        $this->assertGuest('customer');
    });
});
