<?php

use App\Livewire\Estimates\TemplateBuilder;
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
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        // Create a category with templates for testing
        $category = EstimateTemplateCategory::factory()->room()->create();
        EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
            'default_quantity' => 150,
        ]);

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertStatus(200)
            ->assertSee('Template Builder')
            ->assertSee($deal->title);
    });

    it('renders the template builder component for editing an existing estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Existing Template Estimate',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee('Edit Template Estimate')
            ->assertSet('title', 'Existing Template Estimate')
            ->assertSet('isEditing', true);
    });

    it('loads defaults from company settings on mount', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_tax_rate' => 8.25,
            'default_deposit_percent' => 50,
            'estimate_validity_days' => 14,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal]);

        expect($component->get('taxRate'))->toBe(8.25);
        expect($component->get('depositPercent'))->toBe(50.0);
    });

    it('displays template categories as tabs', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $roomCategory = EstimateTemplateCategory::factory()->room()->create(['name' => 'Rooms']);
        $surfaceCategory = EstimateTemplateCategory::factory()->surface()->create(['name' => 'Surfaces']);

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSee('Rooms')
            ->assertSee('Surfaces');
    });

    it('can add a template to selected list', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
            'default_quantity' => 150,
            'default_coats' => 2,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
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
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template1 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
        ]);
        $template2 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(800)->create([
            'name' => 'Living Room',
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template1->id)
            ->call('addTemplate', $template2->id);

        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates)->toHaveCount(2);
    });

    it('can remove a template from selected list', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id);

        expect($component->get('selectedTemplates'))->toHaveCount(1);

        $component->call('removeTemplate', 0);

        expect($component->get('selectedTemplates'))->toHaveCount(0);
    });

    it('can open adjustment modal for a template', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'default_quantity' => 150,
            'default_coats' => 2,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->call('openAdjustment', 0);

        expect($component->get('showAdjustmentModal'))->toBeTrue();
        expect($component->get('adjustingTemplateIndex'))->toBe(0);
        expect($component->get('adjustQuantity'))->toBe(150.0);
        expect($component->get('adjustCoats'))->toBe(2);
    });

    it('can close adjustment modal', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->call('openAdjustment', 0)
            ->assertSet('showAdjustmentModal', true)
            ->call('closeAdjustment')
            ->assertSet('showAdjustmentModal', false)
            ->assertSet('adjustingTemplateIndex', null);
    });

    it('can save adjustment and recalculate price', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->perSqftPricing(2.00)->create([
            'default_quantity' => 100,
            'default_coats' => 2,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->call('openAdjustment', 0)
            ->set('adjustQuantity', 200)
            ->set('adjustCoats', 3)
            ->call('saveAdjustment');

        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates[0]['quantity'])->toBe(200.0);
        expect($selectedTemplates[0]['coats'])->toBe(3);
        expect($component->get('showAdjustmentModal'))->toBeFalse();
    });

    it('calculates subtotal correctly', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template1 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();
        $template2 = EstimateTemplate::factory()->forCategory($category)->fixedPricing(300)->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template1->id)
            ->call('addTemplate', $template2->id);

        expect($component->get('subtotal'))->toBe(800.0);
    });

    it('calculates tax amount correctly', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('taxRate', 10.0);

        expect($component->get('subtotal'))->toBe(1000.0);
        expect($component->get('taxAmount'))->toBe(100.0);
    });

    it('calculates total correctly with tax and discount', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('taxRate', 10.0)
            ->set('discountAmount', 50.00);

        expect($component->get('subtotal'))->toBe(1000.0);
        expect($component->get('taxAmount'))->toBe(100.0);
        expect($component->get('total'))->toBe(1050.0); // 1000 + 100 - 50
    });

    it('calculates deposit amount correctly', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('taxRate', 0)
            ->set('discountAmount', 0)
            ->set('depositPercent', 30);

        expect($component->get('total'))->toBe(1000.0);
        expect($component->get('depositAmount'))->toBe(300.0);
        expect($component->get('balanceDue'))->toBe(700.0);
    });

    it('validates required title', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('title', '')
            ->call('saveAsDraft')
            ->assertHasErrors(['title']);
    });

    it('validates at least one template is selected', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->set('title', 'Test Estimate')
            ->call('saveAsDraft')
            ->assertHasErrors(['selectedTemplates']);
    });

    it('validates valid_until date is not in the past', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->set('title', 'Test')
            ->set('validUntil', '2020-01-01')
            ->call('saveAsDraft')
            ->assertHasErrors(['validUntil']);
    });

    it('can save a new estimate as draft', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1500)->create([
            'name' => 'Living Room',
            'default_quantity' => 300,
            'default_coats' => 2,
        ]);

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
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
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
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
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Original Title',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'created_by' => $user->id,
        ]);

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(2000)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['estimate' => $estimate])
            ->set('title', 'Updated Title')
            ->call('addTemplate', $template->id)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-updated')
            ->assertRedirect();

        $estimate->refresh();
        expect($estimate->title)->toBe('Updated Title');
    });

    it('displays deal and customer information', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Kitchen Remodel',
        ]);

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSee('Kitchen Remodel')
            ->assertSee('John Smith');
    });

    it('does not add inactive templates', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->inactive()->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id);

        expect($component->get('selectedTemplates'))->toHaveCount(0);
    });

    it('allows null valid_until date', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->set('title', 'Test')
            ->call('addTemplate', $template->id)
            ->set('validUntil', null)
            ->call('saveAsDraft')
            ->assertHasNoErrors(['validUntil']);
    });

    it('can toggle between slider and input mode', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSet('useSlider', true)
            ->set('useSlider', false)
            ->assertSet('useSlider', false);
    });

    it('validates adjustment quantity is positive', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->call('openAdjustment', 0)
            ->set('adjustQuantity', 0)
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustQuantity']);
    });

    it('validates adjustment coats is within valid range', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->call('openAdjustment', 0)
            ->set('adjustQuantity', 100)
            ->set('adjustCoats', 5)
            ->call('saveAdjustment')
            ->assertHasErrors(['adjustCoats']);
    });

    it('shows adjustment preview price', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->perSqftPricing(2.00)->create([
            'default_quantity' => 100,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id)
            ->call('openAdjustment', 0)
            ->set('adjustQuantity', 200);

        // Price should be 200 * 2.00 = 400
        expect($component->get('adjustmentPreviewPrice'))->toBe(400.0);
    });

    it('creates template usages when saving estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create([
            'name' => 'Living Room Template',
            'default_quantity' => 200,
            'default_coats' => 2,
        ]);

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
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
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSet('projectScope', 'interior')
            ->assertSet('scopeFilter', 'interior');
    });

    it('returns project scope options', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal]);

        $options = $component->instance()->getProjectScopeOptions();

        expect($options)->toHaveKey('interior');
        expect($options)->toHaveKey('exterior');
        expect($options)->not->toHaveKey('all');
        expect($options['interior'])->toBe('Interior');
        expect($options['exterior'])->toBe('Exterior');
    });

    it('syncs scopeFilter when projectScope changes', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSet('projectScope', 'interior')
            ->assertSet('scopeFilter', 'interior')
            ->set('projectScope', 'exterior')
            ->assertSet('projectScope', 'exterior')
            ->assertSet('scopeFilter', 'exterior')
            ->assertDispatched('scope-changed');
    });

    it('filters out non-matching templates when project scope changes', function () {
        $user = User::factory()->internal()->create();
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
            ->test(TemplateBuilder::class, ['deal' => $deal])
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
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $bothTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Universal Template',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
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
        $user = User::factory()->internal()->create();
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
            ->test(TemplateBuilder::class, ['estimate' => $estimate]);

        expect($component->get('projectScope'))->toBe('exterior');
        expect($component->get('scopeFilter'))->toBe('exterior');
    });
});

