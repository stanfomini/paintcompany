<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ============================================================
// Phase 1A: Deal Stages
// ============================================================

describe('Deal stages', function () {
    it('has the correct quote-focused stages', function () {
        expect(array_keys(Deal::STAGES))->toBe([
            'lead', 'not_contacted', 'follow_up', 'quote_scheduled', 'quoted', 'signed', 'completed', 'lost', 'cancelled',
        ]);
    });

    it('identifies terminal stages', function () {
        expect(Deal::TERMINAL_STAGES)->toBe(['signed', 'completed', 'lost', 'cancelled']);
    });

    it('identifies past deal stages', function () {
        expect(Deal::PAST_DEAL_STAGES)->toBe(['completed', 'lost', 'cancelled']);
    });

    it('reports active status for non-terminal deals', function () {
        $deal = Deal::factory()->lead()->create();
        expect($deal->isActive())->toBeTrue();
        expect($deal->isTerminal())->toBeFalse();
    });

    it('reports terminal status for signed deals', function () {
        $deal = Deal::factory()->signed()->create();
        expect($deal->isTerminal())->toBeTrue();
        expect($deal->isActive())->toBeFalse();
    });

    it('reports terminal status for lost deals', function () {
        $deal = Deal::factory()->lost()->create();
        expect($deal->isTerminal())->toBeTrue();
        expect($deal->isLost())->toBeTrue();
    });

    it('reports completed status for completed deals', function () {
        $deal = Deal::factory()->completed()->create();
        expect($deal->isCompleted())->toBeTrue();
        expect($deal->isTerminal())->toBeTrue();
        expect($deal->isPastDeal())->toBeTrue();
    });

    it('signed deals are not past deals', function () {
        $deal = Deal::factory()->signed()->create();
        expect($deal->isPastDeal())->toBeFalse();
        expect($deal->isTerminal())->toBeTrue();
    });

    it('scopes active deals to non-terminal stages', function () {
        Deal::factory()->lead()->create();
        Deal::factory()->notContacted()->create();
        Deal::factory()->signed()->create();
        Deal::factory()->lost()->create();

        expect(Deal::active()->count())->toBe(2);
    });
});

// ============================================================
// Phase 3: Create/Edit Modal Enhancements
// ============================================================

describe('Customer create modal enhancements', function () {
    it('creates customer with estimate preference', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john-create-test@example.com')
            ->set('estimatePreference', 'virtual')
            ->set('createDeal', false)
            ->set('sendInvite', false)
            ->call('save');

        $customer = Customer::where('email', 'john-create-test@example.com')->first();
        expect($customer)->not->toBeNull();
        expect($customer->estimate_preference)->toBe('virtual');
    });

    it('creates deal with description and lead source', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'Jane')
            ->set('lastName', 'Smith')
            ->set('email', 'jane-deal-test@example.com')
            ->set('projectDescription', 'Need kitchen repainted')
            ->set('leadSource', 'google_ads')
            ->set('createDeal', true)
            ->set('sendInvite', false)
            ->call('save');

        $customer = Customer::where('email', 'jane-deal-test@example.com')->first();
        expect($customer)->not->toBeNull();

        $deal = $customer->deals()->first();
        expect($deal)->not->toBeNull();
        expect($deal->description)->toBe('Need kitchen repainted');
        expect($deal->lead_source)->toBe('google_ads');
    });
});

describe('Customer edit modal enhancements', function () {
    it('can update customer source and estimate preference', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'source' => null,
            'estimate_preference' => null,
        ]);

        Livewire::actingAs($user)
            ->test('customers.edit-modal', ['customerId' => $customer->id])
            ->call('loadCustomer', $customer->id)
            ->set('source', 'referral')
            ->set('estimatePreference', 'in_person')
            ->call('save');

        $customer->refresh();
        expect($customer->source)->toBe('referral');
        expect($customer->estimate_preference)->toBe('in_person');
    });
});

// ============================================================
// Phase 4: Customer Command Center
// ============================================================

