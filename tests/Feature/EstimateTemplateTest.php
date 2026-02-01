<?php

use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\ProductionRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('EstimateTemplate model', function () {
    it('can be created with factory', function () {
        $template = EstimateTemplate::factory()->create();

        expect($template)->toBeInstanceOf(EstimateTemplate::class)
            ->and($template->id)->not->toBeNull();
    });

    it('auto-generates slug on creation', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $template = EstimateTemplate::create([
            'template_category_id' => $category->id,
            'name' => 'Interior Wall Painting',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 500.00,
        ]);

        expect($template->slug)->toBe('interior-wall-painting');
    });

    it('generates unique slug when duplicate name exists', function () {
        $category = EstimateTemplateCategory::factory()->create();

        $template1 = EstimateTemplate::create([
            'template_category_id' => $category->id,
            'name' => 'Ceiling Painting',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 300.00,
        ]);

        $template2 = EstimateTemplate::create([
            'template_category_id' => $category->id,
            'name' => 'Ceiling Painting',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 350.00,
        ]);

        expect($template1->slug)->toBe('ceiling-painting')
            ->and($template2->slug)->toBe('ceiling-painting-1');
    });

    it('belongs to a template category', function () {
        $category = EstimateTemplateCategory::factory()->create([
            'name' => 'Interior Rooms',
        ]);

        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
        ]);

        expect($template->templateCategory)->toBeInstanceOf(EstimateTemplateCategory::class)
            ->and($template->templateCategory->name)->toBe('Interior Rooms');
    });

    it('belongs to a production rate when using production rate pricing', function () {
        $productionRate = ProductionRate::factory()->create([
            'name' => 'Standard Interior Wall',
        ]);

        $template = EstimateTemplate::factory()
            ->withProductionRate($productionRate)
            ->create();

        expect($template->productionRate)->toBeInstanceOf(ProductionRate::class)
            ->and($template->productionRate->name)->toBe('Standard Interior Wall');
    });

    it('can have null production rate for non-production-rate pricing', function () {
        $template = EstimateTemplate::factory()
            ->fixedPricing(500.00)
            ->create();

        expect($template->productionRate)->toBeNull()
            ->and($template->production_rate_id)->toBeNull();
    });
});

describe('EstimateTemplate scopes', function () {
    it('filters active templates', function () {
        EstimateTemplate::factory()->count(3)->create(['is_active' => true]);
        EstimateTemplate::factory()->count(2)->inactive()->create();

        $activeTemplates = EstimateTemplate::active()->get();

        expect($activeTemplates)->toHaveCount(3);
    });

    it('filters by category', function () {
        $category1 = EstimateTemplateCategory::factory()->create();
        $category2 = EstimateTemplateCategory::factory()->create();

        EstimateTemplate::factory()->count(3)->create(['template_category_id' => $category1->id]);
        EstimateTemplate::factory()->count(2)->create(['template_category_id' => $category2->id]);

        $templatesInCategory1 = EstimateTemplate::byCategory($category1->id)->get();

        expect($templatesInCategory1)->toHaveCount(3);
    });
});

describe('EstimateTemplate price calculation', function () {
    it('calculates price using fixed pricing method', function () {
        $template = EstimateTemplate::factory()->fixedPricing(750.00)->create();

        $price = $template->calculatePrice(100); // quantity ignored for fixed
        $priceWithCoats = $template->calculatePrice(200, 3); // coats ignored for fixed

        expect($price)->toBe(750.0)
            ->and($priceWithCoats)->toBe(750.0);
    });

    it('calculates price using per sqft pricing method', function () {
        $template = EstimateTemplate::factory()->perSqftPricing(2.50)->create();

        $price = $template->calculatePrice(200);

        expect($price)->toBe(500.0); // 200 sqft * $2.50
    });

    it('calculates price using percent pricing method', function () {
        $template = EstimateTemplate::factory()->percentPricing(15.0)->create();

        // Percent pricing always returns 0 (calculated externally)
        $price = $template->calculatePrice(500);

        expect($price)->toBe(0.0);
    });

    it('calculates price using production rate pricing method', function () {
        $productionRate = ProductionRate::factory()->create([
            'production_rate' => 200, // 200 sqft/hour
            'hourly_labor_rate' => 60.00,
            'material_cost_per_unit' => 0.25,
            'default_coats' => 2,
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
                '3' => 2.5,
            ],
        ]);

        $template = EstimateTemplate::factory()
            ->withProductionRate($productionRate)
            ->create([
                'default_coats' => 2,
            ]);

        // 400 sqft, 2 coats
        // Labor: (400 / 200) * 60 = $120
        // Material: 400 * 0.25 = $100
        // Total before multiplier: $220
        // With 2-coat multiplier (1.8): $220 * 1.8 = $396
        $price = $template->calculatePrice(400, 2);

        expect($price)->toBe(396.0);
    });

    it('uses default coats when coats parameter is null', function () {
        $productionRate = ProductionRate::factory()->create([
            'production_rate' => 100,
            'hourly_labor_rate' => 50.00,
            'material_cost_per_unit' => 0.10,
            'default_coats' => 1,
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
            ],
        ]);

        $template = EstimateTemplate::factory()
            ->withProductionRate($productionRate)
            ->create([
                'default_coats' => 2, // Template default is 2 coats
            ]);

        // Should use template's default_coats (2)
        $priceWithDefault = $template->calculatePrice(100);

        // 100 sqft, 2 coats
        // Labor: (100 / 100) * 50 = $50
        // Material: 100 * 0.10 = $10
        // Total: $60 * 1.8 = $108
        expect($priceWithDefault)->toBe(108.0);
    });

    it('returns zero when production rate is null for production rate pricing', function () {
        $template = EstimateTemplate::factory()->create([
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
            'production_rate_id' => null,
        ]);

        $price = $template->calculatePrice(500);

        expect($price)->toBe(0.0);
    });
});

