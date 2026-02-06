<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

describe('SimpleBuilder component', function () {
    it('renders the simple builder component for creating a new estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->assertStatus(200)
            ->assertSee('Quick Estimate ($/Sq Ft)')
            ->assertSee($deal->title);
    });

    it('renders the simple builder component for editing an existing estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Existing Simple Estimate',
            'estimator_mode' => Estimate::MODE_SIMPLE,
            'simple_sqft' => 1500,
            'simple_rate_per_sqft' => 4.50,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee('Edit Simple Estimate')
            ->assertSet('title', 'Existing Simple Estimate')
            ->assertSet('totalSqft', 1500.0)
            ->assertSet('ratePerSqft', 4.50)
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
            'default_interior_sqft_rate' => 5.00,
            'default_exterior_sqft_rate' => 6.00,
            'estimate_validity_days' => 14,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal]);

        expect($component->get('taxRate'))->toBe(8.25);
        expect($component->get('depositPercent'))->toBe(50.0);
        expect($component->get('ratePerSqft'))->toBe(5.0);
    });

    it('updates rate per sqft when project type changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'default_interior_sqft_rate' => 4.00,
            'default_exterior_sqft_rate' => 6.00,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal]);

        expect($component->get('ratePerSqft'))->toBe(4.0);

        $component->set('projectType', 'exterior');
        expect($component->get('ratePerSqft'))->toBe(6.0);

        $component->set('projectType', 'interior');
        expect($component->get('ratePerSqft'))->toBe(4.0);
    });

    it('calculates subtotal correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00);

        expect($component->get('subtotal'))->toBe(5000.0);
    });

    it('calculates tax amount correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00)
            ->set('taxRate', 10.0);

        expect($component->get('subtotal'))->toBe(5000.0);
        expect($component->get('taxAmount'))->toBe(500.0);
    });

    it('calculates total correctly with tax and discount', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00)
            ->set('taxRate', 10.0)
            ->set('discountAmount', 200.00);

        expect($component->get('subtotal'))->toBe(5000.0);
        expect($component->get('taxAmount'))->toBe(500.0);
        expect($component->get('total'))->toBe(5300.0); // 5000 + 500 - 200
    });

    it('calculates deposit amount correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00)
            ->set('taxRate', 0)
            ->set('discountAmount', 0)
            ->set('depositPercent', 30);

        expect($component->get('total'))->toBe(5000.0);
        expect($component->get('depositAmount'))->toBe(1500.0);
        expect($component->get('balanceDue'))->toBe(3500.0);
    });

    it('validates required fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', '')
            ->set('totalSqft', 0)
            ->call('saveAsDraft')
            ->assertHasErrors(['title', 'totalSqft']);
    });

    it('validates minimum square footage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 0.5)
            ->set('ratePerSqft', 5.00)
            ->call('saveAsDraft')
            ->assertHasErrors(['totalSqft']);
    });

    it('validates minimum rate per sqft', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 0)
            ->call('saveAsDraft')
            ->assertHasErrors(['ratePerSqft']);
    });

    it('validates project type is valid', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00)
            ->set('projectType', 'invalid')
            ->call('saveAsDraft')
            ->assertHasErrors(['projectType']);
    });

    it('validates valid_until date is not in the past', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00)
            ->set('validUntil', '2020-01-01')
            ->call('saveAsDraft')
            ->assertHasErrors(['validUntil']);
    });

    it('can save a new estimate as draft', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'New Simple Estimate')
            ->set('totalSqft', 2000)
            ->set('ratePerSqft', 4.50)
            ->set('projectType', 'interior')
            ->set('taxRate', 8)
            ->set('depositPercent', 30)
            ->set('notes', 'Test notes')
            ->call('saveAsDraft')
            ->assertDispatched('estimate-created')
            ->assertRedirect();

        $estimate = Estimate::where('title', 'New Simple Estimate')->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('draft');
        expect($estimate->deal_id)->toBe($deal->id);
        expect($estimate->created_by)->toBe($user->id);
        expect($estimate->estimator_mode)->toBe(Estimate::MODE_SIMPLE);
        expect((float) $estimate->simple_sqft)->toBe(2000.0);
        expect((float) $estimate->simple_rate_per_sqft)->toBe(4.50);
        expect($estimate->project_type)->toBe('interior');
        expect((float) $estimate->subtotal)->toBe(9000.0);
    });

    it('can save a new estimate and mark as sent', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'Sent Simple Estimate')
            ->set('totalSqft', 1500)
            ->set('ratePerSqft', 5.00)
            ->call('saveAndSend')
            ->assertDispatched('estimate-created')
            ->assertRedirect();

        $estimate = Estimate::where('title', 'Sent Simple Estimate')->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('sent');
        expect($estimate->sent_at)->not->toBeNull();
    });

    it('can update an existing estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Original Title',
            'estimator_mode' => Estimate::MODE_SIMPLE,
            'simple_sqft' => 1000,
            'simple_rate_per_sqft' => 4.00,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['estimate' => $estimate])
            ->set('title', 'Updated Title')
            ->set('totalSqft', 2500)
            ->set('ratePerSqft', 5.50)
            ->call('saveAsDraft')
            ->assertDispatched('estimate-updated')
            ->assertRedirect();

        $estimate->refresh();
        expect($estimate->title)->toBe('Updated Title');
        expect((float) $estimate->simple_sqft)->toBe(2500.0);
        expect((float) $estimate->simple_rate_per_sqft)->toBe(5.50);
    });

    it('calculates deposit and balance due on save', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'Deposit Test Estimate')
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 10.00)
            ->set('taxRate', 0)
            ->set('discountAmount', 0)
            ->set('depositPercent', 50)
            ->call('saveAsDraft');

        $estimate = Estimate::where('title', 'Deposit Test Estimate')->first();
        expect($estimate)->not->toBeNull();
        expect((float) $estimate->total)->toBe(10000.0);
        expect((float) $estimate->deposit_amount)->toBe(5000.0);
        expect((float) $estimate->balance_due)->toBe(5000.0);
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
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->assertSee('Kitchen Remodel')
            ->assertSee('John Smith');
    });

    it('allows null valid_until date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->set('title', 'Test')
            ->set('totalSqft', 1000)
            ->set('ratePerSqft', 5.00)
            ->set('validUntil', null)
            ->call('saveAsDraft')
            ->assertHasNoErrors(['validUntil']);
    });

    it('shows all project type options', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.simple-builder', ['deal' => $deal])
            ->assertSee('Interior')
            ->assertSee('Exterior')
            ->assertSee('Interior & Exterior');
    });
});
