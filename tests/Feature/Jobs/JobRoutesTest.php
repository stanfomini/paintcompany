<?php

use App\Models\Job;
use App\Models\User;

describe('Job Routes', function () {
    it('can access job kanban page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('jobs.index'))
            ->assertOk();
    });

    it('can access job show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        $this->actingAs($user)
            ->get(route('jobs.show', $job))
            ->assertOk();
    });

    it('redirects unauthenticated users from kanban', function () {
        $this->get(route('jobs.index'))
            ->assertRedirect();
    });

    it('redirects unauthenticated users from show', function () {
        $job = Job::factory()->create();

        $this->get(route('jobs.show', $job))
            ->assertRedirect();
    });

    it('blocks customer users from kanban', function () {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)
            ->get(route('jobs.index'))
            ->assertForbidden();
    });

    it('blocks agent users from kanban', function () {
        $user = User::factory()->agent()->create();

        $this->actingAs($user)
            ->get(route('jobs.index'))
            ->assertForbidden();
    });
});