describe('Customer command center', function () {
    it('shows customer page with active deal banner', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Kitchen Repaint',
        ]);

        $response = $this->actingAs($user)
            ->get(route('customers.show', $customer));

        $response->assertOk();
        $response->assertSee('Kitchen Repaint');
    });

    it('shows no active deals CTA when no deals exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('No active leads for this customer');
    });

    it('can switch between active deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal1 = Deal::factory()->forCustomer($customer)->lead()->create(['title' => 'Deal One']);
        $deal2 = Deal::factory()->forCustomer($customer)->notContacted()->create(['title' => 'Deal Two']);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('switchDeal', $deal2->id)
            ->assertSet('focusDealId', $deal2->id);
    });

    it('can change active deal stage inline', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('changeActiveDealStage', 'quote_scheduled');

        $deal->refresh();
        expect($deal->stage)->toBe('quote_scheduled');
    });

    it('shows signed deals in active deals not past deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        Deal::factory()->forCustomer($customer)->signed()->create(['title' => 'Signed Active Deal']);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Active Deals')
            ->assertSee('Signed Active Deal');
    });

    it('shows completed deals in past deals section', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        Deal::factory()->forCustomer($customer)->completed()->create(['title' => 'Old Completed Deal']);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Past Deals');
    });

    it('shows source and preference in meta bar', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'source' => 'google_ads',
            'estimate_preference' => 'virtual',
        ]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Google Ads')
            ->assertSee('Virtual');
    });

    it('creates deal with description and lead source from show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('openAddDeal')
            ->set('dealTitle', 'Exterior Project')
            ->set('dealDescription', 'Full exterior repaint')
            ->set('dealLeadSource', 'website')
            ->call('saveDeal');

        $deal = $customer->deals()->first();
        expect($deal->title)->toBe('Exterior Project');
        expect($deal->description)->toBe('Full exterior repaint');
        expect($deal->lead_source)->toBe('website');
    });

    it('auto-focuses on new deal after creation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('openAddDeal')
            ->set('dealTitle', 'New Project')
            ->call('saveDeal');

        $deal = $customer->deals()->first();
        $component->assertSet('focusDealId', $deal->id);
    });

    it('accepts deal query parameter from URL', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.show', ['customer' => $customer, 'deal' => $deal->id]));

        $response->assertOk();
    });
});

// ============================================================
// Phase 5: Pipeline Navigation
// ============================================================

describe('Pipeline navigation', function () {
    it('renders pipeline with kanban stage names', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('New Lead')
            ->assertSee('Quote Scheduled')
            ->assertSee('Quoted');
    });

    it('only includes kanban stages on the board', function () {
        // Verify the KANBAN_STAGES constant is correct
        expect(Deal::KANBAN_STAGES)->toBe(['lead', 'quote_scheduled', 'quoted']);
        expect(Deal::KANBAN_STAGES)->not->toContain('not_contacted');
        expect(Deal::KANBAN_STAGES)->not->toContain('follow_up');
    });
});

// ============================================================
// Phase 6: Customer Onboarding
// ============================================================

