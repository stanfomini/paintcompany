<?php

use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Create permissions
    Permission::firstOrCreate(['name' => 'manage roles', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'view roadmap', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'edit roadmap', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'settings.company', 'guard_name' => 'web']);

    // Create all roles
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());

    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->syncPermissions(['view roadmap', 'edit roadmap']);

    $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    $managerRole->syncPermissions(['view roadmap', 'edit roadmap']);

    $estimatorRole = Role::firstOrCreate(['name' => 'estimator', 'guard_name' => 'web']);
    $estimatorRole->syncPermissions(['view roadmap']);

    $crewLeadRole = Role::firstOrCreate(['name' => 'crew_lead', 'guard_name' => 'web']);
    $crewLeadRole->syncPermissions(['view roadmap']);
});

it('requires authentication to access roles page', function () {
    $this->get(route('roles.edit'))
        ->assertRedirect(route('login'));
});

it('requires manage roles permission to access', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('roles.edit'))
        ->assertForbidden();
});

it('allows users with manage roles permission to access', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $this->actingAs($user)
        ->get(route('roles.edit'))
        ->assertOk();
});

it('displays internal users', function () {
    $owner = User::factory()->internal()->withFullAccess()->create(['name' => 'Business Owner']);
    $owner->assignRole('owner');

    $teamMember = User::factory()->internal()->withFullAccess()->create(['name' => 'Team Member']);

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->assertSee('Business Owner')
        ->assertSee('Team Member');
});

it('does not display non-internal users', function () {
    $owner = User::factory()->internal()->withFullAccess()->create(['name' => 'Business Owner']);
    $owner->assignRole('owner');

    $customer = User::factory()->customer()->create(['name' => 'John Customer']);

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->assertSee('Business Owner')
        ->assertDontSee('John Customer');
});

it('displays available roles with descriptions', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->assertSee('Available Roles')
        ->assertSee('Owner')
        ->assertSee('Admin')
        ->assertSee('Manager')
        ->assertSee('Estimator')
        ->assertSee('Crew lead');
});

it('shows current role for users', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    $admin = User::factory()->internal()->withFullAccess()->create(['name' => 'Admin User']);
    $admin->assignRole('admin');

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->assertSee('Admin User')
        ->assertSee('Admin');
});

it('can start editing a user role', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    $teamMember = User::factory()->internal()->withFullAccess()->create(['name' => 'Team Member']);

    $component = Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->call('startEditing', $teamMember->id);

    expect($component->get('editingUserId'))->toBe($teamMember->id);
});

it('prevents editing own role', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->call('startEditing', $owner->id)
        ->assertSee('You cannot modify your own role');
});

it('can assign a role to a user', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    $teamMember = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->call('startEditing', $teamMember->id)
        ->set('selectedRole', 'estimator')
        ->call('saveRole');

    $teamMember->refresh();
    expect($teamMember->hasRole('estimator'))->toBeTrue();
});

it('can change a user role', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    $teamMember = User::factory()->internal()->withFullAccess()->create();
    $teamMember->assignRole('estimator');

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->call('startEditing', $teamMember->id)
        ->set('selectedRole', 'admin')
        ->call('saveRole');

    $teamMember->refresh();
    expect($teamMember->hasRole('admin'))->toBeTrue();
    expect($teamMember->hasRole('estimator'))->toBeFalse();
});

it('can remove a role from a user', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    $teamMember = User::factory()->internal()->withFullAccess()->create();
    $teamMember->assignRole('estimator');

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->call('removeRole', $teamMember->id);

    $teamMember->refresh();
    expect($teamMember->roles)->toHaveCount(0);
});

it('prevents removing own role', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->call('removeRole', $owner->id)
        ->assertSee('You cannot remove your own role');

    $owner->refresh();
    expect($owner->hasRole('owner'))->toBeTrue();
});

it('can cancel editing', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    $teamMember = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->call('startEditing', $teamMember->id);

    expect($component->get('editingUserId'))->toBe($teamMember->id);

    $component->call('cancelEditing');

    expect($component->get('editingUserId'))->toBeNull();
});

it('shows roles link in settings nav for users with permission', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('company.edit'))
        ->assertSee('Roles');
});

it('hides roles link in settings nav for users without permission', function () {
    $user = User::factory()->internal()->create();
    // Give settings.company so they can access the page, but NOT manage roles
    $user->givePermissionTo('settings.company');

    $this->actingAs($user)
        ->get(route('company.edit'))
        ->assertDontSee('Roles');
});

it('shows link to invitations page', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $owner->assignRole('owner');

    Livewire::actingAs($owner)
        ->test('pages::settings.roles')
        ->assertSee('Manage Invitations');
});
