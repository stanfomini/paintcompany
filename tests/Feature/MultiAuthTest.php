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

    it('redirects internal user to their dashboard when logging in at agent portal', function () {
        User::factory()->internal()->withFullAccess()->create([
            'email' => 'internal@test.com',
        ]);

        $this->post(route('agent.login'), [
            'email' => 'internal@test.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated('web');
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

    it('redirects agent to their dashboard when logging in at customer portal', function () {
        User::factory()->agent()->create([
            'email' => 'agent@test.com',
        ]);

        $this->post(route('customer.login'), [
            'email' => 'agent@test.com',
            'password' => 'password',
        ])->assertRedirect(route('agent.dashboard'));

        $this->assertAuthenticated('agent');
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

describe('Universal login (any user type at /login)', function () {
    it('redirects customer to customer dashboard when logging in at main login', function () {
        User::factory()->customer()->create([
            'email' => 'customer@test.com',
        ]);

        $this->post(route('login.store'), [
            'email' => 'customer@test.com',
            'password' => 'password',
        ])->assertRedirect(route('customer.dashboard'));

        $this->assertAuthenticated('customer');
    });

    it('redirects agent to agent dashboard when logging in at main login', function () {
        User::factory()->agent()->create([
            'email' => 'agent@test.com',
        ]);

        $this->post(route('login.store'), [
            'email' => 'agent@test.com',
            'password' => 'password',
        ])->assertRedirect(route('agent.dashboard'));

        $this->assertAuthenticated('agent');
    });

    it('redirects crew to crew dashboard when logging in at main login', function () {
        User::factory()->crew()->create([
            'email' => 'crew@test.com',
        ]);

        $this->post(route('login.store'), [
            'email' => 'crew@test.com',
            'password' => 'password',
        ])->assertRedirect(route('crew.dashboard'));

        $this->assertAuthenticated('crew');
    });

    it('still rejects invalid credentials at main login', function () {
        User::factory()->customer()->create([
            'email' => 'customer@test.com',
        ]);

        $this->post(route('login.store'), [
            'email' => 'customer@test.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors();

        $this->assertGuest('web');
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

        // Agent on web guard is a cross-guard mismatch - logs out and redirects to correct login
        $this->actingAs($user, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('agent.login'))
            ->assertSessionHas('warning');
    });

    it('customer cannot access internal dashboard', function () {
        $user = User::factory()->customer()->create();

        // Customer on web guard is a cross-guard mismatch - logs out and redirects to correct login
        $this->actingAs($user, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('customer.login'))
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

        // Internal user on agent guard is a cross-guard mismatch - logs out and redirects to correct login
        $this->actingAs($user, 'agent')
            ->get(route('agent.dashboard'))
            ->assertRedirect(route('login'))
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

        // Agent on customer guard is a cross-guard mismatch - logs out and redirects to correct login
        $this->actingAs($user, 'customer')
            ->get(route('customer.dashboard'))
            ->assertRedirect(route('agent.login'))
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

describe('CSRF token mismatch (419) handling', function () {
    it('redirects back with error flash for web requests', function () {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $request = \Illuminate\Http\Request::create(route('customer.login'), 'POST', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
        $request->setLaravelSession(app('session.store'));

        $response = $handler->render($request, new \Illuminate\Session\TokenMismatchException);

        expect($response->getStatusCode())->toBe(302)
            ->and($response->headers->get('Location'))->not->toBeNull()
            ->and(session('error'))->toBe('Your session has expired. Please try again.');
    });

    it('excludes password from redirect input', function () {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $request = \Illuminate\Http\Request::create(route('customer.login'), 'POST', [
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);
        $request->setLaravelSession(app('session.store'));

        $response = $handler->render($request, new \Illuminate\Session\TokenMismatchException);

        expect($response->getStatusCode())->toBe(302)
            ->and(session()->get('_old_input.email'))->toBe('test@example.com')
            ->and(session()->get('_old_input.password'))->toBeNull();
    });

    it('returns 419 JSON response for API requests', function () {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $request = \Illuminate\Http\Request::create(route('customer.login'), 'POST', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
        $request->headers->set('Accept', 'application/json');

        $response = $handler->render($request, new \Illuminate\Session\TokenMismatchException);

        expect($response->getStatusCode())->toBe(419)
            ->and($response->getData()->message)->toBe('Your session has expired. Please refresh and try again.');
    });
});

describe('Cross-guard login does not cycle remember token', function () {
    it('does not change remember_token when customer logs in via main login', function () {
        $user = User::factory()->customer()->create([
            'email' => 'customer@test.com',
        ]);

        $originalToken = $user->remember_token;

        $this->post(route('login.store'), [
            'email' => 'customer@test.com',
            'password' => 'password',
        ])->assertRedirect(route('customer.dashboard'));

        $user->refresh();

        expect($user->remember_token)->toBe($originalToken);
        $this->assertAuthenticated('customer');
    });

    it('does not change remember_token when agent logs in via main login', function () {
        $user = User::factory()->agent()->create([
            'email' => 'agent@test.com',
        ]);

        $originalToken = $user->remember_token;

        $this->post(route('login.store'), [
            'email' => 'agent@test.com',
            'password' => 'password',
        ])->assertRedirect(route('agent.dashboard'));

        $user->refresh();

        expect($user->remember_token)->toBe($originalToken);
        $this->assertAuthenticated('agent');
    });
});
