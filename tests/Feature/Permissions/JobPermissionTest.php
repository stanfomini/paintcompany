<?php

use App\Models\Job;
use App\Models\User;

beforeEach(function () {
    $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
    $seeder->run();
});

it('redirects unauthenticated users to login for jobs index', function () {
    $this->get(route('jobs.index'))
        ->assertRedirect(route('login'));
});

it('redirects unauthenticated users to login for jobs show', function () {
    $job = Job::factory()->create();

    $this->get(route('jobs.show', $job))
        ->assertRedirect(route('login'));
});

it('returns 403 for internal user without jobs.view permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('jobs.index'))
        ->assertForbidden();
});

it('allows internal user with jobs.view to access index and show', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('jobs.view');

    $job = Job::factory()->create();

    $this->actingAs($user)
        ->get(route('jobs.index'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('jobs.show', $job))
        ->assertOk();
});
