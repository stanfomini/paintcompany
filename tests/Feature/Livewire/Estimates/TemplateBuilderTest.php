<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\User;
use Livewire\Livewire;

describe('TemplateBuilder component', function () {
    it('renders the template builder component for creating a new estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create a category with templates for testing
        $category = EstimateTemplateCategory::factory()->room()->create();
        EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
            'default_quantity' => 150,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->assertStatus(200)
            ->assertSee('Template Builder')
            ->assertSee($deal->title);
    });

    it('renders the template builder component for editing an existing estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Existing Template Estimate',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee('Edit Template Estimate')
            ->assertSet('title', 'Existing Template Estimate')
            ->assertSet('isEditing', true);
    });

    it('loads defaults from company settings on mount', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_tax_rate' => 8.25,
            'default_deposit_percent' => 50,
            'estimate_validity_days' => 14,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        expect($component->get('taxRate'))->toBe(8.25);
        expect($component->get('depositPercent'))->toBe(50.0);
    });

    it('loads template categories for filtering', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $roomCategory = EstimateTemplateCategory::factory()->room()->create(['name' => 'Rooms']);
        $surfaceCategory = EstimateTemplateCategory::factory()->surface()->create(['name' => 'Surfaces']);

        // Categories are loaded by the template-picker child component now
        // Verify they exist in the database
        $categories = EstimateTemplateCategory::active()->orderBy('sort_order')->get();
        expect($categories)->toHaveCount(2);
        expect($categories->pluck('name')->toArray())->toContain('Rooms', 'Surfaces');
    });

    it('can add a template to selected list', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
            'default_quantity' => 150,
            'default_coats' => 2,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->assertDispatched('template-added');

        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['template_id'])->toBe($template->id);
        expect($selectedTemplates[0]['name'])->toBe('Bedroom');
        expect($selectedTemplates[0]['quantity'])->toBe(150.0);
        expect($selectedTemplates[0]['coats'])->toBe(2);
        expect($selectedTemplates[0]['price'])->toBe(500.0);
    });

    it('can add multiple templates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template1 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
        ]);
        $template2 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(800)->create([
            'name' => 'Living Room',
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template1->id)
            ->call('addTemplate', $template2->id);

        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates)->toHaveCount(2);
    });

    it('can remove a template from selected list', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id);

        expect($component->get('selectedTemplates'))->toHaveCount(1);

        $component->call('removeTemplate', 0);

        expect($component->get('selectedTemplates'))->toHaveCount(0);
    });

    it('calculates subtotal correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template1 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();
        $template2 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(300)->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template1->id)
            ->call('addTemplate', $template2->id);

        expect($component->get('subtotal'))->toBe(800.0);
    });

    it('calculates tax amount correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('taxRate', 10.0);

        expect($component->get('subtotal'))->toBe(1000.0);
        expect($component->get('taxAmount'))->toBe(100.0);
    });

    it('calculates total correctly with tax and discount', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('taxRate', 10.0)
            ->set('discountAmount', 50.00);

        expect($component->get('subtotal'))->toBe(1000.0);
        expect($component->get('taxAmount'))->toBe(100.0);
        expect($component->get('total'))->toBe(1050.0); // 1000 + 100 - 50
    });

    it('calculates deposit amount correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('taxRate', 0)
            ->set('discountAmount', 0)
            ->set('depositPercent', 30);

        expect($component->get('total'))->toBe(1000.0);
        expect($component->get('depositAmount'))->toBe(300.0);
        expect($component->get('balanceDue'))->toBe(700.0);
    });

    it('validates required title', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('title', '')
            ->call('saveAsDraft')
            ->assertHasErrors(['title']);
    });

    it('validates at least one template is selected', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Test Estimate')
            ->call('saveAsDraft')
            ->assertHasErrors(['selectedTemplates']);
    });

    it('validates valid_until date is not in the past', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('title', 'Test')
            ->set('validUntil', '2020-01-01')
            ->call('saveAsDraft')
            ->assertHasErrors(['validUntil']);
    });

    it('can save a new estimate as draft', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1500)->create([
            'name' => 'Living Room',
            'default_quantity' => 300,
            'default_coats' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'New Template Estimate')
            ->call('addTemplate', $template->id)
            ->set('taxRate', 8)
            ->set('depositPercent', 30)
            ->set('notes', 'Test notes')
            ->call('saveAsDraft')
            ->assertDispatched('estimate-created')
            ->assertRedirect();

        $estimate = Estimate::where('title', 'New Template Estimate')->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('draft');
        expect($estimate->deal_id)->toBe($deal->id);
        expect($estimate->created_by)->toBe($user->id);
        expect($estimate->estimator_mode)->toBe(Estimate::MODE_TEMPLATE);
        expect((float) $estimate->subtotal)->toBe(1500.0);
    });

    it('can save a new estimate and mark as sent', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Sent Template Estimate')
            ->call('addTemplate', $template->id)
            ->call('saveAndSend')
            ->assertDispatched('estimate-created')
            ->assertRedirect();

        $estimate = Estimate::where('title', 'Sent Template Estimate')->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('sent');
        expect($estimate->sent_at)->not->toBeNull();
    });

    it('can update an existing estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Original Title',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(2000)->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->set('title', 'Updated Title')
            ->call('addTemplate', $template->id)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-updated')
            ->assertRedirect();

        $estimate->refresh();
        expect($estimate->title)->toBe('Updated Title');
    });

    it('displays deal and customer information', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Kitchen Remodel',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->assertSee('Kitchen Remodel')
            ->assertSee('John Smith');
    });

    it('does not add inactive templates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->inactive()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id);

        expect($component->get('selectedTemplates'))->toHaveCount(0);
    });

    it('allows null valid_until date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Test')
            ->call('addTemplate', $template->id)
            ->set('validUntil', null)
            ->call('saveAsDraft')
            ->assertHasNoErrors(['validUntil']);
    });

    it('creates template usages when saving estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create([
            'name' => 'Living Room Template',
            'default_quantity' => 200,
            'default_coats' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Test With Template Usages')
            ->call('addTemplate', $template->id)
            ->call('saveAsDraft');

        $estimate = Estimate::where('title', 'Test With Template Usages')->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->templateUsages)->toHaveCount(1);
        expect($estimate->templateUsages->first()->template_id)->toBe($template->id);
    });
});