describe('TemplateBuilder quick add', function () {
    it('returns quick add templates for interior scope', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        // Create interior quick add templates
        $bedroom = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $bathroom = EstimateTemplate::factory()->forCategory($category)->fixedPricing(300)->create([
            'name' => 'Bathroom',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $kitchen = EstimateTemplate::factory()->forCategory($category)->fixedPricing(600)->create([
            'name' => 'Kitchen',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);

        // Create exterior template that should NOT be in interior quick add
        EstimateTemplate::factory()->forCategory($category)->fixedPricing(1000)->create([
            'name' => 'House Exterior',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSet('projectScope', 'interior');

        $quickAddTemplates = $component->instance()->quickAddTemplates;
        $quickAddNames = $quickAddTemplates->pluck('name')->toArray();

        expect($quickAddNames)->toContain('Bedroom');
        expect($quickAddNames)->toContain('Bathroom');
        expect($quickAddNames)->toContain('Kitchen');
        expect($quickAddNames)->not->toContain('House Exterior');
    });

    it('returns quick add templates for exterior scope', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        // Create exterior quick add templates
        $houseExterior = EstimateTemplate::factory()->forCategory($category)->fixedPricing(2000)->create([
            'name' => 'House Exterior',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
        $garage = EstimateTemplate::factory()->forCategory($category)->fixedPricing(800)->create([
            'name' => 'Garage',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
        $deck = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Deck',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);

        // Create interior template that should NOT be in exterior quick add
        EstimateTemplate::factory()->forCategory($category)->fixedPricing(400)->create([
            'name' => 'Bedroom',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->set('projectScope', 'exterior');

        $quickAddTemplates = $component->instance()->quickAddTemplates;
        $quickAddNames = $quickAddTemplates->pluck('name')->toArray();

        expect($quickAddNames)->toContain('House Exterior');
        expect($quickAddNames)->toContain('Garage');
        expect($quickAddNames)->toContain('Deck');
        expect($quickAddNames)->not->toContain('Bedroom');
    });

    it('updates quick add templates when project scope changes', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Bedroom',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        EstimateTemplate::factory()->forCategory($category)->fixedPricing(2000)->create([
            'name' => 'House Exterior',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSet('projectScope', 'interior');

        // Interior scope: should have bedroom
        $quickAdd = $component->instance()->quickAddTemplates;
        expect($quickAdd->pluck('name')->toArray())->toContain('Bedroom');

        // Switch to exterior
        $component->set('projectScope', 'exterior');

        // Exterior scope: should have house exterior
        $quickAdd = $component->instance()->quickAddTemplates;
        expect($quickAdd->pluck('name')->toArray())->toContain('House Exterior');
    });
});

describe('TemplateBuilder scope filtering', function () {
    it('returns scope filter options', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal]);

        $options = $component->instance()->getScopeFilterOptions();

        expect($options)->toHaveKey('all');
        expect($options)->toHaveKey('interior');
        expect($options)->toHaveKey('exterior');
        expect($options['all'])->toBe('All');
        expect($options['interior'])->toBe('Interior');
        expect($options['exterior'])->toBe('Exterior');
    });

    it('can set scope filter', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSet('scopeFilter', 'interior') // Now defaults to interior (synced with projectScope)
            ->set('scopeFilter', 'all')
            ->assertSet('scopeFilter', 'all')
            ->set('scopeFilter', 'exterior')
            ->assertSet('scopeFilter', 'exterior');
    });

    it('filters templates by interior scope', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        // Create templates with different scopes
        $interiorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Interior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Exterior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
        $bothTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Both Scopes',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->set('scopeFilter', 'interior');

        $templates = $component->instance()->getTemplatesByCategoryProperty();
        $roomTemplates = $templates->get('room', collect());
        $templateNames = $roomTemplates->pluck('name')->toArray();

        expect($templateNames)->toContain('Interior Only');
        expect($templateNames)->toContain('Both Scopes');
        expect($templateNames)->not->toContain('Exterior Only');
    });

    it('filters templates by exterior scope', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        $interiorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Interior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Exterior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
        $bothTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Both Scopes',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->set('scopeFilter', 'exterior');

        $templates = $component->instance()->getTemplatesByCategoryProperty();
        $roomTemplates = $templates->get('room', collect());
        $templateNames = $roomTemplates->pluck('name')->toArray();

        expect($templateNames)->toContain('Exterior Only');
        expect($templateNames)->toContain('Both Scopes');
        expect($templateNames)->not->toContain('Interior Only');
    });

    it('shows all templates when scope filter is all', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();

        $interiorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Interior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
        $exteriorTemplate = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Exterior Only',
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);

        // scopeFilter now defaults to 'interior' (synced with projectScope)
        // Change to 'all' to test that both scope types are shown
        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->assertSet('scopeFilter', 'interior')
            ->set('scopeFilter', 'all');

        $templates = $component->instance()->getTemplatesByCategoryProperty();
        $roomTemplates = $templates->get('room', collect());
        $templateNames = $roomTemplates->pluck('name')->toArray();

        expect($templateNames)->toContain('Interior Only');
        expect($templateNames)->toContain('Exterior Only');
    });
});

