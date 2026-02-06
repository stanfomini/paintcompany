<?php

use App\Models\Deal;
use App\Models\User;

describe('Deal Permission Access', function () {
    it('redirects unauthenticated users to login for deal index', function () {
        $this->get(route('deals.index'))
            ->assertRedirect(route('login'));
    });

    it('redirects unauthenticated users to login for deal show', function () {
        $deal = Deal::factory()->create();

        $this->get(route('deals.show', $deal))
            ->assertRedirect(route('login'));
    });

    it('forbids internal user without deals.view permission from deal index', function () {
        $user = User::factory()->internal()->create();
        $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
        $seeder->run();

        $this->actingAs($user)
            ->get(route('deals.index'))
            ->assertForbidden();
    });

    it('allows internal user with deals.view permission to access deal index', function () {
        $user = User::factory()->internal()->create();
        $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
        $seeder->run();
        $user->givePermissionTo('deals.view');

        $this->actingAs($user)
            ->get(route('deals.index'))
            ->assertOk();
    });

    it('allows internal user with deals.view permission to access deal show', function () {
        $user = User::factory()->internal()->create();
        $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
        $seeder->run();
        $user->givePermissionTo('deals.view');

        $deal = Deal::factory()->create(['created_by' => $user->id]);

        $this->actingAs($user)
            ->get(route('deals.show', $deal))
            ->assertOk();
    });
});
