<?php

use App\Models\AreaTemplateNestedOption;
use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\User;
use App\Services\AreaTemplateService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new AreaTemplateService;
    $this->category = EstimateTemplateCategory::factory()->create();

    // Ensure company settings exist
    CompanySetting::create([
        'company_name' => 'Test Painting Co',
        'hourly_wage' => 25.00,
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'profit_margin' => 0.15,
        'overhead_margin' => 0.10,
        'markup_margin' => 0.08,
        'interior_profit_margin' => 0.20,
        'interior_overhead_margin' => 0.15,
        'interior_markup_margin' => 0.10,
        'exterior_profit_margin' => 0.15,
        'exterior_overhead_margin' => 0.12,
        'exterior_markup_margin' => 0.10,
        'cleanup_ratio' => 0.10,
        'interior_cleanup_ratio' => 0.10,
        'exterior_cleanup_ratio' => 0.12,
        'sundries_ratio' => 0.05,
        'interior_sundries_ratio' => 0.05,
        'exterior_sundries_ratio' => 0.06,
    ]);
});

describe('createTemplate', function () {
    it('creates a new area template with basic info', function () {
        $data = [
            'name' => 'Living Room',
            'description' => 'Standard living room template',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
            'default_coats' => 2,
            'template_category_id' => $this->category->id,
        ];

        $template = $this->service->createTemplate($data, $this->user);

        expect($template)->toBeInstanceOf(EstimateTemplate::class)
            ->and($template->name)->toBe('Living Room')
            ->and($template->description)->toBe('Standard living room template')
            ->and($template->scope_type)->toBe(EstimateTemplate::SCOPE_TYPE_INTERIOR)
            ->and($template->default_coats)->toBe(2)
            ->and($template->created_by)->toBe($this->user->id)
            ->and($template->is_configurable)->toBeTrue()
            ->and($template->visibility)->toBe(EstimateTemplate::VISIBILITY_COMPANY);
    });

    it('creates template with surfaces', function () {
        $rateCategory = ProductionRateCategory::factory()->create();
        $wallRate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'name' => 'Wall Painting',
            'production_rate' => 300,
        ]);

        $data = [
            'name' => 'Bedroom',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
            'template_category_id' => $this->category->id,
            'surfaces' => [
                [
                    'name' => 'Walls',
                    'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
                    'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
                    'calculation_value' => 1.0,
                    'production_rate_id' => $wallRate->id,
                    'is_required' => true,
                ],
                [
                    'name' => 'Ceiling',
                    'surface_type' => AreaTemplateSurface::SURFACE_TYPE_CEILING,
                    'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
                    'calculation_value' => 0.9,
                    'is_required' => true,
                ],
            ],
        ];

        $template = $this->service->createTemplate($data, $this->user);

        expect($template->surfaces)->toHaveCount(2)
            ->and($template->surfaces[0]->name)->toBe('Walls')
            ->and($template->surfaces[0]->is_required)->toBeTrue()
            ->and($template->surfaces[1]->name)->toBe('Ceiling')
            ->and($template->surfaces[1]->calculation_value)->toBe('0.9000');
    });

    it('creates template with nested options', function () {
        $nestedTemplate = NestedAreaTemplate::factory()->create([
            'name' => 'Standard Closet',
            'fixed_price' => 75.00,
        ]);

        $data = [
            'name' => 'Master Bedroom',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
            'template_category_id' => $this->category->id,
            'nested_options' => [
                [
                    'name' => 'Walk-in Closet',
                    'nested_area_template_id' => $nestedTemplate->id,
                    'is_default_included' => true,
                ],
            ],
        ];

        $template = $this->service->createTemplate($data, $this->user);

        expect($template->nestedOptions)->toHaveCount(1)
            ->and($template->nestedOptions[0]->name)->toBe('Walk-in Closet')
            ->and($template->nestedOptions[0]->is_default_included)->toBeTrue()
            ->and($template->nestedOptions[0]->nested_area_template_id)->toBe($nestedTemplate->id);
    });
});

describe('updateTemplate', function () {
    it('updates basic template info', function () {
        $template = EstimateTemplate::factory()->configurable()->createdBy($this->user)->create([
            'name' => 'Old Name',
        ]);

        $updated = $this->service->updateTemplate($template, [
            'name' => 'New Name',
            'description' => 'Updated description',
        ]);

        expect($updated->name)->toBe('New Name')
            ->and($updated->description)->toBe('Updated description');
    });

    it('syncs surfaces when updating', function () {
        $template = EstimateTemplate::factory()->configurable()->createdBy($this->user)->create();

        // Add initial surfaces
        AreaTemplateSurface::factory()->walls()->create(['estimate_template_id' => $template->id]);
        AreaTemplateSurface::factory()->ceiling()->create(['estimate_template_id' => $template->id]);

        expect($template->fresh()->surfaces)->toHaveCount(2);

        // Update with new surfaces
        $updated = $this->service->updateTemplate($template, [
            'surfaces' => [
                [
                    'name' => 'Only Walls',
                    'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
                    'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
                    'calculation_value' => 1.0,
                ],
            ],
        ]);

        expect($updated->surfaces)->toHaveCount(1)
            ->and($updated->surfaces[0]->name)->toBe('Only Walls');
    });
});

