<?php

use App\Livewire\Estimates\SimpleBuilder;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('SimpleBuilder component rendering', function () {
    it('can render simple builder component', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->assertStatus(200)
            ->assertSee('Interior')
            ->assertSee('Exterior');
    });

    it('renders with existing estimate for editing', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
            'title' => 'Existing Estimate',
            'estimator_mode' => Estimate::MODE_SIMPLE,
            'simple_sqft' => 1500,
            'simple_rate_per_sqft' => 4.00,
            'project_type' => 'exterior',
            'tax_rate' => 8.25,
            'discount_amount' => 100,
            'deposit_percent' => 25,
            'notes' => 'Test notes',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSet('title', 'Existing Estimate')
            ->assertSet('totalSqft', 1500.0)
            ->assertSet('ratePerSqft', 4.00)
            ->assertSet('projectType', 'exterior')
            ->assertSet('taxRate', 8.25)
            ->assertSet('discountAmount', 100.0)
            ->assertSet('depositPercent', 25.0)
            ->assertSet('notes', 'Test notes')
            ->assertSet('isEditing', true);
    });

    it('loads defaults from company settings', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_tax_rate' => 9.5,
            'default_deposit_percent' => 35,
            'default_interior_sqft_rate' => 5.25,
            'estimate_validity_days' => 45,
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->assertSet('taxRate', 9.5)
            ->assertSet('depositPercent', 35.0)
            ->assertSet('ratePerSqft', 5.25);
    });
});

describe('SimpleBuilder calculations', function () {
    it('calculates totals correctly', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_tax_rate' => 0,
        ]);

        $component = Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00)
            ->set('taxRate', 10)
            ->set('discountAmount', 0)
            ->set('depositPercent', 30);

        // Subtotal: 1000 * 5.00 = 5000
        // Tax: 5000 * 0.10 = 500
        // Total: 5000 + 500 = 5500
        // Deposit: 5500 * 0.30 = 1650
        // Balance: 5500 - 1650 = 3850
        expect($component->get('subtotal'))->toBe(5000.0);
        expect($component->get('taxAmount'))->toBe(500.0);
        expect($component->get('total'))->toBe(5500.0);
        expect($component->get('depositAmount'))->toBe(1650.0);
        expect($component->get('balanceDue'))->toBe(3850.0);
    });

    it('applies discount to total calculation', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_tax_rate' => 0,
        ]);

        $component = Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('totalSqft', 500)
            ->set('ratePerSqft', 4.00)
            ->set('taxRate', 0)
            ->set('discountAmount', 200)
            ->set('depositPercent', 50);

        // Subtotal: 500 * 4.00 = 2000
        // Tax: 0
        // Total: 2000 + 0 - 200 = 1800
        expect($component->get('subtotal'))->toBe(2000.0);
        expect($component->get('total'))->toBe(1800.0);
        expect($component->get('depositAmount'))->toBe(900.0);
        expect($component->get('balanceDue'))->toBe(900.0);
    });

    it('handles zero square footage', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        $component = Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('totalSqft', 0)
            ->set('ratePerSqft', 5.00);

        expect($component->get('subtotal'))->toBe(0.0);
        expect($component->get('total'))->toBe(0.0);
    });
});

describe('SimpleBuilder project type updates', function () {
    it('updates rate when project type changes to interior', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_interior_sqft_rate' => 4.50,
            'default_exterior_sqft_rate' => 6.00,
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('projectType', 'exterior')
            ->assertSet('ratePerSqft', 6.00)
            ->set('projectType', 'interior')
            ->assertSet('ratePerSqft', 4.50);
    });

    it('updates rate when project type changes to exterior', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_interior_sqft_rate' => 4.50,
            'default_exterior_sqft_rate' => 6.00,
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('projectType', 'exterior')
            ->assertSet('ratePerSqft', 6.00);
    });

    it('uses interior rate for both project type', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_interior_sqft_rate' => 4.50,
            'default_exterior_sqft_rate' => 6.00,
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('projectType', 'both')
            ->assertSet('ratePerSqft', 4.50);
    });
});

