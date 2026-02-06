<?php

use App\Models\Job;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Jobs Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $job = Job::factory()->create();

        $this->get(route('jobs.index'))->assertRedirect(route('login'));
        $this->get(route('jobs.show', $job))->assertRedirect(route('login'));
    });
});

describe('Jobs Routes - Without Permission', function () {
    it('forbids internal user without jobs.view permission from jobs index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('jobs.index'))
            ->assertForbidden();
    });

    it('forbids internal user without jobs.view permission from job show', function () {
        $user = User::factory()->internal()->create();
        $job = Job::factory()->create();

        $this->actingAs($user)
            ->get(route('jobs.show', $job))
            ->assertForbidden();
    });
});

describe('Jobs Routes - With Permission', function () {
    it('allows internal user with jobs.view permission to access jobs index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('jobs.view');

        $this->actingAs($user)
            ->get(route('jobs.index'))
            ->assertSuccessful();
    });

    it('allows internal user with jobs.view permission to access job show', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('jobs.view');
        $job = Job::factory()->create();

        $this->actingAs($user)
            ->get(route('jobs.show', $job))
            ->assertSuccessful();
    });

    it('allows full access user to access all jobs routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        $this->actingAs($user)->get(route('jobs.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('jobs.show', $job))->assertSuccessful();
    });
});