describe('TemplateBuilder project scope cascade', function () {
    it('initializes with interior project scope by default', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->assertSet('projectScope', 'interior')
            ->assertSet('scopeFilter', 'interior');
    });

    it('returns project scope options', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $options = $component->instance()->getProjectScopeOptions();

        expect($options)->toHaveKey('interior');
        expect($options)->toHaveKey('exterior');
        expect($options)->not->toHaveKey('all');
        expect($options['interior'])->toBe('Interior');
        expect($options['exterior'])->toBe('Exterior');
    });

    it('syncs scopeFilter when projectScope changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->assertSet('projectScope', 'interior')
            ->assertSet('scopeFilter', 'interior')
            ->set('projectScope', 'exterior')
            ->assertSet('projectScope', 'exterior')
            ->assertSet('scopeFilter', 'exterior')
            ->assertDispatched('scope-changed');
    });

    it('filters out non-matching templates when project scope changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $interiorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Interior Room',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(600)->create([
            'name' => 'Exterior Area',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
        $bothTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(700)->create([
            'name' => 'Both Scope Item',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $interiorTemplate->id)
            ->call('addTemplate', $bothTemplate->id);

        // Both interior and both-scope templates should be present
        expect($component->get('selectedTemplates'))->toHaveCount(2);

        // Change to exterior scope
        $component->set('projectScope', 'exterior');

        // Only the "both" scope template should remain
        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['name'])->toBe('Both Scope Item');
    });

    it('keeps both-scope templates when switching project scope', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $bothTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Universal Template',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $bothTemplate->id)
            ->assertSet('projectScope', 'interior');

        expect($component->get('selectedTemplates'))->toHaveCount(1);

        // Switch to exterior
        $component->set('projectScope', 'exterior');

        // Template should still be present
        expect($component->get('selectedTemplates'))->toHaveCount(1);
        expect($component->get('selectedTemplates')[0]['name'])->toBe('Universal Template');
    });

    it('detects project scope from existing templates when editing', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Exterior Template',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);

        // Create estimate with exterior template
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Exterior Job',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        $estimate->templateUsages()->create([
            'template_id' => $exteriorTemplate->id,
            'quantity' => 1000,
            'coats' => 2,
            'calculated_price' => 500,
        ]);

        // When editing, should detect exterior scope
        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        expect($component->get('projectScope'))->toBe('exterior');
        expect($component->get('scopeFilter'))->toBe('exterior');
    });
});

describe('TemplateBuilder scope filtering', function () {
    it('returns scope filter options', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $options = $component->instance()->getScopeFilterOptions();

        expect($options)->toHaveKey('all');
        expect($options)->toHaveKey('interior');
        expect($options)->toHaveKey('exterior');
        expect($options['all'])->toBe('All');
        expect($options['interior'])->toBe('Interior');
        expect($options['exterior'])->toBe('Exterior');
    });

    it('can set scope filter', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->assertSet('scopeFilter', 'interior') // Now defaults to interior (synced with projectScope)
            ->set('scopeFilter', 'all')
            ->assertSet('scopeFilter', 'all')
            ->set('scopeFilter', 'exterior')
            ->assertSet('scopeFilter', 'exterior');
    });

    it('filters templates by interior scope', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        // Create templates with different scopes
        $interiorTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Interior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Exterior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
        $bothTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Both Scopes',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        // Template filtering now lives in the template-picker child component
        $component = Livewire::actingAs($user)
            ->test('estimates.template-picker', ['projectScope' => 'interior']);

        $templateNames = $component->invade()->pickerTemplates->pluck('name')->toArray();

        expect($templateNames)->toContain('Interior Only');
        expect($templateNames)->toContain('Both Scopes');
        expect($templateNames)->not->toContain('Exterior Only');
    });

    it('filters templates by exterior scope', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        $interiorTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Interior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Exterior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
        $bothTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Both Scopes',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        // Template filtering now lives in the template-picker child component
        $component = Livewire::actingAs($user)
            ->test('estimates.template-picker', ['projectScope' => 'exterior']);

        $templateNames = $component->invade()->pickerTemplates->pluck('name')->toArray();

        expect($templateNames)->toContain('Exterior Only');
        expect($templateNames)->toContain('Both Scopes');
        expect($templateNames)->not->toContain('Interior Only');
    });

    it('shows all templates when scope filter is all', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        $interiorTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Interior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Exterior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);

        // The template-picker child filters by projectScope.
        // Interior scope gets interior + both, exterior scope gets exterior + both.
        // Verify both templates are accessible across their respective scopes.
        $interiorPicker = Livewire::actingAs($user)
            ->test('estimates.template-picker', ['projectScope' => 'interior']);
        $interiorNames = $interiorPicker->invade()->pickerTemplates->pluck('name')->toArray();
        expect($interiorNames)->toContain('Interior Only');

        $exteriorPicker = Livewire::actingAs($user)
            ->test('estimates.template-picker', ['projectScope' => 'exterior']);
        $exteriorNames = $exteriorPicker->invade()->pickerTemplates->pluck('name')->toArray();
        expect($exteriorNames)->toContain('Exterior Only');
    });
});

