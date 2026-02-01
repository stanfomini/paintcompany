<?php

use App\Livewire\Deals\Pipeline;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('Deal Pipeline component', function () {
    it('renders the deal pipeline component', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertStatus(200)
            ->assertSee('Deal Pipeline');
    });

    it('allows internal users to view the pipeline', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertStatus(200);
    });

    it('prevents agents from viewing the pipeline', function () {
        $user = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertStatus(403);
    });

    it('displays all deal stages', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertSee('Lead')
            ->assertSee('Quoted')
            ->assertSee('Scheduled')
            ->assertSee('In Progress')
            ->assertSee('Completed')
            ->assertSee('Paid')
            ->assertSee('Cancelled');
    });

    it('displays deals in correct stages', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $leadDeal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Lead Deal Test',
            'created_by' => $user->id,
        ]);
        $quotedDeal = Deal::factory()->forCustomer($customer)->quoted()->create([
            'title' => 'Quoted Deal Test',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertSee('Lead Deal Test')
            ->assertSee('Quoted Deal Test');
    });

    it('can update deal stage via sort', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Move Me Deal',
            'created_by' => $user->id,
        ]);

        expect($deal->stage)->toBe('lead');

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->call('updateOrder', [$deal->id], 'lead', 'quoted');

        $deal->refresh();
        expect($deal->stage)->toBe('quoted');
    });

    it('updates sort order when moving deals', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $deal1 = Deal::factory()->forCustomer($customer)->quoted()->create([
            'title' => 'Deal One',
            'created_by' => $user->id,
            'sort_order' => 0,
        ]);
        $deal2 = Deal::factory()->forCustomer($customer)->quoted()->create([
            'title' => 'Deal Two',
            'created_by' => $user->id,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->call('updateOrder', [$deal2->id, $deal1->id], 'quoted', 'quoted');

        $deal1->refresh();
        $deal2->refresh();

        expect($deal2->sort_order)->toBe(0);
        expect($deal1->sort_order)->toBe(1);
    });

    it('can filter by assigned user', function () {
        $user = User::factory()->internal()->create();
        $assignee1 = User::factory()->internal()->create(['name' => 'John Assigned']);
        $assignee2 = User::factory()->internal()->create(['name' => 'Jane Assigned']);
        $customer = Customer::factory()->createdBy($user)->create();

        $deal1 = Deal::factory()->forCustomer($customer)->assignedTo($assignee1)->create([
            'title' => 'Johns Deal',
            'created_by' => $user->id,
        ]);
        $deal2 = Deal::factory()->forCustomer($customer)->assignedTo($assignee2)->create([
            'title' => 'Janes Deal',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->set('assignedToFilter', $assignee1->id)
            ->assertSee('Johns Deal')
            ->assertDontSee('Janes Deal');
    });

    it('can filter by customer', function () {
        $user = User::factory()->internal()->create();
        $customer1 = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Alice',
            'last_name' => 'Customer',
        ]);
        $customer2 = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Bob',
            'last_name' => 'Customer',
        ]);

        $deal1 = Deal::factory()->forCustomer($customer1)->create([
            'title' => 'Alices Project',
            'created_by' => $user->id,
        ]);
        $deal2 = Deal::factory()->forCustomer($customer2)->create([
            'title' => 'Bobs Project',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->set('customerFilter', $customer1->id)
            ->assertSee('Alices Project')
            ->assertDontSee('Bobs Project');
    });

    it('can search deals by title', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $deal1 = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Kitchen Renovation',
            'created_by' => $user->id,
        ]);
        $deal2 = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Bathroom Remodel',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->set('search', 'Kitchen')
            ->assertSee('Kitchen Renovation')
            ->assertDontSee('Bathroom Remodel');
    });

    it('can search deals by customer name', function () {
        $user = User::factory()->internal()->create();
        $customer1 = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Alice',
            'last_name' => 'Wonderland',
        ]);
        $customer2 = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Bob',
            'last_name' => 'Builder',
        ]);

        $deal1 = Deal::factory()->forCustomer($customer1)->create([
            'title' => 'Project Alpha',
            'created_by' => $user->id,
        ]);
        $deal2 = Deal::factory()->forCustomer($customer2)->create([
            'title' => 'Project Beta',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->set('search', 'Alice')
            ->assertSee('Project Alpha')
            ->assertDontSee('Project Beta');
    });

    it('displays deal estimated value', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $deal = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Big Project',
            'estimated_value' => 15000.00,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertSee('$15,000');
    });

    it('displays customer name on deal cards', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ]);

        $deal = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Customer Project',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertSee('Test Customer');
    });

    it('shows empty state when no deals in a stage', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertSee('No deals');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertSet('search', '')
            ->assertSet('assignedToFilter', '')
            ->assertSet('customerFilter', '');
    });

    it('shows deal count in stage header', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        Deal::factory()->forCustomer($customer)->lead()->count(3)->create([
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Pipeline::class)
            ->assertSee('3');
    });
});
