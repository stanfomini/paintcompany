<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Sidebar - Permission Gating', function () {
    it('hides all permission-gated sidebar items from internal user with no permissions', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSuccessful();

        // Should NOT see any permission-gated navigation links
        $response->assertDontSee(route('people.index'));
        $response->assertDontSee(route('properties.index'));
        $response->assertDontSee(route('deals.index'));
        $response->assertDontSee(route('estimates.index'));
        $response->assertDontSee(route('jobs.index'));
        $response->assertDontSee(route('planner.index'));
        $response->assertDontSee(route('chat.index'));
        $response->assertDontSee(route('business-plan.index'));
    });

    it('shows only the People link when user has people.view permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSuccessful();

        // Should see People link
        $response->assertSee(route('people.index'));

        // Should NOT see other permission-gated links
        $response->assertDontSee(route('properties.index'));
        $response->assertDontSee(route('deals.index'));
        $response->assertDontSee(route('estimates.index'));
        $response->assertDontSee(route('jobs.index'));
        $response->assertDontSee(route('planner.index'));
        $response->assertDontSee(route('chat.index'));
        $response->assertDontSee(route('business-plan.index'));
    });

    it('shows only permitted sidebar items for a user with selective permissions', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('estimates.view');
        $user->givePermissionTo('chat.access');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSuccessful();

        // Should see granted items
        $response->assertSee(route('estimates.index'));
        $response->assertSee(route('chat.index'));

        // Should NOT see ungated items
        $response->assertDontSee(route('people.index'));
        $response->assertDontSee(route('properties.index'));
        $response->assertDontSee(route('deals.index'));
        $response->assertDontSee(route('jobs.index'));
        $response->assertDontSee(route('planner.index'));
        $response->assertDontSee(route('business-plan.index'));
    });

    it('shows all permission-gated sidebar items for full access user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSuccessful();

        // Should see all permission-gated navigation links
        $response->assertSee(route('people.index'));
        $response->assertSee(route('properties.index'));
        $response->assertSee(route('deals.index'));
        $response->assertSee(route('estimates.index'));
        $response->assertSee(route('jobs.index'));
        $response->assertSee(route('planner.index'));
        $response->assertSee(route('chat.index'));
        $response->assertSee(route('business-plan.index'));
    });
});
