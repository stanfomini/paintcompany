<?php

use App\Models\EstimateTemplateCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('EstimateTemplateCategory Model', function () {
    it('can be created with factory', function () {
        $category = EstimateTemplateCategory::factory()->create();

        expect($category)->toBeInstanceOf(EstimateTemplateCategory::class)
            ->and($category->id)->toBeGreaterThan(0)
            ->and($category->name)->not->toBeEmpty()
            ->and($category->slug)->not->toBeEmpty();
    });

    it('has correct fillable attributes', function () {
        $category = EstimateTemplateCategory::factory()->create([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'description' => 'Test description',
            'icon' => 'home',
            'type' => EstimateTemplateCategory::TYPE_ROOM,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        expect($category->name)->toBe('Test Category')
            ->and($category->slug)->toBe('test-category')
            ->and($category->description)->toBe('Test description')
            ->and($category->icon)->toBe('home')
            ->and($category->type)->toBe('room')
            ->and($category->is_active)->toBeTrue()
            ->and($category->sort_order)->toBe(10);
    });

    it('casts is_active to boolean', function () {
        $category = EstimateTemplateCategory::factory()->create(['is_active' => 1]);

        expect($category->is_active)->toBeBool()
            ->and($category->is_active)->toBeTrue();
    });

    it('casts sort_order to integer', function () {
        $category = EstimateTemplateCategory::factory()->create(['sort_order' => '5']);

        expect($category->sort_order)->toBeInt()
            ->and($category->sort_order)->toBe(5);
    });

    it('has type constants', function () {
        expect(EstimateTemplateCategory::TYPE_ROOM)->toBe('room')
            ->and(EstimateTemplateCategory::TYPE_SURFACE)->toBe('surface')
            ->and(EstimateTemplateCategory::TYPE_ITEM)->toBe('item')
            ->and(EstimateTemplateCategory::TYPE_ADDON)->toBe('addon');
    });

    it('has types array with labels', function () {
        expect(EstimateTemplateCategory::TYPES)->toBe([
            'room' => 'Room',
            'surface' => 'Surface',
            'item' => 'Item',
            'addon' => 'Add-on',
        ]);
    });

    it('returns type label attribute', function () {
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ADDON]);

        expect($category->type_label)->toBe('Add-on');
    });
});

describe('EstimateTemplateCategory Slug Auto-generation', function () {
    it('auto-generates slug from name when creating without slug', function () {
        $category = EstimateTemplateCategory::factory()->create([
            'name' => 'My Test Category',
            'slug' => null,
        ]);

        expect($category->slug)->toBe('my-test-category');
    });

    it('uses provided slug when explicitly set', function () {
        $category = EstimateTemplateCategory::factory()->create([
            'name' => 'My Test Category',
            'slug' => 'custom-slug',
        ]);

        expect($category->slug)->toBe('custom-slug');
    });

    it('updates slug when name changes', function () {
        $category = EstimateTemplateCategory::factory()->create([
            'name' => 'Original Name',
            'slug' => 'original-name',
        ]);

        $category->update(['name' => 'Updated Name']);

        expect($category->fresh()->slug)->toBe('updated-name');
    });

    it('does not update slug when explicitly setting both name and slug', function () {
        $category = EstimateTemplateCategory::factory()->create([
            'name' => 'Original Name',
            'slug' => 'original-slug',
        ]);

        $category->update([
            'name' => 'Updated Name',
            'slug' => 'my-custom-slug',
        ]);

        expect($category->fresh()->slug)->toBe('my-custom-slug');
    });
});

describe('EstimateTemplateCategory Scopes', function () {
    it('filters active categories with active scope', function () {
        EstimateTemplateCategory::factory()->count(3)->create(['is_active' => true]);
        EstimateTemplateCategory::factory()->count(2)->create(['is_active' => false]);

        $activeCategories = EstimateTemplateCategory::active()->get();

        expect($activeCategories)->toHaveCount(3)
            ->and($activeCategories->every(fn ($cat) => $cat->is_active))->toBeTrue();
    });

    it('filters categories by type with byType scope', function () {
        EstimateTemplateCategory::factory()->room()->count(2)->create();
        EstimateTemplateCategory::factory()->surface()->count(3)->create();
        EstimateTemplateCategory::factory()->item()->create();

        $roomCategories = EstimateTemplateCategory::byType(EstimateTemplateCategory::TYPE_ROOM)->get();
        $surfaceCategories = EstimateTemplateCategory::byType(EstimateTemplateCategory::TYPE_SURFACE)->get();

        expect($roomCategories)->toHaveCount(2)
            ->and($surfaceCategories)->toHaveCount(3)
            ->and($roomCategories->every(fn ($cat) => $cat->type === 'room'))->toBeTrue()
            ->and($surfaceCategories->every(fn ($cat) => $cat->type === 'surface'))->toBeTrue();
    });

    it('can chain active and byType scopes', function () {
        EstimateTemplateCategory::factory()->room()->count(2)->create(['is_active' => true]);
        EstimateTemplateCategory::factory()->room()->create(['is_active' => false]);
        EstimateTemplateCategory::factory()->surface()->count(2)->create(['is_active' => true]);

        $activeRoomCategories = EstimateTemplateCategory::active()
            ->byType(EstimateTemplateCategory::TYPE_ROOM)
            ->get();

        expect($activeRoomCategories)->toHaveCount(2)
            ->and($activeRoomCategories->every(fn ($cat) => $cat->is_active && $cat->type === 'room'))->toBeTrue();
    });
});

describe('EstimateTemplateCategory Factory States', function () {
    it('creates room category with room state', function () {
        $category = EstimateTemplateCategory::factory()->room()->create();

        expect($category->type)->toBe(EstimateTemplateCategory::TYPE_ROOM);
    });

    it('creates surface category with surface state', function () {
        $category = EstimateTemplateCategory::factory()->surface()->create();

        expect($category->type)->toBe(EstimateTemplateCategory::TYPE_SURFACE);
    });

    it('creates item category with item state', function () {
        $category = EstimateTemplateCategory::factory()->item()->create();

        expect($category->type)->toBe(EstimateTemplateCategory::TYPE_ITEM);
    });

    it('creates addon category with addon state', function () {
        $category = EstimateTemplateCategory::factory()->addon()->create();

        expect($category->type)->toBe(EstimateTemplateCategory::TYPE_ADDON);
    });

    it('creates inactive category with inactive state', function () {
        $category = EstimateTemplateCategory::factory()->inactive()->create();

        expect($category->is_active)->toBeFalse();
    });

    it('creates category with specific slug using withSlug state', function () {
        $category = EstimateTemplateCategory::factory()->withSlug('my-custom-slug')->create();

        expect($category->slug)->toBe('my-custom-slug');
    });
});

describe('EstimateTemplateCategory Relationships', function () {
    it('has templates relationship', function () {
        $category = EstimateTemplateCategory::factory()->create();

        expect($category->templates())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});