describe('EstimateTemplate casts', function () {
    it('casts decimal fields correctly', function () {
        $template = EstimateTemplate::factory()->create([
            'default_quantity' => '150.50',
            'base_price' => '1234.56',
            'per_sqft_rate' => '2.7500',
            'percent_of_total' => '12.50',
        ]);

        expect($template->default_quantity)->toBe('150.50')
            ->and($template->base_price)->toBe('1234.56')
            ->and($template->per_sqft_rate)->toBe('2.7500')
            ->and($template->percent_of_total)->toBe('12.50');
    });

    it('casts JSON fields to arrays', function () {
        $surfaces = ['walls', 'ceiling', 'trim'];
        $options = ['include_primer' => true, 'extra_prep' => false];
        $metadata = ['source' => 'imported', 'version' => 2];

        $template = EstimateTemplate::factory()->create([
            'default_surfaces' => $surfaces,
            'options' => $options,
            'metadata' => $metadata,
        ]);

        expect($template->default_surfaces)->toBe($surfaces)
            ->and($template->options)->toBe($options)
            ->and($template->metadata)->toBe($metadata);
    });

    it('casts boolean and integer fields correctly', function () {
        $template = EstimateTemplate::factory()->create([
            'is_active' => 1,
            'sort_order' => '5',
            'default_coats' => '3',
        ]);

        expect($template->is_active)->toBe(true)
            ->and($template->sort_order)->toBe(5)
            ->and($template->default_coats)->toBe(3);
    });
});

describe('EstimateTemplate attributes', function () {
    it('returns pricing method label', function () {
        $productionRateTemplate = EstimateTemplate::factory()->create([
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);
        $fixedTemplate = EstimateTemplate::factory()->fixedPricing()->create();
        $perSqftTemplate = EstimateTemplate::factory()->perSqftPricing()->create();
        $percentTemplate = EstimateTemplate::factory()->percentPricing()->create();

        expect($productionRateTemplate->pricing_method_label)->toBe('Production Rate')
            ->and($fixedTemplate->pricing_method_label)->toBe('Fixed Price')
            ->and($perSqftTemplate->pricing_method_label)->toBe('Per Square Foot')
            ->and($percentTemplate->pricing_method_label)->toBe('Percentage of Total');
    });

    it('returns scope type label', function () {
        $interiorTemplate = EstimateTemplate::factory()->interior()->create();
        $exteriorTemplate = EstimateTemplate::factory()->exterior()->create();
        $bothTemplate = EstimateTemplate::factory()->create([
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);
        $nullScopeTemplate = EstimateTemplate::factory()->create([
            'scope_type' => null,
        ]);

        expect($interiorTemplate->scope_type_label)->toBe('Interior')
            ->and($exteriorTemplate->scope_type_label)->toBe('Exterior')
            ->and($bothTemplate->scope_type_label)->toBe('Interior & Exterior')
            ->and($nullScopeTemplate->scope_type_label)->toBeNull();
    });
});

describe('EstimateTemplate constants', function () {
    it('has correct pricing method constants', function () {
        expect(EstimateTemplate::PRICING_PRODUCTION_RATE)->toBe('production_rate')
            ->and(EstimateTemplate::PRICING_FIXED)->toBe('fixed')
            ->and(EstimateTemplate::PRICING_PER_SQFT)->toBe('per_sqft')
            ->and(EstimateTemplate::PRICING_PERCENT)->toBe('percent')
            ->and(EstimateTemplate::PRICING_METHODS)->toBe([
                'production_rate',
                'fixed',
                'per_sqft',
                'percent',
            ]);
    });

    it('has correct scope type constants', function () {
        expect(EstimateTemplate::SCOPE_TYPE_INTERIOR)->toBe('interior')
            ->and(EstimateTemplate::SCOPE_TYPE_EXTERIOR)->toBe('exterior')
            ->and(EstimateTemplate::SCOPE_TYPE_BOTH)->toBe('both');
    });
});

describe('EstimateTemplateCategory relationship', function () {
    it('has many templates', function () {
        $category = EstimateTemplateCategory::factory()->create();

        EstimateTemplate::factory()->count(3)->create([
            'template_category_id' => $category->id,
        ]);

        expect($category->templates)->toHaveCount(3);
    });

    it('returns templates ordered by sort_order', function () {
        $category = EstimateTemplateCategory::factory()->create();

        EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Third',
            'sort_order' => 3,
        ]);
        EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'First',
            'sort_order' => 1,
        ]);
        EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Second',
            'sort_order' => 2,
        ]);

        $templates = $category->templates;

        expect($templates[0]->name)->toBe('First')
            ->and($templates[1]->name)->toBe('Second')
            ->and($templates[2]->name)->toBe('Third');
    });
});