describe('TemplateBuilder cost breakdown', function () {
    it('calculates cost breakdown for template with production rate using PBP formula', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        // Create company settings with PBP formula values
        // Using values that make math easy to verify:
        // - $40/hr effective rate (wage × (1 + burden))
        // - No cleanup or sundries for simpler verification
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 40, // Use $40 for easy math
            'payroll_burden_multiplier' => 1.0, // 1.0 = no burden // No burden, so effective = $40
            'interior_cleanup_hours_ratio' => 999999, // Effectively no cleanup
            'interior_sundries_cost_per_hour' => 0, // No sundries
            'net_profit_margin_percent' => 30,
            'overhead_percent' => 12,
            'interior_markup_percent' => 50,
        ]);

        // Create production rate category and rate
        $rateCategory = \App\Models\ProductionRateCategory::factory()->create([
            'type' => 'interior',
        ]);
        $productionRate = \App\Models\ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'name' => 'Wall Painting',
            'production_rate' => 200, // sqft per hour
            'hourly_labor_rate' => 40, // This is ignored in PBP formula
            'material_cost_per_unit' => 0.50,
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
            ->test(TemplateBuilder::class, ['deal' => $deal]);

        $breakdown = $component->instance()->getTemplateCostBreakdown($template->id, 400, 2);

        // PBP Formula with our test values:
        // 400 sqft / 200 sqft/hr = 2 hours base, × 1.8 (coat multiplier for 2 coats) = 3.6 hours labor
        expect($breakdown['labor_hours'])->toBe(3.6);

        // Labor cost uses CompanySetting effective rate ($40), NOT production rate
        // 3.6 hours × $40/hr = $144 labor cost
        expect($breakdown['labor_cost'])->toBe(144.0);

        // 400 × $0.50 × 1.8 = $360 material cost
        expect($breakdown['material_cost'])->toBe(360.0);

        // Cleanup and sundries are effectively 0 with our test setup
        expect($breakdown['cleanup_hours'])->toBe(0.0);
        expect($breakdown['cleanup_cost'])->toBe(0.0);
        expect($breakdown['sundries_cost'])->toBe(0.0);

        // Base total = $144 + $0 + $0 + $360 = $504
        expect($breakdown['base_total'])->toBe(504.0);

        // With margins: $504 × 1.30 (profit) × 1.12 (overhead) × 1.50 (interior markup) = $1,100.74
        expect($breakdown['with_margins'])->toBe(1100.74);
    });

    it('returns zero breakdown for template without production rate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $category = EstimateTemplateCategory::factory()->room()->create();
        $template = EstimateTemplate::factory()->forCategory($category)->fixedPricing(500)->create([
            'name' => 'Fixed Price Room',
            'default_quantity' => 200,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TemplateBuilder::class, ['deal' => $deal]);

        $breakdown = $component->instance()->getTemplateCostBreakdown($template->id, 200, 2);

        expect($breakdown['labor_hours'])->toBe(0);
        expect($breakdown['labor_cost'])->toBe(0);
        expect($breakdown['material_cost'])->toBe(0);
        expect($breakdown['base_total'])->toBe(500.0); // Falls back to calculatePrice
    });

    it('includes cost breakdown data when adding template using PBP formula', function () {
        $user = User::factory()->internal()->create();
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
            'hourly_labor_rate' => 40, // Ignored in PBP formula
            'material_cost_per_unit' => 0.50,
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
            ->test(TemplateBuilder::class, ['deal' => $deal])
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
        // 400 × 0.50 × 1.8 = 360
        expect($addedTemplate['material_cost'])->toBe(360.0);
    });

    it('updates cost breakdown when saving adjustment using PBP formula', function () {
        $user = User::factory()->internal()->create();
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
            'hourly_labor_rate' => 40, // Ignored in PBP formula
            'material_cost_per_unit' => 0.50,
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
            ->test(TemplateBuilder::class, ['deal' => $deal])
            ->call('addTemplate', $template->id);

        // Original: 400 sqft, 2 coats with multiplier 1.8 → (400/200) × 1.8 = 3.6 hours
        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates[0]['labor_hours'])->toBe(3.6);

        // Adjust to 800 sqft, 2 coats → (800/200) × 1.8 = 7.2 hours
        $component->call('openAdjustment', 0)
            ->set('adjustQuantity', 800)
            ->set('adjustCoats', 2)
            ->call('saveAdjustment');

        $selectedTemplates = $component->get('selectedTemplates');
        expect($selectedTemplates[0]['quantity'])->toBe(800.0);
        expect($selectedTemplates[0]['labor_hours'])->toBe(7.2);
        // Uses CompanySetting effective rate: 7.2 × $40 = $288
        expect($selectedTemplates[0]['labor_cost'])->toBe(288.0);
        expect($selectedTemplates[0]['material_cost'])->toBe(720.0); // 800 × 0.50 × 1.8
    });

    it('loads cost breakdown when editing existing estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        // Create production rate
        $rateCategory = \App\Models\ProductionRateCategory::factory()->create([
            'type' => 'interior',
        ]);
        $productionRate = \App\Models\ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'production_rate' => 200,
            'hourly_labor_rate' => 40,
            'material_cost_per_unit' => 0.50,
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
            ->test(TemplateBuilder::class, ['estimate' => $estimate]);

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
