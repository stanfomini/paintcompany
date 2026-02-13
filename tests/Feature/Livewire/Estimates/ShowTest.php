<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use Livewire\Livewire;

describe('Estimate Show component', function () {
    it('renders the show component with estimate data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'title' => 'Interior Painting Estimate',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee($estimate->estimate_number)
            ->assertSee('Interior Painting Estimate')
            ->assertSee('John Doe');
    });

    it('redirects non-internal users to their dashboard', function () {
        $user = User::factory()->agent()->create();
        $estimate = Estimate::factory()->create();

        // Now redirects to agent dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('estimates.show', $estimate))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('displays line items', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['created_by' => $user->id]);
        EstimateItem::factory()->forEstimate($estimate)->create([
            'description' => 'Wall Painting',
            'quantity' => 100,
            'unit' => 'sq ft',
            'unit_price' => 5.00,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Wall Painting')
            ->assertSee('sq ft');
    });

    it('displays item details when available', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['created_by' => $user->id]);
        EstimateItem::factory()->forEstimate($estimate)->create([
            'description' => 'Paint Work',
            'details' => 'Two coats of premium paint',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Two coats of premium paint');
    });

    it('does not show incorrect bottom totals section for template estimates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'subtotal' => 1000.00,
            'tax_rate' => 8.00,
            'tax_amount' => 80.00,
            'discount_amount' => 50.00,
            'total' => 1030.00,
            'created_by' => $user->id,
        ]);

        // Bottom totals were removed because they double-count service items across packages
        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertDontSee('-$50.00');
    });

    it('can mark draft estimate as sent', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('markAsSent')
            ->assertHasNoErrors();

        $estimate->refresh();
        expect($estimate->status)->toBe('sent');
        expect($estimate->sent_at)->not->toBeNull();
    });

    it('cannot mark non-draft estimate as sent', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->sent()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('markAsSent');

        $estimate->refresh();
        expect($estimate->status)->toBe('sent');
    });

    it('can mark estimate as accepted', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->sent()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('markAsAccepted')
            ->assertHasNoErrors();

        $estimate->refresh();
        expect($estimate->status)->toBe('accepted');
        expect($estimate->accepted_at)->not->toBeNull();
    });

    it('can mark draft estimate as accepted', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('markAsAccepted')
            ->assertHasNoErrors();

        $estimate->refresh();
        expect($estimate->status)->toBe('accepted');
    });

    it('cannot mark rejected estimate as accepted', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->rejected()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('markAsAccepted');

        $estimate->refresh();
        expect($estimate->status)->toBe('rejected');
    });

    it('can mark estimate as rejected', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->sent()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('markAsRejected')
            ->assertHasNoErrors();

        $estimate->refresh();
        expect($estimate->status)->toBe('rejected');
        expect($estimate->rejected_at)->not->toBeNull();
    });

    it('shows edit button for draft estimates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSeeHtml('href="'.route('estimates.edit', $estimate).'"');
    });

    it('shows edit button for sent estimates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->sent()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSeeHtml('href="'.route('estimates.edit', $estimate).'"');
    });

    it('hides edit button for accepted estimates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->accepted()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertDontSeeHtml('href="'.route('estimates.edit', $estimate).'"');
    });

    it('displays status badge with correct color', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $draft = Estimate::factory()->draft()->create(['created_by' => $user->id]);
        $sent = Estimate::factory()->sent()->create(['created_by' => $user->id]);
        $accepted = Estimate::factory()->accepted()->create(['created_by' => $user->id]);
        $rejected = Estimate::factory()->rejected()->create(['created_by' => $user->id]);

        $draftComponent = Livewire::actingAs($user)->test('estimates.show', ['estimate' => $draft]);
        expect($draftComponent->get('statusColor'))->toBe('zinc');

        $sentComponent = Livewire::actingAs($user)->test('estimates.show', ['estimate' => $sent]);
        expect($sentComponent->get('statusColor'))->toBe('blue');

        $acceptedComponent = Livewire::actingAs($user)->test('estimates.show', ['estimate' => $accepted]);
        expect($acceptedComponent->get('statusColor'))->toBe('green');

        $rejectedComponent = Livewire::actingAs($user)->test('estimates.show', ['estimate' => $rejected]);
        expect($rejectedComponent->get('statusColor'))->toBe('red');
    });

    it('displays notes when available', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'notes' => 'This is a special note for the customer.',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('This is a special note for the customer.');
    });

    it('does not display terms on internal show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'terms' => 'Payment due within 30 days.',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertDontSee('Payment due within 30 days.')
            ->assertDontSee('Terms & Conditions');
    });

    it('displays valid until date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'valid_until' => '2025-12-31',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Dec 31, 2025');
    });

    it('shows expired indicator for past valid_until dates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'valid_until' => '2020-01-01',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Expired');
    });

    it('displays status history when available', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->accepted()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Status History')
            ->assertSee('Sent:')
            ->assertSee('Accepted:');
    });

    it('links to deal show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['title' => 'Kitchen Project']);
        $estimate = Estimate::factory()->forDeal($deal)->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Kitchen Project');
    });

    it('links to customer show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['first_name' => 'Alice', 'last_name' => 'Wonder']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Alice Wonder');
    });

    it('displays created by information', function () {
        $creator = User::factory()->internal()->withFullAccess()->create(['name' => 'John Creator']);
        $estimate = Estimate::factory()->create(['created_by' => $creator->id]);
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('John Creator');
    });

    it('refreshes estimate on estimate-updated event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create([
            'title' => 'Original Title',
            'created_by' => $user->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate]);

        $estimate->update(['title' => 'Updated Title']);

        $component->dispatch('estimate-updated');

        expect($component->get('estimate.title'))->toBe('Updated Title');
    });

    it('has download PDF link', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Download PDF');
    });
});

describe('Agent Contributions on Estimate Show', function () {
    it('shows agent contributions section when agent data exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
            'agent_notes' => 'Property is in great condition, just needs a refresh',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Agent Contributions')
            ->assertSee('Property is in great condition, just needs a refresh');
    });

    it('shows agent checklist items', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
            'agent_checklist' => ['vacant_property', 'timeline_urgent'],
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Agent Contributions')
            ->assertSee('Vacant property')
            ->assertSee('Timeline urgent');
    });

    it('shows listing URL in property listing link card', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['listing_url' => 'https://www.zillow.com/homedetails/123-main-st']);
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Property Listing Link')
            ->assertSee('https://www.zillow.com/homedetails/123-main-st');
    });

    it('renders without error when deal has no customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['customer_id' => null]);
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee($estimate->estimate_number);
    });

    it('hides agent contributions when no agent data exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertDontSee('Agent Contributions');
    });
});
