<?php

use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EstimateTemplate price calculations', function () {
    it('calculates production rate pricing correctly', function () {
        // Create a production rate category first
        $category = ProductionRateCategory::factory()->create();

        // Create a production rate with known values
        // Formula: ((quantity / production_rate) * hourly_labor_rate + (quantity * material_cost_per_unit)) * coat_multiplier
        $productionRate = ProductionRate::factory()->create([
            'category_id' => $category->id,
            'production_rate' => 200, // 200 sqft/hour
            'hourly_labor_rate' => 50, // $50/hour
            'material_cost_per_unit' => 0.25, // $0.25/sqft
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
                '3' => 2.5,
            ],
            'default_coats' => 2,
        ]);

        $template = EstimateTemplate::factory()
            ->withProductionRate($productionRate)
            ->create();

        // Calculate price for 1000 sqft with 2 coats
        // Labor: (1000 / 200) * 50 = 5 * 50 = $250
        // Material: 1000 * 0.25 = $250
        // Subtotal: 250 + 250 = $500
        // With 2 coat multiplier (1.8): 500 * 1.8 = $900
        $price = $template->calculatePrice(1000, 2);

        expect($price)->toBe(900.0);
    });

    it('uses default coats when not specified for production rate pricing', function () {
        $category = ProductionRateCategory::factory()->create();

        $productionRate = ProductionRate::factory()->create([
            'category_id' => $category->id,
            'production_rate' => 100, // 100 sqft/hour
            'hourly_labor_rate' => 60, // $60/hour
            'material_cost_per_unit' => 0.50, // $0.50/sqft
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
            ],
            'default_coats' => 2,
        ]);

        $template = EstimateTemplate::factory()
            ->withProductionRate($productionRate)
            ->create([
                'default_coats' => 2,
            ]);

        // 500 sqft, default 2 coats
        // Labor: (500 / 100) * 60 = 5 * 60 = $300
        // Material: 500 * 0.50 = $250
        // Subtotal: $550
        // With 2 coat multiplier (1.8): 550 * 1.8 = $990
        $price = $template->calculatePrice(500);

        expect($price)->toBe(990.0);
    });

    it('returns zero when production rate is missing', function () {
        $template = EstimateTemplate::factory()->create([
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
            'production_rate_id' => null,
        ]);

        $price = $template->calculatePrice(1000, 2);

        expect($price)->toBe(0.0);
    });

    it('returns base price for fixed pricing', function () {
        $template = EstimateTemplate::factory()
            ->fixedPricing(499.99)
            ->create();

        // Fixed pricing should return base_price regardless of quantity
        $price = $template->calculatePrice(1000, 2);

        expect($price)->toBe(499.99);
    });

    it('returns base price for fixed pricing regardless of quantity', function () {
        $template = EstimateTemplate::factory()
            ->fixedPricing(750.00)
            ->create();

        // Different quantities should all return the same fixed price
        $price1 = $template->calculatePrice(100);
        $price2 = $template->calculatePrice(500);
        $price3 = $template->calculatePrice(2000);

        expect($price1)->toBe(750.00);
        expect($price2)->toBe(750.00);
        expect($price3)->toBe(750.00);
    });

    it('calculates per sqft pricing by multiplying quantity', function () {
        $template = EstimateTemplate::factory()
            ->perSqftPricing(2.50)
            ->create();

        // 400 sqft at $2.50/sqft = $1000
        $price = $template->calculatePrice(400);

        expect($price)->toBe(1000.0);
    });

    it('calculates per sqft pricing with different quantities', function () {
        $template = EstimateTemplate::factory()
            ->perSqftPricing(3.25)
            ->create();

        // Various quantities
        expect($template->calculatePrice(100))->toBe(325.0);
        expect($template->calculatePrice(250))->toBe(812.5);
        expect($template->calculatePrice(1000))->toBe(3250.0);
    });

    it('returns zero for percent pricing for external calculation', function () {
        $template = EstimateTemplate::factory()
            ->percentPricing(15.0)
            ->create();

        // Percent pricing returns 0 as it's calculated externally based on total
        $price = $template->calculatePrice(1000);

        expect($price)->toBe(0.0);
    });

    it('handles null per sqft rate gracefully', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'pricing_method' => EstimateTemplate::PRICING_PER_SQFT,
            'per_sqft_rate' => null,
        ]);

        $price = $template->calculatePrice(1000);

        expect($price)->toBe(0.0);
    });
});

