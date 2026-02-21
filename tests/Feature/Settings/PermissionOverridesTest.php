<?php

use App\Models\PermissionScope;
use App\Models\User;
use Livewire\Livewire;

it('renders permission overrides for a user', function () {
    $admin = User::factory()->internal()->withRole('owner')->create();
    $targetUser = User::factory()->internal()->withRole('manager')->create();

    Livewire::actingAs($admin)
        ->test('settings.permission-overrides', ['userId' => $targetUser->id])
        ->assertSee($targetUser->name)
        ->assertSee('Permission Overrides');
});

it('grants a scoped permission to a user', function () {
    $admin = User::factory()->internal()->withRole('owner')->create();
    $targetUser = User::factory()->internal()->withRole('manager')->create();

    Livewire::actingAs($admin)
        ->test('settings.permission-overrides', ['userId' => $targetUser->id])
        ->set('newPermission', 'territories.manage_regions')
        ->set('newScopeType', 'grant')
        ->call('addOverride')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('permission_scopes', [
        'user_id' => $targetUser->id,
        'permission' => 'territories.manage_regions',
        'scope_type' => 'grant',
        'granted_by' => $admin->id,
    ]);
});

it('revokes a scoped permission', function () {
    $admin = User::factory()->internal()->withRole('owner')->create();
    $targetUser = User::factory()->internal()->withRole('manager')->create();

    $scope = PermissionScope::create([
        'user_id' => $targetUser->id,
        'permission' => 'territories.manage_regions',
        'scope_type' => 'grant',
        'granted_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('settings.permission-overrides', ['userId' => $targetUser->id])
        ->call('removeOverride', $scope->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('permission_scopes', ['id' => $scope->id]);
});
