<?php

use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;

describe('ProductionRateCategory model', function () {
    it('can create a category', function () {
        $category = ProductionRateCategory::factory()->create([
            'name' => 'Test Category',
            'type' => ProductionRateCategory::TYPE_INTERIOR,
            'description' => 'Test description',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        expect($category)->toBeInstanceOf(ProductionRateCategory::class);
        expect($category->name)->toBe('Test Category');
        expect($category->type)->toBe('interior');
        expect($category->description)->toBe('Test description');
        expect($category->sort_order)->toBe(5);
        expect($category->is_active)->toBeTrue();
    });

    it('can update a category', function () {
        $category = ProductionRateCategory::factory()->create([
            'name' => 'Original Name',
            'description' => 'Original description',
        ]);

        $category->update([
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);

        expect($category->fresh()->name)->toBe('Updated Name');
        expect($category->fresh()->description)->toBe('Updated description');
    });

    it('can delete a category', function () {
        $category = ProductionRateCategory::factory()->create();
        $categoryId = $category->id;

        $category->delete();

        expect(ProductionRateCategory::find($categoryId))->toBeNull();
    });

    it('cascades delete to rates when category is deleted', function () {
        $category = ProductionRateCategory::factory()->create();
        $rate1 = ProductionRate::factory()->forCategory($category)->create();
        $rate2 = ProductionRate::factory()->forCategory($category)->create();

        $rate1Id = $rate1->id;
        $rate2Id = $rate2->id;

        expect(ProductionRate::count())->toBe(2);

        $category->delete();

        expect(ProductionRate::find($rate1Id))->toBeNull();
        expect(ProductionRate::find($rate2Id))->toBeNull();
        expect(ProductionRate::count())->toBe(0);
    });
});

describe('ProductionRateCategory active scope', function () {
    it('filters to only active categories', function () {
        ProductionRateCategory::factory()->count(3)->create(['is_active' => true]);
        ProductionRateCategory::factory()->inactive()->count(2)->create();

        $activeCategories = ProductionRateCategory::active()->get();

        expect($activeCategories)->toHaveCount(3);
        expect($activeCategories->every(fn ($cat) => $cat->is_active))->toBeTrue();
    });

    it('returns empty collection when no active categories exist', function () {
        ProductionRateCategory::factory()->inactive()->count(3)->create();

        $activeCategories = ProductionRateCategory::active()->get();

        expect($activeCategories)->toHaveCount(0);
    });
});

describe('ProductionRateCategory byType scope', function () {
    beforeEach(function () {
        ProductionRateCategory::factory()->interior()->count(2)->create();
        ProductionRateCategory::factory()->exterior()->count(3)->create();
        ProductionRateCategory::factory()->specialty()->count(1)->create();
    });

    it('filters to interior categories', function () {
        $interiorCategories = ProductionRateCategory::byType('interior')->get();

        expect($interiorCategories)->toHaveCount(2);
        expect($interiorCategories->every(fn ($cat) => $cat->type === 'interior'))->toBeTrue();
    });

    it('filters to exterior categories', function () {
        $exteriorCategories = ProductionRateCategory::byType('exterior')->get();

        expect($exteriorCategories)->toHaveCount(3);
        expect($exteriorCategories->every(fn ($cat) => $cat->type === 'exterior'))->toBeTrue();
    });

    it('filters to specialty categories', function () {
        $specialtyCategories = ProductionRateCategory::byType('specialty')->get();

        expect($specialtyCategories)->toHaveCount(1);
        expect($specialtyCategories->every(fn ($cat) => $cat->type === 'specialty'))->toBeTrue();
    });

    it('returns empty collection for nonexistent type', function () {
        $categories = ProductionRateCategory::byType('nonexistent')->get();

        expect($categories)->toHaveCount(0);
    });
});

describe('ProductionRateCategory rates relationship', function () {
    it('returns related production rates', function () {
        $category = ProductionRateCategory::factory()->create();
        ProductionRate::factory()->forCategory($category)->count(3)->create();

        expect($category->rates)->toHaveCount(3);
        expect($category->rates->first())->toBeInstanceOf(ProductionRate::class);
    });

    it('returns empty collection when no rates exist', function () {
        $category = ProductionRateCategory::factory()->create();

        expect($category->rates)->toHaveCount(0);
    });

    it('only returns rates belonging to this category', function () {
        $category1 = ProductionRateCategory::factory()->create();
        $category2 = ProductionRateCategory::factory()->create();

        ProductionRate::factory()->forCategory($category1)->count(2)->create();
        ProductionRate::factory()->forCategory($category2)->count(3)->create();

        expect($category1->rates)->toHaveCount(2);
        expect($category2->rates)->toHaveCount(3);
    });
});

describe('ProductionRateCategory type label attribute', function () {
    it('returns Interior for interior type', function () {
        $category = ProductionRateCategory::factory()->create([
            'type' => ProductionRateCategory::TYPE_INTERIOR,
        ]);

        expect($category->type_label)->toBe('Interior');
    });

    it('returns Exterior for exterior type', function () {
        $category = ProductionRateCategory::factory()->create([
            'type' => ProductionRateCategory::TYPE_EXTERIOR,
        ]);

        expect($category->type_label)->toBe('Exterior');
    });

    it('returns Specialty for specialty type', function () {
        $category = ProductionRateCategory::factory()->create([
            'type' => ProductionRateCategory::TYPE_SPECIALTY,
        ]);

        expect($category->type_label)->toBe('Specialty');
    });

    it('returns raw type for unknown type', function () {
        $category = ProductionRateCategory::factory()->create([
            'type' => 'unknown_type',
        ]);

        expect($category->type_label)->toBe('unknown_type');
    });
});

describe('ProductionRateCategory constants', function () {
    it('has correct type constants', function () {
        expect(ProductionRateCategory::TYPE_INTERIOR)->toBe('interior');
        expect(ProductionRateCategory::TYPE_EXTERIOR)->toBe('exterior');
        expect(ProductionRateCategory::TYPE_SPECIALTY)->toBe('specialty');
    });

    it('has types array with all types', function () {
        expect(ProductionRateCategory::TYPES)->toBe([
            'interior' => 'Interior',
            'exterior' => 'Exterior',
            'specialty' => 'Specialty',
        ]);
    });
});