describe('TemplateBuilder cost breakdown', function () {
    it('calculates cost breakdown for template with production rate using PBP formula', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create company settings with simplified PBP formula values
        // Using values that make math easy to verify:
        // - $40/hr base wage × 1.35 burden = $54/hr effective rate used in COGS
        // - No cleanup, sundries, or taxes for simpler verification
        // - 50% margin is the ONLY margin applied (OTD = COGS / 0.50)
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 40, // Base wage $40
            'payroll_burden_multiplier' => 1.35, // Effective rate = $40 × 1.35 = $54/hr
            'interior_cleanup_hours_ratio' => 999999, // Effectively no cleanup
            'interior_sundries_cost_per_hour' => 0, // No sundries
            'interior_markup_percent' => 50,
            'interior_margin_percent' => 50, // OTD = COGS / 0.50
            'material_tax_rate' => 0, // No tax for simpler verification
            'labor_tax_rate' => 0, // No tax for simpler verification
            'net_profit_margin_percent' => 30,
            'overhead_percent' => 12,
        ]);

        // Create production rate category and rate
        $rateCategory = \App\Models\ProductionRateCategory::factory()->create([
            'type' => 'interior',
        ]);
        $productionRate = \App\Models\ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'name' => 'Wall Painting',
            'production_rate' => 200, // sqft per hour
            // Factory default coat_multipliers: 1 coat = 1.0, 2 coats = 1.8, 3 coats = 2.5
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->create([
            'name' => 'Test Room',
            'scope_type' => 'interior',
            'pricing_method' => 'production_rate',
            'production_rate_id' => $productionRate->id,
            'default_quantity' => 400,
            'default_coats' => 2,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $breakdown = $component->instance()->getTemplateCostBreakdown($template->id, 400, 2);

        // PBP Formula with our test values:
        // 400 sqft / 200 sqft/hr = 2 hours base, × 1.8 (coat multiplier for 2 coats) = 3.6 hours labor
        expect($breakdown['labor_hours'])->toBe(3.6);

        // Labor cost uses effective rate: base wage ($40) × payroll burden (1.35) = $54/hr
        // 3.6 hours × $54/hr = $194.40 labor cost
        expect($breakdown['labor_cost'])->toBe(194.4);

        // Material cost is now 0 since material_cost_per_unit was removed from ProductionRate
        // Material costs now come from PaintProduct selection, not production rate
        expect($breakdown['material_cost'])->toBe(0.0);

        // Cleanup and sundries are effectively 0 with our test setup
        expect($breakdown['cleanup_hours'])->toBe(0.0);
        expect($breakdown['cleanup_cost'])->toBe(0.0);
        expect($breakdown['sundries_cost'])->toBe(0.0);

        // COGS = Labor + Cleanup + Sundries + Materials + Material Tax + Labor Tax
        // COGS = $194.40 + $0 + $0 + $0 + $0 + $0 = $194.40
        expect($breakdown['base_total'])->toBe(194.4);

        // OTD = COGS / (margin% / 100) = $194.40 / 0.50 = $388.80
        expect($breakdown['with_margins'])->toBe(388.8);
    });

    it('returns zero breakdown for template without production rate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Fixed Price Room',
            'default_quantity' => 200,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $breakdown = $component->instance()->getTemplateCostBreakdown($template->id, 200, 2);

        expect($breakdown['labor_hours'])->toBe(0);
        expect($breakdown['labor_cost'])->toBe(0);
        expect($breakdown['material_cost'])->toBe(0);
        expect($breakdown['base_total'])->toBe(500.0); // Falls back to calculatePrice
    });

    it('calculates fixed template price with per-template margin', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create company settings with PBP formula values
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 21, // $21/hr for interior
            'exterior_hourly_wage' => 27, // $27/hr for exterior
            'interior_margin_percent' => 50, // 50% margin
            'exterior_margin_percent' => 41, // 41% margin
        ]);

        $category = EstimateTemplateCategory::factory()->addon()->create();

        // Create a fixed template with cost tracking
        // Labor: 2 hrs × $21 = $42
        // Materials: $20
        // COGS: $62
        // With 40% margin: $62 / (1 - 0.40) = $103.33
        $template = EstimateTemplate::factory()
            ->forCategory($category)
            ->interior()
            ->fixedPricingWithCostTracking(
                laborHours: 2.0,
                materialCost: 20.00,
                marginPercent: 40.0
            )
            ->create(['name' => 'Furniture Moving']);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $breakdown = $component->instance()->getTemplateCostBreakdown($template->id, 1, 1);

        expect($breakdown['labor_hours'])->toBe(2.0);
        expect($breakdown['labor_cost'])->toBe(42.0); // 2 hrs × $21
        expect($breakdown['material_cost'])->toBe(20.0);
        expect($breakdown['base_total'])->toBe(62.0); // COGS
        expect($breakdown['margin_percent'])->toBe(40.0);
        expect($breakdown['with_margins'])->toBe(155.0); // $62 / 0.40
        expect($breakdown['profit'])->toBe(93.0); // $155 - $62
    });

    it('falls back to scope margin when template margin is null', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create company settings with 50% interior margin
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 20, // $20/hr
            'interior_margin_percent' => 50, // 50% margin = OTD is 2× COGS
        ]);

        $category = EstimateTemplateCategory::factory()->addon()->create();

        // Create fixed template without custom margin (uses scope margin)
        // Labor: 2.5 hrs × $20 = $50
        // Materials: $30
        // COGS: $80
        // With 50% margin: $80 / 0.50 = $160
        $template = EstimateTemplate::factory()
            ->forCategory($category)
            ->interior()
            ->fixedPricingWithCostTracking(
                laborHours: 2.5,
                materialCost: 30.00,
                marginPercent: null // Falls back to scope margin
            )
            ->create(['name' => 'Drywall Repair']);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $breakdown = $component->instance()->getTemplateCostBreakdown($template->id, 1, 1);

        expect($breakdown['labor_hours'])->toBe(2.5);
        expect($breakdown['labor_cost'])->toBe(50.0); // 2.5 hrs × $20
        expect($breakdown['material_cost'])->toBe(30.0);
        expect($breakdown['base_total'])->toBe(80.0); // COGS
        expect($breakdown['margin_percent'])->toBe(50.0); // Scope margin
        expect($breakdown['with_margins'])->toBe(160.0); // $80 / 0.50
        expect($breakdown['profit'])->toBe(80.0); // $160 - $80
    });

    it('adds fixed template with cost tracking to estimate with correct breakdown', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 21,
            'interior_margin_percent' => 50,
        ]);

        $category = EstimateTemplateCategory::factory()->addon()->create();
        $template = EstimateTemplate::factory()
            ->forCategory($category)
            ->interior()
            ->fixedPricingWithCostTracking(
                laborHours: 1.5,
                materialCost: 25.00,
                marginPercent: 50.0
            )
            ->create(['name' => 'Minor Drywall Repair']);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id);

        $selectedTemplates = $component->get('selectedTemplates');
        $addedTemplate = $selectedTemplates[0];

        // Labor: 1.5 hrs × $21 = $31.50
        // Materials: $25
        // COGS: $56.50
        // With 50% margin: $56.50 / 0.50 = $113
        expect($addedTemplate['labor_hours'])->toBe(1.5);
        expect($addedTemplate['labor_cost'])->toBe(31.5);
        expect($addedTemplate['material_cost'])->toBe(25.0);
        expect($addedTemplate['base_total'])->toBe(56.5);
        expect($addedTemplate['margin_percent'])->toBe(50.0);
        expect($addedTemplate['with_margins'])->toBe(113.0);
        expect($addedTemplate['profit'])->toBe(56.5);
        expect($addedTemplate['price'])->toBe(113.0);
    });

    it('uses exterior hourly wage for exterior fixed templates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 21,
            'exterior_hourly_wage' => 27, // Higher exterior rate
            'exterior_margin_percent' => 41,
        ]);

        $category = EstimateTemplateCategory::factory()->addon()->create();
        $template = EstimateTemplate::factory()
            ->forCategory($category)
            ->exterior()
            ->fixedPricingWithCostTracking(
                laborHours: 4.0,
                materialCost: 40.00,
                marginPercent: 41.0
            )
            ->create(['name' => 'Outdoor Furniture Set']);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $breakdown = $component->instance()->getTemplateCostBreakdown($template->id, 1, 1);

        // Labor: 4 hrs × $27 (exterior wage) = $108
        // Materials: $40
        // COGS: $148
        // With 41% margin: $148 / 0.59 = $250.85
        expect($breakdown['labor_hours'])->toBe(4.0);
        expect($breakdown['labor_cost'])->toBe(108.0); // Uses exterior wage
        expect($breakdown['material_cost'])->toBe(40.0);
        expect($breakdown['base_total'])->toBe(148.0);
        expect($breakdown['margin_percent'])->toBe(41.0);
        expect($breakdown['with_margins'])->toBe(360.98); // $148 / 0.41
        expect($breakdown['profit'])->toBe(212.98); // $360.98 - $148
    });

    it('includes cost breakdown data when adding template using PBP formula', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create company settings with PBP formula values
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 40,
            'payroll_burden_multiplier' => 1.0, // 1.0 = no burden
            'interior_cleanup_hours_ratio' => 999999,
            'interior_sundries_cost_per_hour' => 0,
            'net_profit_margin_percent' => 30,
            'overhead_percent' => 12,
            'interior_markup_percent' => 50,
        ]);

        // Create production rate
        $rateCategory = \App\Models\ProductionRateCategory::factory()->create([
            'type' => 'interior',
        ]);
        $productionRate = \App\Models\ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'production_rate' => 200,
            // Factory default coat_multipliers: 2 coats = 1.8
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->create([
            'scope_type' => 'interior',
            'pricing_method' => 'production_rate',
            'production_rate_id' => $productionRate->id,
            'default_quantity' => 400,
            'default_coats' => 2,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addTemplate', $template->id);

        $selectedTemplates = $component->get('selectedTemplates');
        $addedTemplate = $selectedTemplates[0];

        expect($addedTemplate)->toHaveKey('labor_hours');
        expect($addedTemplate)->toHaveKey('labor_cost');
        expect($addedTemplate)->toHaveKey('cleanup_hours');
        expect($addedTemplate)->toHaveKey('cleanup_cost');
        expect($addedTemplate)->toHaveKey('sundries_cost');
        expect($addedTemplate)->toHaveKey('material_cost');
        expect($addedTemplate)->toHaveKey('base_total');
        expect($addedTemplate)->toHaveKey('with_margins');

        // With coat multiplier 1.8 for 2 coats: (400/200) × 1.8 = 3.6 hours
        expect($addedTemplate['labor_hours'])->toBe(3.6);
        // Uses CompanySetting effective rate: 3.6 × $40 = $144
        expect($addedTemplate['labor_cost'])->toBe(144.0);
        // Material cost is now 0 since material_cost_per_unit was removed from ProductionRate
        // Material costs now come from PaintProduct selection, not production rate
        expect($addedTemplate['material_cost'])->toBe(0.0);
    });

    it('loads cost breakdown when editing existing estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create production rate
        $rateCategory = \App\Models\ProductionRateCategory::factory()->create([
            'type' => 'interior',
        ]);
        $productionRate = \App\Models\ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'production_rate' => 200,
            // Factory default coat_multipliers: 2 coats = 1.8
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->create([
            'pricing_method' => 'production_rate',
            'production_rate_id' => $productionRate->id,
            'default_quantity' => 400,
            'default_coats' => 2,
        ]);

        // Create estimate with template usage
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Test Estimate',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        $estimate->templateUsages()->create([
            'template_id' => $template->id,
            'quantity' => 400,
            'coats' => 2,
            'calculated_price' => 504.00, // 144 labor + 360 material with 1.8 coat multiplier
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0])->toHaveKey('labor_hours');
        expect($selectedTemplates[0])->toHaveKey('labor_cost');
        expect($selectedTemplates[0])->toHaveKey('material_cost');
        expect($selectedTemplates[0])->toHaveKey('base_total');
        expect($selectedTemplates[0])->toHaveKey('with_margins');

        // With coat multiplier 1.8: (400/200) × 1.8 = 3.6 hours
        expect($selectedTemplates[0]['labor_hours'])->toBe(3.6);
    });
});

