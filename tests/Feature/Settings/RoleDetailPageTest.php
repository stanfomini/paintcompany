<?php

use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('renders the role detail page', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'admin'])
        ->assertStatus(200)
        ->assertSee('Admin')
        ->assertSee('People')
        ->assertSee('Deals');
});

it('returns 404 for invalid role name', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'nonexistent_role'])
        ->assertNotFound();
});

it('requires team.manage_roles permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.role-detail', ['roleName' => 'admin'])
        ->assertForbidden();
});

it('shows full access message for owner role', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'owner'])
        ->assertSee('Full Access')
        ->assertSee('Owner role has all permissions');
});

it('toggles a permission on for a role', function () {
    $adminRole = Role::findByName('admin', 'web');
    $adminRole->revokePermissionTo('estimates.delete');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($adminRole->fresh()->hasPermissionTo('estimates.delete'))->toBeFalse();

    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'admin'])
        ->call('togglePermission', 'estimates.delete');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Role::findByName('admin', 'web')->hasPermissionTo('estimates.delete'))->toBeTrue();
});

it('toggles a permission off for a role', function () {
    $adminRole = Role::findByName('admin', 'web');
    expect($adminRole->hasPermissionTo('people.view'))->toBeTrue();

    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'admin'])
        ->call('togglePermission', 'people.view');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Role::findByName('admin', 'web')->hasPermissionTo('people.view'))->toBeFalse();
});

it('prevents toggling owner role permissions', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'owner'])
        ->call('togglePermission', 'people.view');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Role::findByName('owner', 'web')->hasPermissionTo('people.view'))->toBeTrue();
});

it('toggles all group permissions on', function () {
    $estimatorRole = Role::findByName('estimator', 'web');
    expect($estimatorRole->hasPermissionTo('people.delete'))->toBeFalse();

    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'estimator'])
        ->call('toggleGroupPermissions', 'People');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $estimatorRole = Role::findByName('estimator', 'web');
    expect($estimatorRole->hasPermissionTo('people.view'))->toBeTrue();
    expect($estimatorRole->hasPermissionTo('people.create'))->toBeTrue();
    expect($estimatorRole->hasPermissionTo('people.edit'))->toBeTrue();
    expect($estimatorRole->hasPermissionTo('people.delete'))->toBeTrue();
    expect($estimatorRole->hasPermissionTo('people.view_all'))->toBeTrue();
});

it('filters permissions by search', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'admin'])
        ->set('permissionSearch', 'chat')
        ->assertSee('Communication')
        ->assertDontSee('Roadmap');
});

it('shows back link to roles list', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'admin'])
        ->assertSee('Back to Roles');
});

it('renders the role detail page via route', function () {
    $this->actingAs($this->user)
        ->get(route('roles.detail', 'admin'))
        ->assertOk();
});

it('shows the granted permission count', function () {
    $adminRole = Role::findByName('admin', 'web');
    $grantedCount = $adminRole->permissions->count();

    Livewire::actingAs($this->user)
        ->test('pages::settings.role-detail', ['roleName' => 'admin'])
        ->assertSee((string) $grantedCount);
});
