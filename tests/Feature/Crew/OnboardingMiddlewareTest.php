<?php

use App\Models\User;

describe('Crew Onboarding Middleware', function () {

    // Routes accessible WITHOUT onboarding complete

    it('allows crew with onboarding pending to access dashboard', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => true]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.dashboard'))
            ->assertOk();
    });

    it('allows crew with onboarding pending to access onboarding page', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => true]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.onboarding'))
            ->assertOk();
    });

    it('allows crew with onboarding pending to access settings', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => true]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.settings'))
            ->assertOk();
    });

    it('allows crew with onboarding pending to access chat', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => true]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.chat.index'))
            ->assertOk();
    });

    it('allows crew with onboarding pending to access referrals', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => true]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.referrals'))
            ->assertOk();
    });

    // Routes BLOCKED until onboarding complete

    it('redirects crew with onboarding pending from available jobs to onboarding', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => true]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.jobs.available'))
            ->assertRedirect(route('crew.onboarding'));
    });

    it('redirects crew with onboarding pending from my jobs to onboarding', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => true]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.jobs.mine'))
            ->assertRedirect(route('crew.onboarding'));
    });

    // Onboarded crew can access all routes

    it('allows onboarded crew to access available jobs', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => false]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.jobs.available'))
            ->assertOk();
    });

    it('allows onboarded crew to access my jobs', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => false]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.jobs.mine'))
            ->assertOk();
    });

    it('allows onboarded crew to access dashboard', function () {
        $crew = User::factory()->crew()->create(['needs_onboarding' => false]);

        $this->actingAs($crew, 'crew')
            ->get(route('crew.dashboard'))
            ->assertOk();
    });

    // Unauthenticated users

    it('redirects unauthenticated users from crew dashboard to login', function () {
        $this->get(route('crew.dashboard'))
            ->assertRedirect(route('crew.login'));
    });

    it('redirects unauthenticated users from crew available jobs to login', function () {
        $this->get(route('crew.jobs.available'))
            ->assertRedirect(route('crew.login'));
    });
});