describe('SimpleBuilder saving', function () {
    it('saves estimate with simple mode', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_tax_rate' => 8,
            'default_interior_sqft_rate' => 4.00,
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', 'Simple Interior Estimate')
            ->set('totalSqft', 800)
            ->set('projectType', 'interior')
            ->set('taxRate', 8)
            ->set('discountAmount', 0)
            ->set('depositPercent', 30)
            ->set('notes', 'Simple estimate notes')
            ->call('saveAsDraft')
            ->assertHasNoErrors();

        $estimate = Estimate::where('title', 'Simple Interior Estimate')->first();

        expect($estimate)->not->toBeNull();
        expect($estimate->estimator_mode)->toBe(Estimate::MODE_SIMPLE);
        expect((float) $estimate->simple_sqft)->toBe(800.0);
        expect((float) $estimate->simple_rate_per_sqft)->toBe(4.00);
        expect($estimate->project_type)->toBe('interior');
        expect($estimate->status)->toBe('draft');
        expect($estimate->deal_id)->toBe($deal->id);
        expect($estimate->created_by)->toBe($user->id);
    });

    it('validates required fields', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', '')
            ->set('totalSqft', 0)
            ->call('saveAsDraft')
            ->assertHasErrors(['title', 'totalSqft']);
    });

    it('validates minimum values', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 0.5)
            ->set('ratePerSqft', 0)
            ->call('saveAsDraft')
            ->assertHasErrors(['totalSqft', 'ratePerSqft']);
    });

    it('saves and marks as sent', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', 'Sent Estimate')
            ->set('totalSqft', 500)
            ->set('ratePerSqft', 5.00)
            ->set('projectType', 'exterior')
            ->call('saveAndSend')
            ->assertHasNoErrors();

        $estimate = Estimate::where('title', 'Sent Estimate')->first();

        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('sent');
        expect($estimate->sent_at)->not->toBeNull();
    });

    it('updates existing estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
            'title' => 'Original Title',
            'estimator_mode' => Estimate::MODE_SIMPLE,
            'simple_sqft' => 500,
            'simple_rate_per_sqft' => 3.00,
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['estimate' => $estimate])
            ->set('title', 'Updated Title')
            ->set('totalSqft', 750)
            ->set('ratePerSqft', 4.50)
            ->call('saveAsDraft')
            ->assertHasNoErrors();

        $estimate->refresh();

        expect($estimate->title)->toBe('Updated Title');
        expect((float) $estimate->simple_sqft)->toBe(750.0);
        expect((float) $estimate->simple_rate_per_sqft)->toBe(4.50);
    });

    it('dispatches event after creating estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', 'New Estimate')
            ->set('totalSqft', 300)
            ->set('ratePerSqft', 5.00)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-created');
    });

    it('dispatches event after updating estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
            'estimator_mode' => Estimate::MODE_SIMPLE,
            'simple_sqft' => 500,
            'simple_rate_per_sqft' => 3.00,
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['estimate' => $estimate])
            ->set('title', 'Updated')
            ->call('saveAsDraft')
            ->assertDispatched('estimate-updated');
    });
});

describe('SimpleBuilder validation', function () {
    it('validates project type is valid option', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 100)
            ->set('projectType', 'invalid')
            ->call('saveAsDraft')
            ->assertHasErrors(['projectType']);
    });

    it('validates tax rate range', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 100)
            ->set('taxRate', 150)
            ->call('saveAsDraft')
            ->assertHasErrors(['taxRate']);
    });

    it('validates deposit percent range', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        Livewire::actingAs($user)
            ->test(SimpleBuilder::class, ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 100)
            ->set('depositPercent', 110)
            ->call('saveAsDraft')
            ->assertHasErrors(['depositPercent']);
    });
});
