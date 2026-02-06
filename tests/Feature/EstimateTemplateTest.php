<?php

use App\Models\CompanySetting;
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
        // Set up company settings with known hourly rate
        CompanySetting::truncate();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'hourly_wage' => 25.00,
            'interior_hourly_wage' => 25.00,
            'exterior_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.0, // No burden for simple calculation
        ]);

        $productionRate = ProductionRate::factory()->create([
            'production_rate' => 200, // 200 sqft/hour
            'scope' => 'interior',
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
                'scope_type' => 'interior',
            ]);

        // 400 sqft, 2 coats
        // Labor hours: (400 / 200) * 1.8 = 3.6 hours
        // Labor cost: 3.6 * $25 = $90 (using effective rate which is hourly_wage with no burden)
        // Material cost is now 0 (comes from paint products, not production rate)
        $price = $template->calculatePrice(400, 2);

        expect($price)->toBe(90.0);
    });

    it('uses default coats when coats parameter is null', function () {
        // Set up company settings with known hourly rate
        CompanySetting::truncate();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'hourly_wage' => 25.00,
            'interior_hourly_wage' => 25.00,
            'exterior_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.0, // No burden for simple calculation
        ]);

        $productionRate = ProductionRate::factory()->create([
            'production_rate' => 100,
            'scope' => 'interior',
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
                'scope_type' => 'interior',
            ]);

        // Should use template's default_coats (2)
        $priceWithDefault = $template->calculatePrice(100);

        // 100 sqft, 2 coats
        // Labor hours: (100 / 100) * 1.8 = 1.8 hours
        // Labor cost: 1.8 * $25 = $45 (using effective rate with no burden)
        // Material cost is now 0 (comes from paint products, not production rate)
        expect($priceWithDefault)->toBe(45.0);
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

describe('EstimateTemplate fixed price cost tracking', function () {
    it('calculates fixed price with per-template margin', function () {
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 21, // $21/hr
            'interior_margin_percent' => 50, // Default margin
        ]);

        $template = EstimateTemplate::factory()
            ->interior()
            ->fixedPricingWithCostTracking(
                laborHours: 2.0,
                materialCost: 20.00,
                marginPercent: 40.0 // Custom margin
            )
            ->create();

        $breakdown = $template->calculateFixedPrice();

        // Labor: 2 hrs × $21 = $42
        // Materials: $20
        // COGS: $62
        // With 40% margin: $62 / (1 - 0.40) = $103.33
        expect($breakdown['labor_hours'])->toBe(2.0)
            ->and($breakdown['labor_cost'])->toBe(42.0)
            ->and($breakdown['material_cost'])->toBe(20.0)
            ->and($breakdown['cogs'])->toBe(62.0)
            ->and($breakdown['margin_percent'])->toBe(40.0)
            ->and($breakdown['final_price'])->toBe(155.0)
            ->and($breakdown['profit'])->toBe(93.0);
    });

    it('falls back to scope margin when template margin is null', function () {
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 20, // $20/hr
            'interior_margin_percent' => 50, // 50% margin = price is 2× COGS
        ]);

        $template = EstimateTemplate::factory()
            ->interior()
            ->fixedPricingWithCostTracking(
                laborHours: 2.5,
                materialCost: 30.00,
                marginPercent: null // Falls back to scope margin
            )
            ->create();

        $breakdown = $template->calculateFixedPrice();

        // Labor: 2.5 hrs × $20 = $50
        // Materials: $30
        // COGS: $80
        // With 50% margin: $80 / 0.50 = $160
        expect($breakdown['labor_hours'])->toBe(2.5)
            ->and($breakdown['labor_cost'])->toBe(50.0)
            ->and($breakdown['material_cost'])->toBe(30.0)
            ->and($breakdown['cogs'])->toBe(80.0)
            ->and($breakdown['margin_percent'])->toBe(50.0)
            ->and($breakdown['final_price'])->toBe(160.0)
            ->and($breakdown['profit'])->toBe(80.0);
    });

    it('uses exterior hourly wage for exterior scope', function () {
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 21,
            'exterior_hourly_wage' => 27, // Higher exterior rate
            'exterior_margin_percent' => 41, // 41% exterior margin
        ]);

        $template = EstimateTemplate::factory()
            ->exterior()
            ->fixedPricingWithCostTracking(
                laborHours: 4.0,
                materialCost: 40.00,
                marginPercent: null // Uses scope margin (41%)
            )
            ->create();

        $breakdown = $template->calculateFixedPrice();

        // Labor: 4 hrs × $27 = $108
        // Materials: $40
        // COGS: $148
        // With 41% margin: $148 / 0.59 = $250.85
        expect($breakdown['labor_hours'])->toBe(4.0)
            ->and($breakdown['labor_cost'])->toBe(108.0)
            ->and($breakdown['material_cost'])->toBe(40.0)
            ->and($breakdown['cogs'])->toBe(148.0)
            ->and($breakdown['margin_percent'])->toBe(41.0)
            ->and($breakdown['final_price'])->toBe(360.98)
            ->and($breakdown['profit'])->toBe(212.98);
    });

    it('returns effective price from calculateFixedPrice for templates with cost tracking', function () {
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 21,
            'interior_margin_percent' => 50,
        ]);

        $template = EstimateTemplate::factory()
            ->interior()
            ->fixedPricingWithCostTracking(
                laborHours: 1.5,
                materialCost: 25.00,
                marginPercent: 50.0
            )
            ->create();

        // Labor: 1.5 hrs × $21 = $31.50
        // Materials: $25
        // COGS: $56.50
        // With 50% margin: $56.50 / 0.50 = $113
        expect($template->getEffectivePrice())->toBe(113.0);
    });

    it('returns base_price for legacy fixed templates without cost tracking', function () {
        $template = EstimateTemplate::factory()
            ->interior()
            ->fixedPricing(500.00)
            ->create();

        expect($template->getEffectivePrice())->toBe(500.0);
    });

    it('returns calculated_price if stored', function () {
        $template = EstimateTemplate::factory()
            ->interior()
            ->fixedPricing()
            ->create(['calculated_price' => 250.00]);

        expect($template->getEffectivePrice())->toBe(250.0);
    });
});
