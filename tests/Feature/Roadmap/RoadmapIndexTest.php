<?php

use App\Models\RoadmapCategory;
use App\Models\RoadmapItem;
use App\Models\RoadmapPhase;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Create permissions if they don't exist
    Permission::firstOrCreate(['name' => 'view roadmap', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'edit roadmap', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    // Create owner role with all permissions
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());

    // Create estimator role with limited permissions
    $estimatorRole = Role::firstOrCreate(['name' => 'estimator', 'guard_name' => 'web']);
    $estimatorRole->syncPermissions(['view roadmap']);
});

it('requires authentication to view roadmap', function () {
    $this->get(route('roadmap.index'))
        ->assertRedirect(route('login'));
});

it('requires internal user type to view roadmap', function () {
    $customer = User::factory()->customer()->create();
    $customer->givePermissionTo('view roadmap');

    // Customer type mismatch results in redirect to customer dashboard
    $this->actingAs($customer)
        ->get(route('roadmap.index'))
        ->assertRedirect();
});

it('legacy roadmap URL redirects to business plan', function () {
    $user = User::factory()->internal()->create();

    // Legacy /roadmap route redirects to business plan page (permission check is on the target)
    $this->actingAs($user)
        ->get(route('roadmap.index'))
        ->assertRedirect(route('business-plan.index', ['tab' => 'roadmap']));
});

it('allows users with view roadmap permission to access via business plan', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    // Legacy /roadmap redirects to business plan
    $this->actingAs($user)
        ->get(route('roadmap.index'))
        ->assertRedirect(route('business-plan.index', ['tab' => 'roadmap']));
});

it('displays phases from the database', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create([
        'name' => 'Test Phase Alpha',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->assertSee('Test Phase Alpha');
});

it('displays categories within expanded phases', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create([
        'name' => 'Test Phase',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $category = RoadmapCategory::factory()->create([
        'roadmap_phase_id' => $phase->id,
        'name' => 'Test Category Beta',
    ]);

    // The first active phase gets auto-expanded on mount
    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->assertSee('Test Category Beta');
});

it('displays items within expanded phases', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create([
        'name' => 'Test Phase',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $category = RoadmapCategory::factory()->create([
        'roadmap_phase_id' => $phase->id,
        'name' => 'Test Category',
    ]);
    $item = RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'title' => 'Complete Business Plan',
    ]);

    // The first active phase gets auto-expanded on mount
    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->assertSee('Complete Business Plan');
});

it('shows overall progress stats', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create();
    $category = RoadmapCategory::factory()->create(['roadmap_phase_id' => $phase->id]);

    // Create 2 done, 1 in progress, 1 not started
    RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_DONE,
    ]);
    RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_DONE,
    ]);
    RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_IN_PROGRESS,
    ]);
    RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_NOT_STARTED,
    ]);

    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->assertSee('Overall Progress')
        ->assertSee('Completed')
        ->assertSee('In Progress')
        ->assertSee('Not Started');
});

it('allows users with edit permission to cycle item status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create();
    $category = RoadmapCategory::factory()->create(['roadmap_phase_id' => $phase->id]);
    $item = RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_NOT_STARTED,
    ]);

    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->call('cycleStatus', $item->id);

    $item->refresh();
    expect($item->status)->toBe(RoadmapItem::STATUS_IN_PROGRESS);
});

it('cycles status from in_progress to done', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create();
    $category = RoadmapCategory::factory()->create(['roadmap_phase_id' => $phase->id]);
    $item = RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_IN_PROGRESS,
    ]);

    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->call('cycleStatus', $item->id);

    $item->refresh();
    expect($item->status)->toBe(RoadmapItem::STATUS_DONE);
    expect($item->completed_at)->not->toBeNull();
});

it('cycles status from done back to not_started', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create();
    $category = RoadmapCategory::factory()->create(['roadmap_phase_id' => $phase->id]);
    $item = RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_DONE,
        'completed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->call('cycleStatus', $item->id);

    $item->refresh();
    expect($item->status)->toBe(RoadmapItem::STATUS_NOT_STARTED);
    expect($item->completed_at)->toBeNull();
});

it('prevents users without edit permission from changing status', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('estimator'); // Only has view permission, not edit

    $phase = RoadmapPhase::factory()->create();
    $category = RoadmapCategory::factory()->create(['roadmap_phase_id' => $phase->id]);
    $item = RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_NOT_STARTED,
    ]);

    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->call('cycleStatus', $item->id);

    $item->refresh();
    expect($item->status)->toBe(RoadmapItem::STATUS_NOT_STARTED);
});

it('updates phase status when items change', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create([
        'status' => RoadmapPhase::STATUS_NOT_STARTED,
    ]);
    $category = RoadmapCategory::factory()->create(['roadmap_phase_id' => $phase->id]);
    $item = RoadmapItem::factory()->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_NOT_STARTED,
    ]);

    Livewire::actingAs($user)
        ->test('roadmap.index')
        ->call('cycleStatus', $item->id);

    $phase->refresh();
    expect($phase->status)->toBe(RoadmapPhase::STATUS_IN_PROGRESS);
});

it('can toggle phase expansion', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    // Create a phase that won't be auto-expanded (not first, not active)
    $phase = RoadmapPhase::factory()->create([
        'is_active' => false,
        'sort_order' => 999,
    ]);

    $component = Livewire::actingAs($user)
        ->test('roadmap.index');

    // Initially not expanded
    $expandedPhases = $component->get('expandedPhases');
    expect($expandedPhases)->not->toHaveKey($phase->id);

    // Toggle to expand
    $component->call('togglePhase', $phase->id);
    $expandedPhases = $component->get('expandedPhases');
    expect($expandedPhases)->toHaveKey($phase->id);
    expect($expandedPhases[$phase->id])->toBeTrue();

    // Toggle to collapse
    $component->call('togglePhase', $phase->id);
    $expandedPhases = $component->get('expandedPhases');
    expect($expandedPhases)->not->toHaveKey($phase->id);
});

it('calculates progress percentage correctly', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $phase = RoadmapPhase::factory()->create();
    $category = RoadmapCategory::factory()->create(['roadmap_phase_id' => $phase->id]);

    // Create 2 done out of 4 = 50%
    RoadmapItem::factory()->count(2)->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_DONE,
    ]);
    RoadmapItem::factory()->count(2)->create([
        'roadmap_category_id' => $category->id,
        'status' => RoadmapItem::STATUS_NOT_STARTED,
    ]);

    $component = Livewire::actingAs($user)
        ->test('roadmap.index');

    expect($component->get('overallProgress'))->toBe(50.0);
});

it('shows business plan link in sidebar for users with permission', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Business Plan');
});

it('hides business plan link in sidebar for users without permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertDontSee('Business Plan');
});
