<?php

use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('renders the roles card grid', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.roles')
        ->assertSee('Roles')
        ->assertSee('Owner')
        ->assertSee('Admin')
        ->assertSee('Manager');
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