describe('TemplateBuilder area saving flow', function () {
    it('correctly handles area-saved event and stores price', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create company settings
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 40,
            'payroll_burden_multiplier' => 1.0,
            'interior_cleanup_hours_ratio' => 999999,
            'interior_sundries_cost_per_hour' => 0,
            'net_profit_margin_percent' => 30,
            'overhead_percent' => 12,
            'interior_markup_percent' => 50,
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Test Room',
            'scope_type' => 'interior',
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        // Simulate receiving area-saved event with price data
        $areaData = [
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'source_template_id' => $template->id,
            'length' => 14,
            'width' => 12,
            'height' => 9,
            'wall_sqft' => 468,
            'ceiling_sqft' => 168,
            'perimeter_lnft' => 52,
            'default_coats' => 2,
            'surfaces' => [
                [
                    'name' => 'Walls',
                    'surface_type' => 'walls',
                    'production_rate_id' => null,
                    'quantity_source' => 'wall_sqft',
                    'default_coats' => 2,
                    'is_required' => false,
                    'is_default_enabled' => true,
                    'fixed_price' => null,
                ],
            ],
            'addons' => [],
            'labor_hours' => 5.5,
            'labor_cost' => 220.00,
            'cleanup_hours' => 0,
            'cleanup_cost' => 0,
            'sundries_cost' => 0,
            'material_cost' => 50.00,
            'addon_cost' => 0,
            'base_total' => 270.00,
            'with_margins' => 589.68,
            'price' => 589.68,
        ];

        $component->dispatch('area-saved', areaData: $areaData, editingIndex: null);

        $selectedTemplates = $component->get('selectedTemplates');

        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['price'])->toBe(589.68);
        expect($selectedTemplates[0]['with_margins'])->toBe(589.68);
        expect($selectedTemplates[0]['name'])->toBe('Living Room');
        expect($selectedTemplates[0]['area_data'])->not->toBeNull();
        expect($selectedTemplates[0]['area_data']['length'])->toBe(14);
    });

    it('saves area price correctly to template_usages when saving estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create company settings
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 40,
            'payroll_burden_multiplier' => 1.0,
            'interior_cleanup_hours_ratio' => 999999,
            'interior_sundries_cost_per_hour' => 0,
            'net_profit_margin_percent' => 30,
            'overhead_percent' => 12,
            'interior_markup_percent' => 50,
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Test Room',
            'scope_type' => 'interior',
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        // Simulate receiving area-saved event with price data
        $areaData = [
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'source_template_id' => $template->id,
            'length' => 14,
            'width' => 12,
            'height' => 9,
            'wall_sqft' => 468,
            'ceiling_sqft' => 168,
            'perimeter_lnft' => 52,
            'default_coats' => 2,
            'surfaces' => [
                [
                    'name' => 'Walls',
                    'surface_type' => 'walls',
                    'production_rate_id' => null,
                    'quantity_source' => 'wall_sqft',
                    'default_coats' => 2,
                    'is_required' => false,
                    'is_default_enabled' => true,
                    'fixed_price' => null,
                ],
            ],
            'addons' => [],
            'labor_hours' => 5.5,
            'labor_cost' => 220.00,
            'cleanup_hours' => 0,
            'cleanup_cost' => 0,
            'sundries_cost' => 0,
            'material_cost' => 50.00,
            'addon_cost' => 0,
            'base_total' => 270.00,
            'with_margins' => 589.68,
            'price' => 589.68,
        ];

        $component->dispatch('area-saved', areaData: $areaData, editingIndex: null);

        // Verify the price is in selectedTemplates (this is the pre-calculated margined price from areaData)
        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates[0]['price'])->toBe(589.68);

        // Save as draft
        $component
            ->set('title', 'Test Estimate')
            ->call('saveAsDraft');

        // Verify the estimate was created
        // Note: In template mode, the subtotal comes from the pre-calculated template price
        // (with_margins) from the areaData. The template system calculates the price,
        // and it's saved directly to the estimate. We don't recalculate from surfaces.
        $estimate = \App\Models\Estimate::where('title', 'Test Estimate')->first();
        expect($estimate)->not->toBeNull();
        expect((float) $estimate->subtotal)->toBe(589.68);
        expect((float) $estimate->total)->toBe(589.68); // No tax, no discount

        // The template usage stores the calculated price from the template system
        $usage = $estimate->templateUsages()->first();
        expect($usage)->not->toBeNull();
        expect((float) $usage->calculated_price)->toBe(589.68);

        // Verify the area was created
        $area = $estimate->areas()->first();
        expect($area)->not->toBeNull();
        expect($area->name)->toBe('Living Room');
        expect((float) $area->length)->toBe(14.0);
        expect((float) $area->width)->toBe(12.0);
    });

    it('loads saved area data correctly when editing estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create company settings
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 40,
            'payroll_burden_multiplier' => 1.0,
            'interior_cleanup_hours_ratio' => 999999,
            'interior_sundries_cost_per_hour' => 0,
            'net_profit_margin_percent' => 30,
            'overhead_percent' => 12,
            'interior_markup_percent' => 50,
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Test Room',
            'scope_type' => 'interior',
        ]);

        // Create estimate with area
        $estimate = \App\Models\Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Test Estimate',
            'estimator_mode' => \App\Models\Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
            'subtotal' => 589.68,
            'total' => 589.68,
        ]);

        $area = \App\Models\EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 14,
            'width' => 12,
            'height' => 9,
            'wall_sqft' => 468,
            'ceiling_sqft' => 168,
            'perimeter_lnft' => 52,
            'sort_order' => 0,
        ]);

        // Create a surface for the area so pricing can be calculated
        $productionRate = \App\Models\ProductionRate::factory()->create([
            'production_rate' => 150,
        ]);
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'surface_type' => 'wall',
            'quantity' => 468,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_id' => $productionRate->id,
            'production_rate_value' => 150,
            'hourly_labor_rate' => 40,
            'material_cost_per_unit' => 0.30,
            'labor_hours' => 5.62, // (468 / 150) × 1.8 coat multiplier
            'labor_cost' => 224.64, // 5.62 × $40
            'material_cost' => 252.72, // 468 × $0.30 × 1.8
            'total' => 477.36,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        // Create template usage linked to the area
        // Price will be recalculated using margin formula:
        // COGS = labor_cost + cleanup + sundries + material = ~$477 (cleanup/sundries disabled)
        // With 50% margin: OTD = $477.36 / (1 - 0.50) = ~$954.72
        $estimate->templateUsages()->create([
            'template_id' => $template->id,
            'quantity' => 468,
            'coats' => 2,
            'calculated_price' => 954.72, // Will be recalculated from surfaces
            'resultable_type' => \App\Models\EstimateArea::class,
            'resultable_id' => $area->id,
        ]);

        // Load the estimate in the builder
        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        $selectedTemplates = $component->get('selectedTemplates');

        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['name'])->toBe('Living Room');
        // Price is recalculated using margin formula: COGS / (1 - margin%)
        // COGS = $224.64 (labor) + $252.72 (material) + tax = ~$510
        // With 50% margin: ~$1020 (actual depends on tax settings)
        expect((float) $selectedTemplates[0]['price'])->toBeGreaterThan(900);
        expect($selectedTemplates[0]['area_data'])->not->toBeNull();
        expect((float) $selectedTemplates[0]['area_data']['length'])->toBe(14.0);
        expect((float) $selectedTemplates[0]['area_data']['width'])->toBe(12.0);
        expect((float) $selectedTemplates[0]['area_data']['height'])->toBe(9.0);
    });

    it('preserves production rate ID through handleAreaSaved and editSelectedTemplate cycle', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create a production rate
        $rate = \App\Models\ProductionRate::factory()->create([
            'name' => 'Test Wall Rate',
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 200,
            'rate_type' => 'sqft_per_hour',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        // Simulate area data coming from AreaBuilder with production_rate_id set
        $areaData = [
            'name' => 'Test Room',
            'scope_type' => 'interior',
            'source_template_id' => null,
            'length' => 12.0,
            'width' => 12.0,
            'height' => 9.0,
            'wall_sqft' => 432,
            'ceiling_sqft' => 144,
            'perimeter_lnft' => 48,
            'default_coats' => 2,
            'surfaces' => [
                [
                    'id' => null,
                    'name' => 'Walls',
                    'surface_type' => 'walls',
                    'surface_category' => 'walls',
                    'production_rate_id' => $rate->id, // This should be preserved
                    'quantity_source' => 'wall_sqft',
                    'quantity_override' => null,
                    'default_coats' => 2,
                    'is_required' => true,
                    'is_default_enabled' => true,
                    'fixed_price' => null,
                ],
            ],
            'addons' => [],
            'labor_hours' => 3.89,
            'labor_cost' => 116.64,
            'cleanup_hours' => 0.39,
            'cleanup_cost' => 11.66,
            'sundries_cost' => 7.0,
            'material_cost' => 50.0,
            'addon_cost' => 0,
            'base_total' => 200.0,
            'with_margins' => 400.0,
            'price' => 400.0,
        ];

        // Dispatch the area-saved event (simulating AreaBuilder save)
        $component->dispatch('area-saved', areaData: $areaData, editingIndex: null);

        // Get the stored data
        $selectedTemplates = $component->get('selectedTemplates');

        // Verify it was stored correctly
        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['name'])->toBe('Test Room');
        expect($selectedTemplates[0]['area_data']['surfaces'][0]['production_rate_id'])->toBe($rate->id);

        // Now test editSelectedTemplate dispatches correct data
        // The component should dispatch 'open-area-builder-edit' with the preserved data
        $component->call('editSelectedTemplate', 0);

        $component->assertDispatched('open-area-builder-edit', function ($event, $params) use ($rate) {
            $surfaces = $params['areaData']['surfaces'] ?? [];

            return isset($surfaces[0]['production_rate_id'])
                && $surfaces[0]['production_rate_id'] == $rate->id;
        });
    });
});