describe('EstimateTemplate scopes', function () {
    it('filters inactive templates with active scope', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $activeTemplate1 = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Active Template 1',
            'is_active' => true,
        ]);

        $activeTemplate2 = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Active Template 2',
            'is_active' => true,
        ]);

        $inactiveTemplate = EstimateTemplate::factory()
            ->inactive()
            ->create([
                'template_category_id' => $category->id,
                'name' => 'Inactive Template',
            ]);

        $activeTemplates = EstimateTemplate::active()->get();

        expect($activeTemplates)->toHaveCount(2);
        expect($activeTemplates->pluck('id')->toArray())
            ->toContain($activeTemplate1->id)
            ->toContain($activeTemplate2->id)
            ->not->toContain($inactiveTemplate->id);
    });

    it('returns all templates when not using active scope', function () {
        $category = EstimateTemplateCategory::factory()->create();

        EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'is_active' => true,
        ]);

        EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'is_active' => false,
        ]);

        $allTemplates = EstimateTemplate::all();

        expect($allTemplates)->toHaveCount(2);
    });

    it('filters templates by category with byCategory scope', function () {
        $category1 = EstimateTemplateCategory::factory()->create(['name' => 'Interior']);
        $category2 = EstimateTemplateCategory::factory()->create(['name' => 'Exterior']);

        $interiorTemplate1 = EstimateTemplate::factory()->create([
            'template_category_id' => $category1->id,
            'name' => 'Interior Walls',
        ]);

        $interiorTemplate2 = EstimateTemplate::factory()->create([
            'template_category_id' => $category1->id,
            'name' => 'Interior Ceiling',
        ]);

        $exteriorTemplate = EstimateTemplate::factory()->create([
            'template_category_id' => $category2->id,
            'name' => 'Exterior Siding',
        ]);

        $interiorTemplates = EstimateTemplate::byCategory($category1->id)->get();

        expect($interiorTemplates)->toHaveCount(2);
        expect($interiorTemplates->pluck('id')->toArray())
            ->toContain($interiorTemplate1->id)
            ->toContain($interiorTemplate2->id)
            ->not->toContain($exteriorTemplate->id);
    });

    it('returns empty collection when category has no templates', function () {
        $category = EstimateTemplateCategory::factory()->create();
        $otherCategory = EstimateTemplateCategory::factory()->create();

        EstimateTemplate::factory()->create([
            'template_category_id' => $otherCategory->id,
        ]);

        $templates = EstimateTemplate::byCategory($category->id)->get();

        expect($templates)->toBeEmpty();
    });

    it('combines active and byCategory scopes', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $activeInCategory = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'is_active' => true,
        ]);

        EstimateTemplate::factory()->inactive()->create([
            'template_category_id' => $category->id,
        ]);

        $templates = EstimateTemplate::active()
            ->byCategory($category->id)
            ->get();

        expect($templates)->toHaveCount(1);
        expect($templates->first()->id)->toBe($activeInCategory->id);
    });
});

describe('EstimateTemplate attributes', function () {
    it('generates unique slug on creation', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $template1 = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Interior Walls',
            'slug' => null,
        ]);

        $template2 = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Interior Walls',
            'slug' => null,
        ]);

        expect($template1->slug)->toBe('interior-walls');
        expect($template2->slug)->toBe('interior-walls-1');
    });

    it('returns correct pricing method label', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $productionRateTemplate = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $fixedTemplate = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
        ]);

        $perSqftTemplate = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'pricing_method' => EstimateTemplate::PRICING_PER_SQFT,
        ]);

        $percentTemplate = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'pricing_method' => EstimateTemplate::PRICING_PERCENT,
        ]);

        expect($productionRateTemplate->pricing_method_label)->toBe('Production Rate');
        expect($fixedTemplate->pricing_method_label)->toBe('Fixed Price');
        expect($perSqftTemplate->pricing_method_label)->toBe('Per Square Foot');
        expect($percentTemplate->pricing_method_label)->toBe('Percentage of Total');
    });

    it('returns correct scope type label', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $interiorTemplate = EstimateTemplate::factory()->interior()->create([
            'template_category_id' => $category->id,
        ]);

        $exteriorTemplate = EstimateTemplate::factory()->exterior()->create([
            'template_category_id' => $category->id,
        ]);

        $bothTemplate = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        expect($interiorTemplate->scope_type_label)->toBe('Interior');
        expect($exteriorTemplate->scope_type_label)->toBe('Exterior');
        expect($bothTemplate->scope_type_label)->toBe('Interior & Exterior');
    });
});
