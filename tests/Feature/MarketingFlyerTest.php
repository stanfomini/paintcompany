<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'settings.company', 'guard_name' => 'web']);
});

it('downloads the marketing flyer PDF for users with settings.company permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.company');

    $this->actingAs($user)
        ->get(route('marketing-flyer.show'))
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

it('blocks users without settings.company permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('marketing-flyer.show'))
        ->assertForbidden();
});

it('blocks unauthenticated users', function () {
    $this->get(route('marketing-flyer.show'))
        ->assertRedirect();
});
