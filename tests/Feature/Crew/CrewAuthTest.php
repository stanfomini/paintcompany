<?php

use App\Models\CrewProfile;
use App\Models\User;

it('can show crew login page', function () {
    $this->get(route('crew.login'))
        ->assertOk()
        ->assertSee('Crew Portal Login');
});

it('can show crew registration page', function () {
    $this->get(route('crew.register'))
        ->assertOk()
        ->assertSee('Crew Registration');
});

it('can register as crew', function () {
    $response = $this->post(route('crew.register'), [
        'name' => 'Test Crew',
        'email' => 'crew@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'company_name' => 'Smith Painting',
        'phone' => '555-123-4567',
        'crew_size' => 3,
    ]);

    $response->assertRedirect(route('crew.dashboard'));

    $user = User::where('email', 'crew@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->type)->toBe('crew')
        ->and($user->isCrew())->toBeTrue()
        ->and($user->crewProfile)->not->toBeNull()
        ->and($user->crewProfile->company_name)->toBe('Smith Painting')
        ->and($user->crewProfile->crew_size)->toBe(3);
});

it('auto-creates crew profile on user creation', function () {
    $user = User::factory()->crew()->create();

    expect($user->crewProfile)->not->toBeNull()
        ->and($user->crewProfile)->toBeInstanceOf(CrewProfile::class)
        ->and($user->crewProfile->crew_size)->toBe(1);
});

it('can login as crew', function () {
    $user = User::factory()->crew()->create([
        'password' => bcrypt('password'),
    ]);

    $response = $this->post(route('crew.login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('crew.dashboard'));
    $this->assertAuthenticatedAs($user, 'crew');
});

it('cannot login with wrong credentials', function () {
    $user = User::factory()->crew()->create([
        'password' => bcrypt('password'),
    ]);

    $this->post(route('crew.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('crew');
});

it('cannot login as crew with non-crew account', function () {
    $user = User::factory()->internal()->withFullAccess()->create([
        'password' => bcrypt('password'),
    ]);

    $this->post(route('crew.login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('crew');
});

it('cannot login as inactive crew', function () {
    $user = User::factory()->crew()->inactive()->create([
        'password' => bcrypt('password'),
    ]);

    $this->post(route('crew.login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('crew');
});

it('can logout as crew', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->post(route('crew.logout'))
        ->assertRedirect(route('crew.login'));

    $this->assertGuest('crew');
});

it('can access crew dashboard when authenticated', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->get(route('crew.dashboard'))
        ->assertOk()
        ->assertSee('Crew Dashboard');
});

it('redirects to crew login when not authenticated', function () {
    $this->get(route('crew.dashboard'))
        ->assertRedirect(route('crew.login'));
});

it('denies non-crew user access to crew routes', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user, 'crew')
        ->get(route('crew.dashboard'))
        ->assertForbidden();
});

it('has isCrew method on User model', function () {
    $crew = User::factory()->crew()->create();
    $internal = User::factory()->internal()->withFullAccess()->create();
    $agent = User::factory()->agent()->create();

    expect($crew->isCrew())->toBeTrue()
        ->and($internal->isCrew())->toBeFalse()
        ->and($agent->isCrew())->toBeFalse();
});
