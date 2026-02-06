<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EditModal component', function () {
    it('renders the edit modal component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal')
            ->assertStatus(200);
    });

    it('loads deal data on mount with deal id', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Test Deal',
            'description' => 'Test description',
            'stage' => 'quoted',
            'estimated_value' => 5000.00,
        ]);

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->assertSet('dealId', $deal->id)
            ->assertSet('customerId', $customer->id)
            ->assertSet('title', 'Test Deal')
            ->assertSet('description', 'Test description')
            ->assertSet('stage', 'quoted')
            ->assertSet('estimatedValue', '5000.00');
    });

    it('opens modal with deal data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['title' => 'Exterior Project']);

        Livewire::actingAs($user)
            ->test('deals.edit-modal')
            ->call('openModal', $deal->id)
            ->assertSet('showModal', true)
            ->assertSet('dealId', $deal->id)
            ->assertSet('title', 'Exterior Project');
    });

    it('closes modal and resets data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false)
            ->assertSet('dealId', null)
            ->assertSet('title', '');
    });

    it('validates required fields on save', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('customerId', null)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['customerId', 'title']);
    });

    it('validates stage is valid', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('stage', 'invalid_stage')
            ->call('save')
            ->assertHasErrors(['stage']);
    });

    it('validates actual end date is after actual start date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('actualStartDate', '2025-06-15')
            ->set('actualEndDate', '2025-06-01')
            ->call('save')
            ->assertHasErrors(['actualEndDate']);
    });

    it('updates deal successfully', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Original Title',
            'stage' => 'lead',
        ]);
        $assignee = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('title', 'Updated Title')
            ->set('description', 'Updated description')
            ->set('stage', 'scheduled')
            ->set('estimatedValue', '25000.00')
            ->set('assignedTo', $assignee->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('deal-updated')
            ->assertSet('showModal', false);

        $deal->refresh();

        expect($deal->title)->toBe('Updated Title');
        expect($deal->description)->toBe('Updated description');
        expect($deal->stage)->toBe('scheduled');
        expect((float) $deal->estimated_value)->toBe(25000.00);
        expect($deal->assigned_to)->toBe($assignee->id);
    });

    it('updates actual values for completed deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->inProgress()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('stage', 'completed')
            ->set('actualValue', '18500.00')
            ->set('actualStartDate', '2025-05-01')
            ->set('actualEndDate', '2025-05-15')
            ->call('save')
            ->assertHasNoErrors();

        $deal->refresh();

        expect($deal->stage)->toBe('completed');
        expect((float) $deal->actual_value)->toBe(18500.00);
        expect($deal->actual_start_date->format('Y-m-d'))->toBe('2025-05-01');
        expect($deal->actual_end_date->format('Y-m-d'))->toBe('2025-05-15');
    });

    it('clears optional fields when set to empty', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create([
            'description' => 'Some description',
            'estimated_value' => 5000.00,
        ]);

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('description', '')
            ->set('estimatedValue', '')
            ->call('save')
            ->assertHasNoErrors();

        $deal->refresh();

        expect($deal->description)->toBeNull();
        expect($deal->estimated_value)->toBeNull();
    });

    it('can change customer and property', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        $property = Property::factory()->create(['customer_id' => $customer2->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer1->id]);

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('customerId', $customer2->id)
            ->set('propertyId', $property->id)
            ->call('save')
            ->assertHasNoErrors();

        $deal->refresh();

        expect($deal->customer_id)->toBe($customer2->id);
        expect($deal->property_id)->toBe($property->id);
    });

    it('resets property when customer changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        $property = Property::factory()->create(['customer_id' => $customer1->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer1->id,
            'property_id' => $property->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->assertSet('propertyId', $property->id)
            ->set('customerId', $customer2->id)
            ->assertSet('propertyId', null);
    });

    it('preserves dates when loading deal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create([
            'expected_start_date' => '2025-06-01',
            'expected_end_date' => '2025-06-30',
            'actual_start_date' => '2025-06-02',
            'actual_end_date' => '2025-06-28',
        ]);

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->assertSet('expectedStartDate', '2025-06-01')
            ->assertSet('expectedEndDate', '2025-06-30')
            ->assertSet('actualStartDate', '2025-06-02')
            ->assertSet('actualEndDate', '2025-06-28');
    });
});
