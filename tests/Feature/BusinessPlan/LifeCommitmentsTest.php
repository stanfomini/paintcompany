<?php

use App\Models\CompanySetting;
use App\Models\LifeCommitment;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('renders the life commitments component', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertOk()
        ->assertSee('Life Commitments');
});

it('shows empty state when no commitments exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('No commitments yet');
});

it('can create a life commitment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->assertSet('showModal', true)
        ->set('area', 'Physical Health')
        ->set('commitment', 'Exercise 4x per week')
        ->set('action_type', 'weekly')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertSee('Physical Health')
        ->assertSee('Exercise 4x per week');

    $this->assertDatabaseHas('life_commitments', [
        'area' => 'Physical Health',
        'commitment' => 'Exercise 4x per week',
        'action_type' => 'weekly',
    ]);
});

it('can edit a life commitment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'area' => 'Family',
        'commitment' => 'Weekly date night',
        'action_type' => 'weekly',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('Family')
        ->call('openEditModal', $commitment->id)
        ->assertSet('showModal', true)
        ->assertSet('area', 'Family')
        ->assertSet('commitment', 'Weekly date night')
        ->set('area', 'Family & Relationships')
        ->set('commitment', 'Bi-weekly date night')
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('life_commitments', [
        'id' => $commitment->id,
        'area' => 'Family & Relationships',
        'commitment' => 'Bi-weekly date night',
    ]);
});

it('can delete a life commitment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $commitment = LifeCommitment::factory()->create([
        'area' => 'Business',
        'commitment' => 'Review KPIs monthly',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSee('Business')
        ->call('delete', $commitment->id)
        ->assertDontSee('Review KPIs monthly');

    $this->assertDatabaseMissing('life_commitments', [
        'id' => $commitment->id,
    ]);
});

it('validates required fields', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', '')
        ->set('commitment', '')
        ->call('save')
        ->assertHasErrors(['area', 'commitment']);
});

it('defaults action type to ongoing', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSet('action_type', 'ongoing');
});

it('orders commitments by sort_order', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $companySettingId = CompanySetting::getInstance()->id;

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Second',
        'commitment' => 'Second commitment',
        'sort_order' => 2,
    ]);

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'First',
        'commitment' => 'First commitment',
        'sort_order' => 1,
    ]);

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Third',
        'commitment' => 'Third commitment',
        'sort_order' => 3,
    ]);

    $ordered = LifeCommitment::query()
        ->where('company_setting_id', $companySettingId)
        ->ordered()
        ->pluck('area')
        ->toArray();

    expect($ordered)->toBe(['First', 'Second', 'Third']);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->assertSeeInOrder(['First', 'Second', 'Third']);
});

it('requires business_plan.manage permission to create', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->assertForbidden();
});

it('requires business_plan.manage permission to edit', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $commitment = LifeCommitment::factory()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openEditModal', $commitment->id)
        ->assertForbidden();
});

it('requires business_plan.manage permission to delete', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $commitment = LifeCommitment::factory()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('delete', $commitment->id)
        ->assertForbidden();
});

it('can move commitments up and down', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $companySettingId = CompanySetting::getInstance()->id;

    $first = LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'First',
        'sort_order' => 0,
    ]);

    $second = LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'area' => 'Second',
        'sort_order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('moveDown', $first->id);

    expect($first->fresh()->sort_order)->toBe(1);
    expect($second->fresh()->sort_order)->toBe(0);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('moveUp', $first->id);

    expect($first->fresh()->sort_order)->toBe(0);
    expect($second->fresh()->sort_order)->toBe(1);
});

it('validates action type against allowed values', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'Test')
        ->set('commitment', 'Test commitment')
        ->set('action_type', 'invalid_type')
        ->call('save')
        ->assertHasErrors(['action_type']);
});

it('assigns incremental sort_order to new commitments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $companySettingId = CompanySetting::getInstance()->id;

    LifeCommitment::factory()->create([
        'company_setting_id' => $companySettingId,
        'sort_order' => 5,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.life-commitments')
        ->call('openCreateModal')
        ->set('area', 'New Area')
        ->set('commitment', 'New commitment')
        ->call('save');

    $latest = LifeCommitment::where('area', 'New Area')->first();
    expect($latest->sort_order)->toBe(6);
});
