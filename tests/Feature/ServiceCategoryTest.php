<?php

use App\Models\CrewProfile;
use App\Models\JobPrepTask;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;

it('creates a service category with all fields', function () {
    $category = ServiceCategory::factory()->create([
        'name' => 'Pressure Washing',
        'slug' => 'pressure-washing',
        'description' => 'Exterior pressure washing services',
        'icon' => 'water-drop',
        'color' => 'blue',
        'is_active' => true,
        'sort_order' => 5,
    ]);

    expect($category)->toBeInstanceOf(ServiceCategory::class)
        ->and($category->name)->toBe('Pressure Washing')
        ->and($category->slug)->toBe('pressure-washing')
        ->and($category->description)->toBe('Exterior pressure washing services')
        ->and($category->icon)->toBe('water-drop')
        ->and($category->color)->toBe('blue')
        ->and($category->is_active)->toBeTrue()
        ->and($category->sort_order)->toBe(5);
});

it('auto-generates slug from name on creating', function () {
    $category = ServiceCategory::factory()->create([
        'name' => 'Deck Staining',
        'slug' => null,
    ]);

    expect($category->slug)->toBe('deck-staining');
});

it('auto-generates slug for multi-word names', function () {
    $category = ServiceCategory::factory()->create([
        'name' => 'Interior Cabinet Refinishing',
        'slug' => null,
    ]);

    expect($category->slug)->toBe('interior-cabinet-refinishing');
});

it('does not overwrite an explicitly provided slug', function () {
    $category = ServiceCategory::factory()->create([
        'name' => 'Some Service',
        'slug' => 'custom-slug',
    ]);

    expect($category->slug)->toBe('custom-slug');
});

it('enforces unique slug constraint', function () {
    ServiceCategory::factory()->create(['slug' => 'unique-test-slug']);

    ServiceCategory::factory()->create(['slug' => 'unique-test-slug']);
})->throws(\Illuminate\Database\QueryException::class);

it('filters active categories with active scope', function () {
    // Count any pre-existing active categories from migrations
    $preExistingCount = ServiceCategory::active()->count();

    ServiceCategory::factory()->create(['is_active' => true, 'name' => 'Active One']);
    ServiceCategory::factory()->create(['is_active' => true, 'name' => 'Active Two']);
    ServiceCategory::factory()->inactive()->create(['name' => 'Inactive One']);

    $active = ServiceCategory::active()->get();

    expect($active)->toHaveCount($preExistingCount + 2)
        ->and($active->pluck('name')->toArray())->not->toContain('Inactive One');
});

it('orders categories by sort_order then name with ordered scope', function () {
    // Remove any pre-existing categories so ordering is deterministic
    ServiceCategory::query()->delete();

    ServiceCategory::factory()->create(['sort_order' => 2, 'name' => 'Cleaning Service']);
    ServiceCategory::factory()->create(['sort_order' => 1, 'name' => 'Painting Service']);
    ServiceCategory::factory()->create(['sort_order' => 1, 'name' => 'Drywall Service']);

    $ordered = ServiceCategory::ordered()->get();

    expect($ordered->first()->name)->toBe('Drywall Service')
        ->and($ordered->get(1)->name)->toBe('Painting Service')
        ->and($ordered->last()->name)->toBe('Cleaning Service');
});

it('identifies painting category via isPainting', function () {
    $painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'is_active' => true, 'sort_order' => 1]
    );
    $other = ServiceCategory::factory()->create(['slug' => 'drywall-repair']);

    expect($painting->isPainting())->toBeTrue()
        ->and($other->isPainting())->toBeFalse();
});

it('identifies cleaning category via isCleaning', function () {
    $cleaning = ServiceCategory::factory()->cleaning()->create();
    $other = ServiceCategory::factory()->create(['slug' => 'carpentry']);

    expect($cleaning->isCleaning())->toBeTrue()
        ->and($other->isCleaning())->toBeFalse();
});

it('has crewProfiles relationship', function () {
    $category = ServiceCategory::factory()->create();

    // Crew users auto-create CrewProfile via model event
    $crew1 = User::factory()->crew()->create();
    $crew1->crewProfile->update(['service_category_id' => $category->id]);

    $crew2 = User::factory()->crew()->create();
    $crew2->crewProfile->update(['service_category_id' => $category->id]);

    expect($category->crewProfiles)->toHaveCount(2)
        ->and($category->crewProfiles->first())->toBeInstanceOf(CrewProfile::class);
});

it('has serviceRequirements relationship', function () {
    $category = ServiceCategory::factory()->create();

    JobServiceRequirement::factory()->count(3)->create([
        'service_category_id' => $category->id,
    ]);

    expect($category->serviceRequirements)->toHaveCount(3)
        ->and($category->serviceRequirements->first())->toBeInstanceOf(JobServiceRequirement::class);
});

it('has prepTasks relationship', function () {
    $category = ServiceCategory::factory()->create();

    JobPrepTask::factory()->count(2)->create([
        'service_category_id' => $category->id,
    ]);

    expect($category->prepTasks)->toHaveCount(2)
        ->and($category->prepTasks->first())->toBeInstanceOf(JobPrepTask::class);
});

it('uses painting factory state correctly', function () {
    // Delete pre-existing painting category from backfill migration
    ServiceCategory::where('slug', 'painting')->delete();

    $category = ServiceCategory::factory()->painting()->create();

    expect($category->name)->toBe('Painting')
        ->and($category->slug)->toBe('painting')
        ->and($category->icon)->toBe('paint-brush')
        ->and($category->color)->toBe('blue')
        ->and($category->sort_order)->toBe(1)
        ->and($category->is_active)->toBeTrue();
});

it('uses cleaning factory state correctly', function () {
    $category = ServiceCategory::factory()->cleaning()->create();

    expect($category->name)->toBe('Cleaning')
        ->and($category->slug)->toBe('cleaning')
        ->and($category->icon)->toBe('sparkles')
        ->and($category->color)->toBe('green')
        ->and($category->sort_order)->toBe(2)
        ->and($category->is_active)->toBeTrue();
});

it('uses inactive factory state correctly', function () {
    $category = ServiceCategory::factory()->inactive()->create();

    expect($category->is_active)->toBeFalse();
});

it('casts is_active to boolean and sort_order to integer', function () {
    $category = ServiceCategory::factory()->create([
        'is_active' => 1,
        'sort_order' => '3',
    ]);

    expect($category->is_active)->toBeBool()->toBeTrue()
        ->and($category->sort_order)->toBeInt()->toBe(3);
});

it('chains active and ordered scopes together', function () {
    // Remove any pre-existing categories so counting is deterministic
    ServiceCategory::query()->delete();

    ServiceCategory::factory()->create(['is_active' => true, 'sort_order' => 2, 'name' => 'Cleaning Service']);
    ServiceCategory::factory()->create(['is_active' => true, 'sort_order' => 1, 'name' => 'Painting Service']);
    ServiceCategory::factory()->inactive()->create(['sort_order' => 0, 'name' => 'Inactive First']);

    $results = ServiceCategory::active()->ordered()->get();

    expect($results)->toHaveCount(2)
        ->and($results->first()->name)->toBe('Painting Service')
        ->and($results->last()->name)->toBe('Cleaning Service');
});

it('returns empty relationships when no related records exist', function () {
    $category = ServiceCategory::factory()->create();

    expect($category->crewProfiles)->toHaveCount(0)
        ->and($category->serviceRequirements)->toHaveCount(0)
        ->and($category->prepTasks)->toHaveCount(0);
});
