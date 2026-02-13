<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();
});

it('can assign a service category to an area', function () {
    // Use the painting category seeded by migration
    $category = ServiceCategory::where('slug', 'painting')->first();

    if (! $category) {
        $category = ServiceCategory::factory()->create(['name' => 'Test Category']);
    }

    $area = EstimateArea::factory()->create([
        'service_category_id' => $category->id,
    ]);

    expect($area->service_category_id)->toBe($category->id);
    expect($area->serviceCategory->name)->toBe($category->name);
});

it('defaults service_category_id to null', function () {
    $area = EstimateArea::factory()->create();
    expect($area->service_category_id)->toBeNull();
});

it('nullifies service_category_id on category delete', function () {
    $category = ServiceCategory::factory()->create(['name' => 'Deletable Category']);
    $area = EstimateArea::factory()->create([
        'service_category_id' => $category->id,
    ]);

    $category->delete();
    $area->refresh();

    expect($area->service_category_id)->toBeNull();
});

it('persists service category through save/load cycle', function () {
    $category = ServiceCategory::factory()->create(['name' => 'Floor Coating']);
    $estimate = Estimate::factory()->create();

    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'service_category_id' => $category->id,
    ]);

    // Reload from DB
    $loadedArea = EstimateArea::find($area->id);

    expect($loadedArea->service_category_id)->toBe($category->id);
    expect($loadedArea->serviceCategory->name)->toBe('Floor Coating');
});

it('uses factory withServiceCategory state correctly', function () {
    // Use the existing painting category from migration
    $category = ServiceCategory::where('slug', 'painting')->first();

    if (! $category) {
        $category = ServiceCategory::factory()->create(['name' => 'Painting', 'slug' => 'painting']);
    }

    $area = EstimateArea::factory()->withServiceCategory($category)->create();

    expect($area->service_category_id)->toBe($category->id);
    expect($area->serviceCategory->slug)->toBe('painting');
});

it('creates a service category via factory when none provided', function () {
    $area = EstimateArea::factory()->withServiceCategory()->create();

    expect($area->service_category_id)->not->toBeNull();
    expect($area->serviceCategory)->not->toBeNull();
});

it('can create service category inline from area builder', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.area-builder')
        ->set('newCategoryName', 'Window Washing')
        ->call('createServiceCategory')
        ->assertSet('showNewCategoryForm', false)
        ->assertSet('newCategoryName', '');

    $category = ServiceCategory::where('name', 'Window Washing')->first();
    expect($category)->not->toBeNull();
    expect($category->slug)->toBe('window-washing');
    expect($category->is_active)->toBeTrue();
});

it('sets serviceCategoryId after inline creation', function () {
    $component = Livewire::actingAs($this->user)
        ->test('estimates.area-builder')
        ->set('newCategoryName', 'Pressure Washing')
        ->call('createServiceCategory');

    $category = ServiceCategory::where('name', 'Pressure Washing')->first();
    $component->assertSet('serviceCategoryId', $category->id);
});

it('validates required name for inline category creation', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.area-builder')
        ->set('newCategoryName', '')
        ->call('createServiceCategory')
        ->assertHasErrors(['newCategoryName' => 'required']);
});

it('can have multiple areas with different service categories', function () {
    // Use existing painting category from migration
    $painting = ServiceCategory::where('slug', 'painting')->first();

    if (! $painting) {
        $painting = ServiceCategory::factory()->create(['name' => 'Painting', 'slug' => 'painting']);
    }

    $cleaning = ServiceCategory::factory()->create(['name' => 'Cleaning', 'slug' => 'cleaning-test']);
    $estimate = Estimate::factory()->create();

    $area1 = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'service_category_id' => $painting->id,
    ]);

    $area2 = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'service_category_id' => $cleaning->id,
    ]);

    expect($area1->serviceCategory->slug)->toBe('painting');
    expect($area2->serviceCategory->slug)->toBe('cleaning-test');
});

it('allows areas without a service category', function () {
    $estimate = Estimate::factory()->create();

    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'service_category_id' => null,
    ]);

    expect($area->service_category_id)->toBeNull();
    expect($area->serviceCategory)->toBeNull();
});