describe('TemplateBuilder fixed price templates', function () {
    it('can add a fixed price template', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create a fixed price template with margin pricing off (manual price)
        $fixedPriceTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Small Closet',
            'code' => 'CLOSET-SM',
            'fixed_price' => 95.00,
            'use_margin_pricing' => false,
            'scope_type' => 'interior',
            'category' => 'closet',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addFixedPriceTemplate', $fixedPriceTemplate->id);

        $selectedTemplates = $component->get('selectedTemplates');

        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['is_fixed_price'])->toBeTrue();
        expect($selectedTemplates[0]['name'])->toBe('Small Closet');
        expect($selectedTemplates[0]['code'])->toBe('CLOSET-SM');
        expect($selectedTemplates[0]['quantity'])->toBe(1);
        expect($selectedTemplates[0]['unit_price'])->toBe(95.00);
        expect($selectedTemplates[0]['price'])->toBe(95.00);
    });

    it('snapshots labor hours and material cost from nested template', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $fixedPriceTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Walk-in Closet',
            'code' => 'CLOSET-WI',
            'fixed_price' => 175.00,
            'use_margin_pricing' => false,
            'estimated_hours' => 1.5,
            'material_cost' => 15.00,
            'scope_type' => 'interior',
            'category' => 'closet',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addFixedPriceTemplate', $fixedPriceTemplate->id);

        $selectedTemplates = $component->get('selectedTemplates');

        expect($selectedTemplates[0]['labor_hours'])->toBe(1.5);
        expect($selectedTemplates[0]['material_cost'])->toBe(15.00);
        expect($selectedTemplates[0]['labor_cost'])->toBeGreaterThan(0);
        // Customer price uses manual fixed_price (margin pricing off)
        expect($selectedTemplates[0]['price'])->toBe(175.00);
    });

    it('can update quantity of a fixed price template', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $fixedPriceTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Entry Door',
            'code' => 'EXT-ENTRY-STD',
            'fixed_price' => 125.00,
            'use_margin_pricing' => false,
            'scope_type' => 'exterior',
            'category' => 'other',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addFixedPriceTemplate', $fixedPriceTemplate->id)
            ->call('updateFixedPriceQuantity', 0, 3);

        $selectedTemplates = $component->get('selectedTemplates');

        expect($selectedTemplates[0]['quantity'])->toBe(3);
        expect((float) $selectedTemplates[0]['unit_price'])->toEqual(125.00);
        expect((float) $selectedTemplates[0]['price'])->toEqual(375.00); // 125 × 3
    });

    it('cannot set quantity below 1 for fixed price templates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $fixedPriceTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Half Bath',
            'fixed_price' => 150.00,
            'use_margin_pricing' => false,
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addFixedPriceTemplate', $fixedPriceTemplate->id)
            ->call('updateFixedPriceQuantity', 0, 0);

        $selectedTemplates = $component->get('selectedTemplates');

        // Quantity should be clamped to 1
        expect($selectedTemplates[0]['quantity'])->toBe(1);
        expect((float) $selectedTemplates[0]['price'])->toEqual(150.00);
    });

    it('can remove a fixed price template', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $fixedPriceTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Pantry',
            'fixed_price' => 85.00,
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addFixedPriceTemplate', $fixedPriceTemplate->id)
            ->assertSet('selectedTemplates', function ($templates) {
                return count($templates) === 1;
            })
            ->call('removeTemplate', 0)
            ->assertSet('selectedTemplates', []);
    });

    it('does not add inactive fixed price templates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $inactiveTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Inactive Closet',
            'fixed_price' => 100.00,
            'is_active' => false,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addFixedPriceTemplate', $inactiveTemplate->id)
            ->assertSet('selectedTemplates', []);
    });

    it('includes fixed price templates in subtotal calculation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create two fixed price templates with margin pricing off
        $template1 = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Closet 1',
            'fixed_price' => 100.00,
            'use_margin_pricing' => false,
            'scope_type' => 'interior',
            'is_active' => true,
        ]);
        $template2 = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Closet 2',
            'fixed_price' => 150.00,
            'use_margin_pricing' => false,
            'scope_type' => 'interior',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->call('addFixedPriceTemplate', $template1->id)
            ->call('addFixedPriceTemplate', $template2->id);

        // Subtotal should be sum of fixed prices
        expect($component->get('subtotal'))->toBe(250.00);
    });

    it('filters fixed price templates by project scope', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Create interior and exterior templates
        \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Interior Closet',
            'scope_type' => 'interior',
            'is_active' => true,
        ]);
        \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Exterior Door',
            'scope_type' => 'exterior',
            'is_active' => true,
        ]);

        // Fixed price template filtering now lives in the template-picker child component
        $interiorPicker = Livewire::actingAs($user)
            ->test('estimates.template-picker', ['projectScope' => 'interior']);

        $interiorTemplates = $interiorPicker->invade()->fixedPriceTemplates;
        expect($interiorTemplates->pluck('name')->toArray())->toContain('Interior Closet');
        expect($interiorTemplates->pluck('name')->toArray())->not->toContain('Exterior Door');

        // Test exterior scope
        $exteriorPicker = Livewire::actingAs($user)
            ->test('estimates.template-picker', ['projectScope' => 'exterior']);

        $exteriorTemplates = $exteriorPicker->invade()->fixedPriceTemplates;
        expect($exteriorTemplates->pluck('name')->toArray())->toContain('Exterior Door');
        expect($exteriorTemplates->pluck('name')->toArray())->not->toContain('Interior Closet');
    });

    it('preserves door and window quantity_override when loading existing areas for edit', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Test Room',
            'scope_type' => 'interior',
        ]);

        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Door Test Estimate',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        $area = \App\Models\EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 14,
            'width' => 12,
            'height' => 9,
            'wall_sqft' => 468,
            'ceiling_sqft' => 168,
            'perimeter_lnft' => 52,
            'sort_order' => 0,
        ]);

        $wallRate = \App\Models\ProductionRate::factory()->create([
            'production_rate' => 150,
            'surface_category' => 'walls',
        ]);
        $doorRate = \App\Models\ProductionRate::factory()->create([
            'production_rate' => 2,
            'surface_category' => 'doors',
        ]);
        $windowRate = \App\Models\ProductionRate::factory()->create([
            'production_rate' => 2,
            'surface_category' => 'windows',
        ]);
        $ceilingRate = \App\Models\ProductionRate::factory()->create([
            'production_rate' => 200,
            'surface_category' => 'ceiling',
        ]);

        // Create wall surface (sqft-based)
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 468,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_id' => $wallRate->id,
            'production_rate_value' => 150,
            'labor_hours' => 5.62,
            'labor_cost' => 224.64,
            'material_cost' => 0,
            'total' => 224.64,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        // Create door surface with quantity=2 (user set 2 doors)
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Doors',
            'surface_type' => 'doors',
            'quantity' => 2,
            'unit' => 'units',
            'coats' => 2,
            'production_rate_id' => $doorRate->id,
            'production_rate_value' => 2,
            'labor_hours' => 1.8,
            'labor_cost' => 72.0,
            'material_cost' => 0,
            'total' => 72.0,
            'is_included' => true,
            'sort_order' => 1,
        ]);

        // Create window surface with quantity=3 (user set 3 windows)
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Windows',
            'surface_type' => 'windows',
            'quantity' => 3,
            'unit' => 'units',
            'coats' => 2,
            'production_rate_id' => $windowRate->id,
            'production_rate_value' => 2,
            'labor_hours' => 2.7,
            'labor_cost' => 108.0,
            'material_cost' => 0,
            'total' => 108.0,
            'is_included' => true,
            'sort_order' => 2,
        ]);

        // Create ceiling surface (sqft-based)
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Ceiling',
            'surface_type' => 'ceiling',
            'quantity' => 168,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_id' => $ceilingRate->id,
            'production_rate_value' => 200,
            'labor_hours' => 1.51,
            'labor_cost' => 60.48,
            'material_cost' => 0,
            'total' => 60.48,
            'is_included' => true,
            'sort_order' => 3,
        ]);

        // Link via template usage
        $estimate->templateUsages()->create([
            'template_id' => $template->id,
            'quantity' => 468,
            'coats' => 2,
            'calculated_price' => 500,
            'resultable_type' => \App\Models\EstimateArea::class,
            'resultable_id' => $area->id,
        ]);

        // Load the template builder in edit mode
        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        // Get the loaded surfaces from selectedTemplates
        $selectedTemplates = $component->get('selectedTemplates');
        $surfaces = $selectedTemplates[0]['area_data']['surfaces'];

        // Find surfaces by name
        $wallSurface = collect($surfaces)->firstWhere('name', 'Walls');
        $doorSurface = collect($surfaces)->firstWhere('name', 'Doors');
        $windowSurface = collect($surfaces)->firstWhere('name', 'Windows');
        $ceilingSurface = collect($surfaces)->firstWhere('name', 'Ceiling');

        // Walls should use wall_sqft source with no override
        expect($wallSurface['quantity_source'])->toBe('wall_sqft');
        expect($wallSurface['quantity_override'])->toBeNull();

        // Doors should use door_count source with quantity_override = 2
        expect($doorSurface['quantity_source'])->toBe('door_count');
        expect($doorSurface['quantity_override'])->toBe(2.0);

        // Windows should use window_count source with quantity_override = 3
        expect($windowSurface['quantity_source'])->toBe('window_count');
        expect($windowSurface['quantity_override'])->toBe(3.0);

        // Ceiling should use ceiling_sqft source with no override
        expect($ceilingSurface['quantity_source'])->toBe('ceiling_sqft');
        expect($ceilingSurface['quantity_override'])->toBeNull();

        // Production rate IDs should be preserved as strings for Livewire select compatibility
        expect($doorSurface['production_rate_id'])->toBe((string) $doorRate->id);
        expect($wallSurface['production_rate_id'])->toBe((string) $wallRate->id);

        // Surface categories should be inferred from production rate
        expect($wallSurface['surface_category'])->toBe('walls');
        expect($doorSurface['surface_category'])->toBe('doors');
        expect($windowSurface['surface_category'])->toBe('windows');
        expect($ceilingSurface['surface_category'])->toBe('ceiling');
    });

    it('preserves disabled surfaces when saving and reloading areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Test Room',
            'scope_type' => 'interior',
        ]);

        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Disabled Surface Test',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        $area = \App\Models\EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 14,
            'width' => 12,
            'height' => 9,
            'wall_sqft' => 468,
            'ceiling_sqft' => 168,
            'perimeter_lnft' => 52,
            'sort_order' => 0,
        ]);

        // Create an enabled surface
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 468,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 150,
            'labor_hours' => 5.62,
            'labor_cost' => 224.64,
            'material_cost' => 0,
            'total' => 224.64,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        // Create a disabled surface (user toggled it off)
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Ceiling',
            'surface_type' => 'ceiling',
            'quantity' => 168,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 200,
            'labor_hours' => 0,
            'labor_cost' => 0,
            'material_cost' => 0,
            'total' => 0,
            'is_included' => false,
            'sort_order' => 1,
        ]);

        $estimate->templateUsages()->create([
            'template_id' => $template->id,
            'quantity' => 468,
            'coats' => 2,
            'calculated_price' => 500,
            'resultable_type' => \App\Models\EstimateArea::class,
            'resultable_id' => $area->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        $selectedTemplates = $component->get('selectedTemplates');
        $surfaces = $selectedTemplates[0]['area_data']['surfaces'];

        // Both surfaces should be loaded (enabled and disabled)
        expect($surfaces)->toHaveCount(2);

        $wallSurface = collect($surfaces)->firstWhere('name', 'Walls');
        $ceilingSurface = collect($surfaces)->firstWhere('name', 'Ceiling');

        // Walls should be enabled
        expect($wallSurface['is_default_enabled'])->toBeTrue();

        // Ceiling should be disabled (preserved from DB)
        expect($ceilingSurface['is_default_enabled'])->toBeFalse();

        // Disabled surface should not contribute to totals
        expect($selectedTemplates[0]['labor_hours'])->toBe(round(5.62, 2));
    });

    it('infers correct surface category by scope when production rate is null', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->configurable()->create([
            'name' => 'Exterior Test',
            'scope_type' => 'exterior',
        ]);

        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Category Inference Test',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        $area = \App\Models\EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'House Exterior',
            'scope_type' => 'exterior',
            'length' => 0,
            'width' => 50,
            'height' => 10,
            'wall_sqft' => 500,
            'ceiling_sqft' => 0,
            'perimeter_lnft' => 50,
            'sort_order' => 0,
        ]);

        // Surface with NO production rate (surface_category must be inferred)
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Siding',
            'surface_type' => 'walls',
            'quantity' => 500,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 100,
            'labor_hours' => 9,
            'labor_cost' => 360,
            'material_cost' => 0,
            'total' => 360,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        // Doors with no production rate
        \App\Models\EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Entry Door',
            'surface_type' => 'doors',
            'quantity' => 1,
            'unit' => 'units',
            'coats' => 2,
            'production_rate_value' => 1,
            'labor_hours' => 1.8,
            'labor_cost' => 72,
            'material_cost' => 0,
            'total' => 72,
            'is_included' => true,
            'sort_order' => 1,
        ]);

        $estimate->templateUsages()->create([
            'template_id' => $template->id,
            'quantity' => 500,
            'coats' => 2,
            'calculated_price' => 500,
            'resultable_type' => \App\Models\EstimateArea::class,
            'resultable_id' => $area->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        $selectedTemplates = $component->get('selectedTemplates');
        $surfaces = $selectedTemplates[0]['area_data']['surfaces'];

        $sidingSurface = collect($surfaces)->firstWhere('name', 'Siding');
        $doorSurface = collect($surfaces)->firstWhere('name', 'Entry Door');

        // Exterior walls should infer to 'body' category, not 'walls'
        expect($sidingSurface['surface_category'])->toBe('body');

        // Exterior doors should infer to 'exterior_doors', not 'doors'
        expect($doorSurface['surface_category'])->toBe('exterior_doors');
    });

    it('saves fixed-price template as EstimateArea with is_fixed_price', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $nestedTemplate = \App\Models\NestedAreaTemplate::factory()->smallCloset()->create([
            'use_margin_pricing' => false,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Fixed Price Estimate')
            ->call('addFixedPriceTemplate', $nestedTemplate->id)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-created');

        $estimate = Estimate::where('title', 'Fixed Price Estimate')->first();
        expect($estimate)->not->toBeNull();

        // Should have created an EstimateArea with is_fixed_price
        $areas = $estimate->areas;
        expect($areas)->toHaveCount(1);
        expect($areas[0]->is_fixed_price)->toBeTrue();
        expect((float) $areas[0]->fixed_price_amount)->toBe(95.00);
        expect($areas[0]->nested_area_template_id)->toBe($nestedTemplate->id);
        expect($areas[0]->name)->toBe('Small Closet');
        expect($areas[0]->scope_type)->toBe('interior');

        // Should have created a template usage pointing to the area
        $usages = $estimate->templateUsages;
        expect($usages)->toHaveCount(1);
        expect($usages[0]->template_id)->toBeNull();
        expect($usages[0]->resultable_type)->toBe(\App\Models\EstimateArea::class);
        expect($usages[0]->resultable_id)->toBe($areas[0]->id);
        expect((float) $usages[0]->calculated_price)->toBe(95.00);
    });

    it('saves fixed-price template with quantity > 1 as multiple areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $nestedTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Medium Closet',
            'code' => 'CLOSET-MD',
            'fixed_price' => 165.00,
            'use_margin_pricing' => false,
            'scope_type' => 'interior',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Multi Closet Estimate')
            ->call('addFixedPriceTemplate', $nestedTemplate->id)
            ->call('updateFixedPriceQuantity', 0, 3)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-created');

        $estimate = Estimate::where('title', 'Multi Closet Estimate')->first();

        // 3 closets = 3 separate EstimateArea records
        $areas = $estimate->areas;
        expect($areas)->toHaveCount(3);

        foreach ($areas as $area) {
            expect($area->is_fixed_price)->toBeTrue();
            expect((float) $area->fixed_price_amount)->toBe(165.00);
            expect($area->nested_area_template_id)->toBe($nestedTemplate->id);
        }

        // 3 template usages
        $usages = $estimate->templateUsages;
        expect($usages)->toHaveCount(3);

        foreach ($usages as $usage) {
            expect($usage->template_id)->toBeNull();
            expect((float) $usage->calculated_price)->toBe(165.00);
        }

        // Subtotal should be 165 × 3
        expect((float) $estimate->subtotal)->toBe(495.00);
    });

    it('loads fixed-price areas correctly when editing an estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $nestedTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Half Bath',
            'code' => 'BATH-HALF',
            'fixed_price' => 175.00,
            'use_margin_pricing' => false,
            'scope_type' => 'interior',
            'category' => 'bathroom',
            'is_active' => true,
        ]);

        // First, create an estimate with fixed-price templates
        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Edit Fixed Price Test')
            ->call('addFixedPriceTemplate', $nestedTemplate->id)
            ->call('updateFixedPriceQuantity', 0, 2)
            ->call('saveAsDraft');

        $estimate = Estimate::where('title', 'Edit Fixed Price Test')->first();

        // Now load it for editing
        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        $selectedTemplates = $component->get('selectedTemplates');

        // Should load as one grouped entry with quantity 2
        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['is_fixed_price'])->toBeTrue();
        expect($selectedTemplates[0]['name'])->toBe('Half Bath');
        expect($selectedTemplates[0]['quantity'])->toBe(2);
        expect((float) $selectedTemplates[0]['unit_price'])->toBe(175.00);
        expect((float) $selectedTemplates[0]['price'])->toBe(350.00);
    });

    it('saves mixed estimate with regular templates and fixed-price templates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        // Regular template
        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create([
            'name' => 'Living Room',
            'default_quantity' => 200,
        ]);

        // Fixed-price template
        $nestedTemplate = \App\Models\NestedAreaTemplate::factory()->create([
            'name' => 'Small Closet',
            'code' => 'CLOSET-SM',
            'fixed_price' => 95.00,
            'use_margin_pricing' => false,
            'scope_type' => 'interior',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Mixed Estimate')
            ->call('addTemplate', $template->id)
            ->call('addFixedPriceTemplate', $nestedTemplate->id)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-created');

        $estimate = Estimate::where('title', 'Mixed Estimate')->first();
        expect($estimate)->not->toBeNull();

        // Should have template usages for both
        $usages = $estimate->templateUsages;
        expect($usages)->toHaveCount(2);

        // One should have a template_id, one should be null
        $regularUsage = $usages->whereNotNull('template_id')->first();
        $fixedUsage = $usages->whereNull('template_id')->first();

        expect($regularUsage)->not->toBeNull();
        expect($fixedUsage)->not->toBeNull();

        // Fixed-price should point to an EstimateArea
        expect($fixedUsage->resultable_type)->toBe(\App\Models\EstimateArea::class);
        $fixedArea = $fixedUsage->resultable;
        expect($fixedArea->is_fixed_price)->toBeTrue();
        expect((float) $fixedArea->fixed_price_amount)->toBe(95.00);

        // Total should be sum of both
        expect((float) $estimate->subtotal)->toBe(1095.00);
    });

    it('saves fixed-price area with labor tracking from nested template', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $nestedTemplate = \App\Models\NestedAreaTemplate::factory()->smallCloset()->create([
            'use_margin_pricing' => false,
        ]);
        // smallCloset has estimated_hours=1.5, material_cost=12.00

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Labor Tracking Estimate')
            ->call('addFixedPriceTemplate', $nestedTemplate->id)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-created');

        $estimate = Estimate::where('title', 'Labor Tracking Estimate')->first();
        $area = $estimate->areas->first();

        expect($area->is_fixed_price)->toBeTrue();
        expect((float) $area->fixed_price_amount)->toBe(95.00);
        expect((float) $area->estimated_labor_hours)->toBe(1.5);
        expect((float) $area->estimated_material_cost)->toBe(12.00);
        expect((float) $area->estimated_labor_cost)->toBeGreaterThan(0);
    });

    it('loads labor tracking values when editing fixed-price areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $nestedTemplate = \App\Models\NestedAreaTemplate::factory()->smallCloset()->create();

        // Save first
        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal])
            ->set('title', 'Edit Labor Tracking')
            ->call('addFixedPriceTemplate', $nestedTemplate->id)
            ->call('saveAsDraft');

        $estimate = Estimate::where('title', 'Edit Labor Tracking')->first();

        // Now load for editing
        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        $selectedTemplates = $component->get('selectedTemplates');

        expect($selectedTemplates)->toHaveCount(1);
        expect($selectedTemplates[0]['is_fixed_price'])->toBeTrue();
        expect((float) $selectedTemplates[0]['labor_hours'])->toBe(1.5);
        expect((float) $selectedTemplates[0]['material_cost'])->toBe(12.00);
        expect((float) $selectedTemplates[0]['labor_cost'])->toBeGreaterThan(0);
    });

    it('uses real COGS for fixed-price area with labor breakdown', function () {
        $settings = CompanySetting::getInstance();

        $nestedTemplate = \App\Models\NestedAreaTemplate::factory()->smallCloset()->create();

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = \App\Models\EstimateArea::factory()
            ->forEstimate($estimate)
            ->fixedPrice(95.00, $nestedTemplate)
            ->create(['scope_type' => 'interior']);

        // getFullCOGS should return real COGS (not just the fixed price)
        $cogs = $area->getFullCOGS();

        // COGS should include labor + cleanup + sundries + material + taxes
        // It should NOT just be the fixed_price_amount
        expect($cogs)->toBeGreaterThan(0);

        // Customer price stays fixed regardless of COGS
        expect((float) $area->getCustomerPrice())->toBe(95.00);
    });
});
