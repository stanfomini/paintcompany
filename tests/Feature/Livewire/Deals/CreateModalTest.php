<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('CreateModal component', function () {
    it('renders the create modal component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->assertStatus(200);
    });

    it('opens and closes the modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->assertSet('showModal', false)
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false);
    });

    it('resets form fields when closing modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', 'Test Deal')
            ->set('estimatedValue', '5000')
            ->call('closeModal')
            ->assertSet('customerId', null)
            ->assertSet('title', '')
            ->assertSet('estimatedValue', '');
    });

    it('validates required fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->call('save')
            ->assertHasErrors(['customerId', 'title']);
    });

    it('validates customer exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', 99999)
            ->set('title', 'Test Deal')
            ->call('save')
            ->assertHasErrors(['customerId']);
    });

    it('validates stage is valid', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', 'Test Deal')
            ->set('stage', 'invalid_stage')
            ->call('save')
            ->assertHasErrors(['stage']);
    });

    it('validates estimated value is numeric', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', 'Test Deal')
            ->set('estimatedValue', 'not-a-number')
            ->call('save')
            ->assertHasErrors(['estimatedValue']);
    });

    it('validates expected end date is after start date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', 'Test Deal')
            ->set('expectedStartDate', '2025-06-01')
            ->set('expectedEndDate', '2025-05-01')
            ->call('save')
            ->assertHasErrors(['expectedEndDate']);
    });

    it('creates a deal successfully with all fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->create(['customer_id' => $customer->id]);
        $assignee = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('propertyId', $property->id)
            ->set('title', 'Exterior Painting Project')
            ->set('description', 'Full exterior painting for main house')
            ->set('stage', 'quoted')
            ->set('estimatedValue', '15000.50')
            ->set('expectedStartDate', '2025-06-01')
            ->set('expectedEndDate', '2025-06-15')
            ->set('assignedTo', $assignee->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('deal-created')
            ->assertSet('showModal', false);

        $deal = Deal::where('title', 'Exterior Painting Project')->first();

        expect($deal)->not->toBeNull();
        expect($deal->customer_id)->toBe($customer->id);
        expect($deal->property_id)->toBe($property->id);
        expect($deal->description)->toBe('Full exterior painting for main house');
        expect($deal->stage)->toBe('quoted');
        expect((float) $deal->estimated_value)->toBe(15000.50);
        expect($deal->expected_start_date->format('Y-m-d'))->toBe('2025-06-01');
        expect($deal->expected_end_date->format('Y-m-d'))->toBe('2025-06-15');
        expect($deal->assigned_to)->toBe($assignee->id);
        expect($deal->created_by)->toBe($user->id);
    });

    it('creates deal with only required fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', 'Basic Deal')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('deal-created');

        $this->assertDatabaseHas('deals', [
            'customer_id' => $customer->id,
            'title' => 'Basic Deal',
            'stage' => 'lead',
            'created_by' => $user->id,
        ]);
    });

    it('sets created_by to authenticated user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', 'Test Deal')
            ->call('save');

        $deal = Deal::where('title', 'Test Deal')->first();

        expect($deal->created_by)->toBe($user->id);
    });

    it('resets property when customer changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        $property = Property::factory()->create(['customer_id' => $customer1->id]);

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer1->id)
            ->set('propertyId', $property->id)
            ->assertSet('propertyId', $property->id)
            ->set('customerId', $customer2->id)
            ->assertSet('propertyId', null);
    });

    it('shows properties dropdown only for selected customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Main House',
        ]);

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->assertSee('Main House');
    });

    it('lists internal users in assigned dropdown', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create(['name' => 'John Painter']);
        $agentUser = User::factory()->agent()->create(['name' => 'Agent Smith']);

        Livewire::actingAs($internalUser)
            ->test('deals.create-modal')
            ->assertSee('John Painter')
            ->assertDontSee('Agent Smith');
    });
});
