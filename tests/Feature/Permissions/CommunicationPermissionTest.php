<?php

use App\Models\ChatThread;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Communication Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->withParticipants([$user])->create();

        $this->get(route('chat.index'))->assertRedirect(route('login'));
        $this->get(route('chat.show', $thread))->assertRedirect(route('login'));
        $this->get(route('walkie-talkie.room'))->assertRedirect(route('login'));
    });
});

describe('Communication Routes - Without Permission', function () {
    it('forbids internal user without chat.access permission from chat index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('chat.index'))
            ->assertForbidden();
    });

    it('forbids internal user without chat.access permission from chat show', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->withParticipants([$user])->create();

        $this->actingAs($user)
            ->get(route('chat.show', $thread))
            ->assertForbidden();
    });

    it('forbids internal user without walkie_talkie.access permission from walkie-talkie', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('walkie-talkie.room'))
            ->assertForbidden();
    });

});

describe('Communication Routes - With Permission', function () {
    it('allows internal user with chat.access permission to access chat index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('chat.access');

        $this->actingAs($user)
            ->get(route('chat.index'))
            ->assertSuccessful();
    });

    it('allows internal user with chat.access permission to access chat show', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('chat.access');
        $thread = ChatThread::factory()->withParticipants([$user])->create();

        $this->actingAs($user)
            ->get(route('chat.show', $thread))
            ->assertSuccessful();
    });

    it('allows internal user with walkie_talkie.access permission to access walkie-talkie', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('walkie_talkie.access');

        $this->actingAs($user)
            ->get(route('walkie-talkie.room'))
            ->assertSuccessful();
    });

    it('allows full access user to access all communication routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->withParticipants([$user])->create();

        $this->actingAs($user)->get(route('chat.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('chat.show', $thread))->assertSuccessful();
        $this->actingAs($user)->get(route('walkie-talkie.room'))->assertSuccessful();
    });
});
