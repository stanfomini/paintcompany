<?php

use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'roadmap.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'kpis.view', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('requires authentication to view business plan', function () {
    $this->get(route('business-plan.index'))
        ->assertRedirect(route('login'));
});

it('requires business_plan.view permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('business-plan.index'))
        ->assertForbidden();
});

it('allows users with business_plan.view permission', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get(route('business-plan.index'))
        ->assertOk()
        ->assertSee('Master Business Plan');
});

it('renders with default roadmap tab', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSet('activeTab', 'roadmap')
        ->assertSee('Roadmap');
});

it('renders all tab buttons', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSee('Roadmap')
        ->assertSee('Life Commitments')
        ->assertSee('Sales Plan')
        ->assertSee('Production Plan')
        ->assertSee('Job Cost Cards')
        ->assertSee('Budget')
        ->assertSee('Overhead')
        ->assertSee('Marketing');
});

it('switches tabs via setTab', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSet('activeTab', 'roadmap')
        ->call('setTab', 'budget')
        ->assertSet('activeTab', 'budget')
        ->call('setTab', 'life-commitments')
        ->assertSet('activeTab', 'life-commitments');
});

it('ignores invalid tab names', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->call('setTab', 'invalid-tab')
        ->assertSet('activeTab', 'roadmap');
});

it('reads tab from URL query string', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get(route('business-plan.index', ['tab' => 'budget']))
        ->assertOk()
        ->assertSee('Budget');
});

it('redirects from legacy roadmap URL', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get('/roadmap')
        ->assertRedirect(route('business-plan.index', ['tab' => 'roadmap']));
});

it('redirects from legacy kpis/sales URL', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get('/kpis/sales')
        ->assertRedirect(route('business-plan.index', ['tab' => 'sales-plan']));
});

it('redirects from legacy kpis/production URL', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get('/kpis/production')
        ->assertRedirect(route('business-plan.index', ['tab' => 'production-plan']));
});