describe('cloneTemplate', function () {
    it('clones a template for a new owner', function () {
        $originalOwner = User::factory()->create();
        $newOwner = User::factory()->create();

        $original = EstimateTemplate::factory()->configurable()->createdBy($originalOwner)->create([
            'name' => 'Original Template',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
        ]);

        AreaTemplateSurface::factory()->walls()->create(['estimate_template_id' => $original->id]);
        AreaTemplateSurface::factory()->ceiling()->create(['estimate_template_id' => $original->id]);

        $clone = $this->service->cloneTemplate($original, $newOwner);

        expect($clone->id)->not->toBe($original->id)
            ->and($clone->name)->toBe('Original Template (Copy)')
            ->and($clone->created_by)->toBe($newOwner->id)
            ->and($clone->visibility)->toBe(EstimateTemplate::VISIBILITY_COMPANY)
            ->and($clone->surfaces)->toHaveCount(2);
    });

    it('allows custom name when cloning', function () {
        $original = EstimateTemplate::factory()->configurable()->createdBy($this->user)->create([
            'name' => 'Original',
        ]);

        $clone = $this->service->cloneTemplate($original, $this->user, 'My Custom Clone');

        expect($clone->name)->toBe('My Custom Clone');
    });
});

describe('surface management', function () {
    it('adds a surface to template', function () {
        $template = EstimateTemplate::factory()->configurable()->createdBy($this->user)->create();

        $surface = $this->service->addSurface($template, [
            'name' => 'Baseboards',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_TRIM,
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_PER_100SQFT,
            'calculation_value' => 28,
            'unit_type' => AreaTemplateSurface::UNIT_TYPE_LNFT,
        ]);

        expect($surface)->toBeInstanceOf(AreaTemplateSurface::class)
            ->and($surface->name)->toBe('Baseboards')
            ->and($surface->calculation_method)->toBe(AreaTemplateSurface::CALC_METHOD_PER_100SQFT)
            ->and($surface->calculation_value)->toBe('28.0000');
    });

    it('updates a surface', function () {
        $template = EstimateTemplate::factory()->configurable()->createdBy($this->user)->create();
        $surface = AreaTemplateSurface::factory()->walls()->create(['estimate_template_id' => $template->id]);

        $updated = $this->service->updateSurface($surface, [
            'name' => 'Updated Walls',
            'default_coats' => 3,
        ]);

        expect($updated->name)->toBe('Updated Walls')
            ->and($updated->default_coats)->toBe(3);
    });

    it('removes a surface', function () {
        $template = EstimateTemplate::factory()->configurable()->createdBy($this->user)->create();
        $surface = AreaTemplateSurface::factory()->walls()->create(['estimate_template_id' => $template->id]);

        $result = $this->service->removeSurface($surface);

        expect($result)->toBeTrue()
            ->and(AreaTemplateSurface::find($surface->id))->toBeNull();
    });
});

