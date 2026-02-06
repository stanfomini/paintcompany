<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealNote;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Deal pipeline access', function () {
    it('allows internal user to view deal pipeline', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->get(route('deals.index'));

        $response->assertOk();
        $response->assertSeeLivewire('deals.pipeline');
    });

    it('denies agent access to deal pipeline', function () {
        $user = User::factory()->agent()->create();

        $response = $this->actingAs($user)
            ->get(route('deals.index'));

        $response->assertRedirect();
    });

    it('denies customer access to deal pipeline', function () {
        $user = User::factory()->customer()->create();

        $response = $this->actingAs($user)
            ->get(route('deals.index'));

        $response->assertRedirect();
    });

    it('redirects unauthenticated users to login', function () {
        $response = $this->get(route('deals.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('Deal detail access', function () {
    it('allows internal user to view deal detail', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('deals.show', $deal));

        $response->assertOk();
        $response->assertSeeLivewire('deals.show');
    });

    it('denies agent access to deal detail', function () {
        $user = User::factory()->agent()->create();
        $deal = Deal::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('deals.show', $deal));

        $response->assertRedirect();
    });

    it('denies customer access to deal detail', function () {
        $user = User::factory()->customer()->create();
        $deal = Deal::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('deals.show', $deal));

        $response->assertRedirect();
    });
});

describe('Deal creation', function () {
    it('allows internal user to create deal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', 'New Painting Project')
            ->set('stage', 'lead')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'customer_id' => $customer->id,
            'title' => 'New Painting Project',
            'stage' => 'lead',
            'created_by' => $user->id,
        ]);
    });

    it('validates required customer field', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', '')
            ->set('title', 'Test Deal')
            ->call('save')
            ->assertHasErrors(['customerId']);
    });

    it('validates required title field', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.create-modal')
            ->set('customerId', $customer->id)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title']);
    });
});

describe('Deal editing', function () {
    it('allows internal user to update deal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Original Title',
            'stage' => 'lead',
        ]);

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('title', 'Updated Title')
            ->set('description', 'Updated description')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'title' => 'Updated Title',
            'description' => 'Updated description',
        ]);
    });

    it('can move deal to different stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->lead()->create();

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('stage', 'quoted')
            ->call('save')
            ->assertHasNoErrors();

        $deal->refresh();
        expect($deal->stage)->toBe('quoted');
    });

    it('deal belongs to correct customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer1->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.edit-modal', ['dealId' => $deal->id])
            ->set('customerId', $customer2->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'customer_id' => $customer2->id,
        ]);
    });
});

describe('Deal filters', function () {
    it('can search deals by title', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Deal::factory()->forCustomer($customer)->create([
            'title' => 'Kitchen Renovation',
            'created_by' => $user->id,
        ]);
        Deal::factory()->forCustomer($customer)->create([
            'title' => 'Bathroom Remodel',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->set('search', 'Kitchen')
            ->assertSee('Kitchen Renovation')
            ->assertDontSee('Bathroom Remodel');
    });

    it('can filter by stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Lead Stage Deal',
            'created_by' => $user->id,
        ]);
        Deal::factory()->forCustomer($customer)->quoted()->create([
            'title' => 'Quoted Stage Deal',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('Lead Stage Deal')
            ->assertSee('Quoted Stage Deal');
    });

    it('can filter by customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Test',
        ]);
        $customer2 = Customer::factory()->create([
            'first_name' => 'Bob',
            'last_name' => 'Test',
        ]);

        Deal::factory()->forCustomer($customer1)->create([
            'title' => 'Alice Project',
            'created_by' => $user->id,
        ]);
        Deal::factory()->forCustomer($customer2)->create([
            'title' => 'Bob Project',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->set('customerFilter', $customer1->id)
            ->assertSee('Alice Project')
            ->assertDontSee('Bob Project');
    });

    it('can filter by assigned user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee1 = User::factory()->internal()->withFullAccess()->create(['name' => 'John Worker']);
        $assignee2 = User::factory()->internal()->withFullAccess()->create(['name' => 'Jane Worker']);
        $customer = Customer::factory()->create();

        Deal::factory()->forCustomer($customer)->assignedTo($assignee1)->create([
            'title' => 'John Assigned Deal',
            'created_by' => $user->id,
        ]);
        Deal::factory()->forCustomer($customer)->assignedTo($assignee2)->create([
            'title' => 'Jane Assigned Deal',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->set('assignedToFilter', $assignee1->id)
            ->assertSee('John Assigned Deal')
            ->assertDontSee('Jane Assigned Deal');
    });
});

describe('Deal notes', function () {
    it('can add note to deal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->set('content', 'This is a test note')
            ->set('isInternal', false)
            ->call('saveNote')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deal_notes', [
            'deal_id' => $deal->id,
            'user_id' => $user->id,
            'content' => 'This is a test note',
            'is_internal' => false,
        ]);
    });

    it('can add internal note', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->set('content', 'Internal staff note')
            ->set('isInternal', true)
            ->call('saveNote')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deal_notes', [
            'deal_id' => $deal->id,
            'user_id' => $user->id,
            'content' => 'Internal staff note',
            'is_internal' => true,
        ]);
    });

    it('internal note marked correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->set('content', 'Staff only note')
            ->set('isInternal', true)
            ->call('saveNote');

        $note = DealNote::where('deal_id', $deal->id)->first();

        expect($note->is_internal)->toBeTrue();
    });

    it('can delete own note', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($user)->create([
            'content' => 'Note to delete',
        ]);

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('deleteNote', $note->id)
            ->assertDispatched('note-deleted');

        $this->assertDatabaseMissing('deal_notes', [
            'id' => $note->id,
        ]);
    });

    it('cannot delete other users note', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $note = DealNote::factory()->forDeal($deal)->writtenBy($otherUser)->create([
            'content' => 'Other user note',
        ]);

        Livewire::actingAs($user)
            ->test('deals.note-form', ['dealId' => $deal->id])
            ->call('deleteNote', $note->id);

        $this->assertDatabaseHas('deal_notes', [
            'id' => $note->id,
        ]);
    });
});