describe('Customer onboarding', function () {
    it('sets needs_onboarding when creating customer user via invitation', function () {
        $inviter = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['email' => 'onboard-test@example.com']);

        $service = app(\App\Services\InvitationService::class);
        $invitation = $service->sendInvitation(
            inviter: $inviter,
            email: 'onboard-test@example.com',
            invitedUserType: 'customer'
        );

        $user = $service->acceptInvitation($invitation, [
            'name' => 'Onboard User',
            'password' => 'password',
        ]);

        expect($user->needs_onboarding)->toBeTrue();
    });

    it('does not set needs_onboarding for non-customer users', function () {
        $inviter = User::factory()->internal()->withFullAccess()->create();

        $service = app(\App\Services\InvitationService::class);
        $invitation = $service->sendInvitation(
            inviter: $inviter,
            email: 'agent-onboard-test@example.com',
            invitedUserType: 'agent'
        );

        $user = $service->acceptInvitation($invitation, [
            'name' => 'Agent User',
            'password' => 'password',
        ]);

        expect($user->needs_onboarding)->toBeFalse();
    });

    it('redirects customer who needs onboarding to onboarding page', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);

        $response = $this->actingAs($user, 'customer')
            ->get(route('customer.dashboard'));

        $response->assertRedirect(route('customer.onboarding'));
    });

    it('allows onboarded customer to access dashboard', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => false]);

        $response = $this->actingAs($user, 'customer')
            ->get(route('customer.dashboard'));

        $response->assertOk();
    });

    it('can complete onboarding flow', function () {
        User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->set('phone', '555-123-4567')
            ->set('preferredContact', 'phone')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->set('address', '456 Oak Ave')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->set('timeline', 'within_2_weeks')
            ->set('projectNotes', 'Want light blue for living room')
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $user->refresh();
        expect($user->needs_onboarding)->toBeFalse();

        $customer->refresh();
        expect($customer->phone)->toBe('555-123-4567');
        expect($customer->address)->toBe('456 Oak Ave');

        // Check property was created
        expect($customer->properties()->count())->toBe(1);
    });

    it('can skip onboarding', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('skip')
            ->assertRedirect(route('customer.dashboard'));

        $user->refresh();
        expect($user->needs_onboarding)->toBeFalse();
    });

    it('saves virtual estimate preference', function () {
        User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'estimate_preference' => null,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $customer->refresh();
        expect($customer->estimate_preference)->toBe('virtual');
    });

    it('saves in-person estimate preference', function () {
        User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'estimate_preference' => null,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('selectEstimatePreference', 'in_person')
            ->assertRedirect(route('customer.dashboard'));

        $customer->refresh();
        expect($customer->estimate_preference)->toBe('in_person');
    });

    it('can skip step 4 without setting preference', function () {
        User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'estimate_preference' => null,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('complete')
            ->assertRedirect(route('customer.dashboard'));

        $customer->refresh();
        expect($customer->estimate_preference)->toBeNull();
    });

    it('auto-creates deal and estimate on completion', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $deal = $customer->deals()->where('stage', 'lead')->first();
        expect($deal)->not->toBeNull();
        expect($deal->created_by)->toBe($internal->id);

        $estimate = $deal->estimates()->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('draft');
        expect($estimate->estimator_mode)->toBe('template');
    });

    it('reuses existing lead deal instead of creating duplicate', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
        $existingDeal = Deal::factory()->forCustomer($customer)->lead()->create([
            'created_by' => $internal->id,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('selectEstimatePreference', 'in_person')
            ->assertRedirect(route('customer.dashboard'));

        expect($customer->deals()->count())->toBe(1);
        expect($existingDeal->estimates()->count())->toBe(1);
    });

    it('does not create duplicate estimate on existing deal', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'created_by' => $internal->id,
        ]);
        \App\Models\Estimate::factory()->create([
            'deal_id' => $deal->id,
            'created_by' => $internal->id,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('complete');

        expect($deal->estimates()->count())->toBe(1);
    });

    it('does not create deal or estimate when skipping at step 1', function () {
        User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('skip')
            ->assertRedirect(route('customer.dashboard'));

        expect($customer->deals()->count())->toBe(0);
    });

    it('rejects invalid estimate preference', function () {
        User::factory()->internal()->withFullAccess()->create();
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('selectEstimatePreference', 'invalid')
            ->assertNoRedirect();
    });
});

// ============================================================
// Phase 1B: ReferralService Property Creation
// ============================================================

describe('ReferralService property creation', function () {
    it('creates property from lead form when address and city provided', function () {
        User::factory()->internal()->withFullAccess()->create();

        $service = app(\App\Services\ReferralService::class);
        $result = $service->createFromLeadForm([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'referral-prop-test@example.com',
            'address' => '789 Pine St',
            'city' => 'Boulder',
            'state' => 'CO',
            'zip' => '80302',
        ]);

        expect($result['customer'])->not->toBeNull();

        $property = Property::where('customer_id', $result['customer']->id)->first();
        expect($property)->not->toBeNull();
        expect($property->address)->toBe('789 Pine St');
        expect($property->city)->toBe('Boulder');

        expect($result['deal']->property_id)->toBe($property->id);
    });

    it('does not create property when address is missing', function () {
        User::factory()->internal()->withFullAccess()->create();

        $service = app(\App\Services\ReferralService::class);
        $result = $service->createFromLeadForm([
            'first_name' => 'No',
            'last_name' => 'Address',
            'email' => 'no-address-test@example.com',
        ]);

        $propertyCount = Property::where('customer_id', $result['customer']->id)->count();
        expect($propertyCount)->toBe(0);
        expect($result['deal']->property_id)->toBeNull();
    });
});
