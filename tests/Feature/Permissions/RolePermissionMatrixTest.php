<?php

use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('renders the permission matrix tab', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->set('activeTab', 'matrix')
        ->assertSee('Permission Matrix')
        ->assertSee('Toggle permissions for each role');
});

it('toggles a permission on for a role', function () {
    $adminRole = Role::findByName('admin', 'web');
    $adminRole->revokePermissionTo('estimates.delete');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($adminRole->fresh()->hasPermissionTo('estimates.delete'))->toBeFalse();

    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->call('togglePermission', 'admin', 'estimates.delete');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('admin', 'web')->hasPermissionTo('estimates.delete'))->toBeTrue();
});

it('toggles a permission off for a role', function () {
    $adminRole = Role::findByName('admin', 'web');

    expect($adminRole->hasPermissionTo('people.view'))->toBeTrue();

    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->call('togglePermission', 'admin', 'people.view');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('admin', 'web')->hasPermissionTo('people.view'))->toBeFalse();
});

it('protects owner role from permission toggling', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->call('togglePermission', 'owner', 'people.view');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $ownerRole = Role::findByName('owner', 'web');

    expect($ownerRole->hasPermissionTo('people.view'))->toBeTrue();
    expect($ownerRole->permissions->count())->toBe(Permission::count());
});

it('creates a custom role', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->set('newRoleName', 'Sales Rep')
        ->call('createRole')
        ->assertHasNoErrors();

    expect(Role::where('name', 'sales_rep')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('prevents creating a duplicate role', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->set('newRoleName', 'admin')
        ->call('createRole')
        ->assertHasErrors('newRoleName');
});

it('deletes a custom role', function () {
    $customRole = Role::create(['name' => 'sales_rep', 'guard_name' => 'web']);

    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->call('confirmDeleteRole', $customRole->id)
        ->call('deleteRole');

    expect(Role::where('name', 'sales_rep')->exists())->toBeFalse();
});

it('prevents deleting default roles', function (string $roleName) {
    $role = Role::findByName($roleName, 'web');

    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->call('confirmDeleteRole', $role->id)
        ->call('deleteRole');

    expect(Role::where('name', $roleName)->exists())->toBeTrue();
})->with([
    'owner',
    'admin',
    'manager',
    'estimator',
    'crew_lead',
]);

it('validates custom role name is required', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->set('newRoleName', '')
        ->call('createRole')
        ->assertHasErrors('newRoleName');
});

it('validates custom role name allows only valid characters', function (string $invalidName) {
    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->set('newRoleName', $invalidName)
        ->call('createRole')
        ->assertHasErrors('newRoleName');
})->with([
    'special chars' => 'role@name!',
    'html tags' => '<script>alert</script>',
    'dots' => 'role.name',
]);