describe('calculatePreview', function () {
    it('calculates pricing preview for a template', function () {
        $rateCategory = ProductionRateCategory::factory()->create();
        $wallRate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'production_rate' => 200, // 200 sqft/hr
            'material_cost_per_unit' => 0.50,
        ]);

        $template = EstimateTemplate::factory()->configurable()->interior()->createdBy($this->user)->create([
            'template_category_id' => $this->category->id,
        ]);

        AreaTemplateSurface::factory()->create([
            'estimate_template_id' => $template->id,
            'name' => 'Walls',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
            'calculation_value' => 1.0,
            'production_rate_id' => $wallRate->id,
            'is_required' => true,
            'is_default_enabled' => true,
        ]);

        $preview = $this->service->calculatePreview($template->fresh(), sqft: 200, coats: 2);

        expect($preview)->toHaveKeys(['surfaces', 'nested', 'totals'])
            ->and($preview['surfaces'])->toHaveCount(1)
            ->and($preview['surfaces'][0]['name'])->toBe('Walls')
            ->and($preview['surfaces'][0]['quantity'])->toBe(200.0)
            ->and($preview['totals']['labor_hours'])->toBeGreaterThan(0)
            ->and($preview['totals']['with_margins'])->toBeGreaterThan(0);
    });

    it('includes nested items in preview', function () {
        $template = EstimateTemplate::factory()->configurable()->interior()->createdBy($this->user)->create([
            'template_category_id' => $this->category->id,
        ]);

        $nestedTemplate = NestedAreaTemplate::factory()->create([
            'fixed_price' => 75.00,
        ]);

        AreaTemplateNestedOption::factory()->create([
            'parent_template_id' => $template->id,
            'nested_area_template_id' => $nestedTemplate->id,
            'name' => 'Closet',
            'is_default_included' => true,
        ]);

        $preview = $this->service->calculatePreview($template->fresh(), sqft: 200, coats: 2);

        expect($preview['nested'])->toHaveCount(1)
            ->and($preview['nested'][0]['name'])->toBe('Closet')
            ->and($preview['nested'][0]['price'])->toBe(75.0)
            ->and($preview['totals']['nested_cost'])->toBe(75.0);
    });

    it('applies coat multiplier correctly', function () {
        $rateCategory = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'production_rate' => 100, // 100 sqft/hr
        ]);

        $template = EstimateTemplate::factory()->configurable()->interior()->createdBy($this->user)->create([
            'template_category_id' => $this->category->id,
        ]);

        AreaTemplateSurface::factory()->create([
            'estimate_template_id' => $template->id,
            'name' => 'Walls',
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
            'calculation_value' => 1.0,
            'production_rate_id' => $rate->id,
            'is_required' => true,
        ]);

        // 100 sqft at 100 sqft/hr = 1 hour base
        $preview1 = $this->service->calculatePreview($template->fresh(), sqft: 100, coats: 1);
        $preview2 = $this->service->calculatePreview($template->fresh(), sqft: 100, coats: 2);

        // 2 coats should use 1.8x multiplier (not 2x due to efficiency)
        expect($preview1['totals']['labor_hours'])->toBe(1.0)
            ->and($preview2['totals']['labor_hours'])->toBe(1.8);
    });
});

describe('visibility and permissions', function () {
    it('gets visible templates for user', function () {
        $otherUser = User::factory()->create();

        // Create various templates
        EstimateTemplate::factory()->configurable()->system()->create(['name' => 'System Template']);
        EstimateTemplate::factory()->configurable()->company()->createdBy($otherUser)->create(['name' => 'Company Template']);
        EstimateTemplate::factory()->configurable()->private()->createdBy($this->user)->create(['name' => 'My Private']);
        EstimateTemplate::factory()->configurable()->private()->createdBy($otherUser)->create(['name' => 'Other Private']);

        $templates = $this->service->getVisibleTemplates($this->user);

        $names = $templates->pluck('name')->toArray();

        expect($names)->toContain('System Template')
            ->and($names)->toContain('Company Template')
            ->and($names)->toContain('My Private')
            ->and($names)->not->toContain('Other Private');
    });

    it('filters by scope', function () {
        EstimateTemplate::factory()->configurable()->interior()->create(['name' => 'Interior']);
        EstimateTemplate::factory()->configurable()->exterior()->create(['name' => 'Exterior']);

        $interiorTemplates = $this->service->getVisibleTemplates($this->user, scope: 'interior');

        $names = $interiorTemplates->pluck('name')->toArray();

        expect($names)->toContain('Interior')
            ->and($names)->not->toContain('Exterior');
    });

    it('deletes template only if allowed', function () {
        $systemTemplate = EstimateTemplate::factory()->configurable()->system()->create();
        $companyTemplate = EstimateTemplate::factory()->configurable()->company()->createdBy($this->user)->create();

        expect($this->service->deleteTemplate($systemTemplate, $this->user))->toBeFalse()
            ->and(EstimateTemplate::find($systemTemplate->id))->not->toBeNull();

        expect($this->service->deleteTemplate($companyTemplate, $this->user))->toBeTrue()
            ->and(EstimateTemplate::find($companyTemplate->id))->toBeNull();
    });
});

describe('AreaTemplateSurface model', function () {
    it('calculates quantity using ratio method', function () {
        $surface = new AreaTemplateSurface([
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
            'calculation_value' => 0.9,
        ]);

        expect($surface->calculateQuantity(100))->toBe(90.0);
    });

    it('calculates quantity using per_100sqft method', function () {
        $surface = new AreaTemplateSurface([
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_PER_100SQFT,
            'calculation_value' => 28, // 28 lnft per 100 sqft
        ]);

        expect($surface->calculateQuantity(200))->toBe(56.0); // (200/100) * 28
    });

    it('calculates quantity using fixed_count method', function () {
        $surface = new AreaTemplateSurface([
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_FIXED_COUNT,
            'calculation_value' => 2, // 2 doors
        ]);

        expect($surface->calculateQuantity(500))->toBe(2.0); // Always 2, regardless of sqft
    });
});
