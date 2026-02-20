<?php

use App\Models\Deal;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

describe('Deal Chat button visibility', function () {
    it('shows Deal Chat button to users with chat.view_deal_chats permission', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin'); // admin has chat.view_deal_chats

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertSee('Deal Chat');
    });

    it('hides Deal Chat button from users without chat.view_deal_chats permission', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('door_to_door'); // door_to_door does NOT have chat.view_deal_chats

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertDontSee('Deal Chat');
    });

    it('hides Deal Chat button from HR manager without chat.view_deal_chats', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('hr_manager'); // hr_manager does NOT have chat.view_deal_chats

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertDontSee('Deal Chat');
    });

    it('shows Deal Chat button to internal users without roles (backward compat)', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('chat.access');
        // No role assigned - should allow deal chats for backward compatibility

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertSee('Deal Chat');
    });
});

describe('Deal Chat creation enforcement', function () {
    it('prevents deal chat creation for users without chat.view_deal_chats', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('door_to_door');

        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->set('type', 'deal')
            ->set('selectedDealId', $deal->id)
            ->call('createThread')
            ->assertHasErrors('general');
    });

    it('allows deal chat creation for users with chat.view_deal_chats', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->set('type', 'deal')
            ->set('selectedDealId', $deal->id)
            ->call('createThread')
            ->assertHasNoErrors('general')
            ->assertRedirect();
    });

    it('does not show deals in the list for users without chat.view_deal_chats', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('door_to_door');

        $deal = Deal::factory()->create(['title' => 'Visible Deal Test']);

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->set('type', 'deal')
            ->assertDontSee('Visible Deal Test');
    });
});

describe('User list respects chat.with_* permissions', function () {
    it('only shows internal users when user has only chat.with_internal', function () {
        $user = User::factory()->internal()->create();

        $restrictedRole = Role::firstOrCreate(['name' => 'internal_only_chat', 'guard_name' => 'web']);
        $restrictedRole->syncPermissions(['chat.access', 'chat.with_internal']);
        $user->assignRole('internal_only_chat');

        $internal = User::factory()->internal()->create(['name' => 'Internal Person Visible']);
        $customer = User::factory()->customer()->create(['name' => 'Customer Person Hidden']);
        $agent = User::factory()->agent()->create(['name' => 'Agent Person Hidden']);
        $crew = User::factory()->crew()->create(['name' => 'Crew Person Hidden']);

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertSee('Internal Person Visible')
            ->assertDontSee('Customer Person Hidden')
            ->assertDontSee('Agent Person Hidden')
            ->assertDontSee('Crew Person Hidden');
    });

    it('shows all user types when user has all chat.with_* permissions', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        User::factory()->internal()->create(['name' => 'Internal Person Show']);
        User::factory()->customer()->create(['name' => 'Customer Person Show']);
        User::factory()->agent()->create(['name' => 'Agent Person Show']);
        User::factory()->crew()->create(['name' => 'Crew Person Show']);

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertSee('Internal Person Show')
            ->assertSee('Customer Person Show')
            ->assertSee('Agent Person Show')
            ->assertSee('Crew Person Show');
    });

    it('excludes crew when user has chat.with_customers and chat.with_agents but not chat.with_crew', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');

        User::factory()->internal()->create(['name' => 'Internal Est Visible']);
        User::factory()->customer()->create(['name' => 'Customer Est Visible']);
        User::factory()->agent()->create(['name' => 'Agent Est Visible']);
        User::factory()->crew()->create(['name' => 'Crew Est Hidden']);

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertSee('Internal Est Visible')
            ->assertSee('Customer Est Visible')
            ->assertSee('Agent Est Visible')
            ->assertDontSee('Crew Est Hidden');
    });
});

describe('Direct message permission enforcement', function () {
    it('prevents creating direct thread with user type not in permissions', function () {
        $user = User::factory()->internal()->create();

        $restrictedRole = Role::firstOrCreate(['name' => 'internal_only_chat', 'guard_name' => 'web']);
        $restrictedRole->syncPermissions(['chat.access', 'chat.with_internal']);
        $user->assignRole('internal_only_chat');

        $customer = User::factory()->customer()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->set('type', 'direct')
            ->set('selectedUserId', $customer->id)
            ->call('createThread')
            ->assertHasErrors('selectedUserId');
    });

    it('allows creating direct thread with permitted user type', function () {
        $user = User::factory()->internal()->create();

        $restrictedRole = Role::firstOrCreate(['name' => 'internal_only_chat', 'guard_name' => 'web']);
        $restrictedRole->syncPermissions(['chat.access', 'chat.with_internal']);
        $user->assignRole('internal_only_chat');

        $internal = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->set('type', 'direct')
            ->set('selectedUserId', $internal->id)
            ->call('createThread')
            ->assertHasNoErrors()
            ->assertRedirect();
    });
});
