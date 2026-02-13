<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('Deal Pipeline component', function () {
    it('renders the deal pipeline component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertStatus(200)
            ->assertSee('Active Leads');
    });

    it('allows internal users to view the pipeline', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertStatus(200);
    });

    it('prevents agents from viewing the pipeline', function () {
        $user = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertStatus(403);
    });

    it('displays kanban stage columns', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('New Lead')
            ->assertSee('Quote Scheduled')
            ->assertSee('Quoted');
    });

    it('displays deals in correct stages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
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
            ->test('deals.pipeline')
            ->assertSee('Lead Deal Test')
            ->assertSee('Quoted Deal Test');
    });

    it('can move deal to a new stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Move Me Deal',
            'created_by' => $user->id,
        ]);

        expect($deal->stage)->toBe('lead');

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->call('moveToStage', $deal->id, 'quoted', 0);

        $deal->refresh();
        expect($deal->stage)->toBe('quoted');
        expect($deal->sort_order)->toBe(0);
    });

    it('updates sort order when reordering deals within a stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
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
            ->test('deals.pipeline')
            ->call('moveToStage', $deal2->id, 'quoted', 0);

        $deal1->refresh();
        $deal2->refresh();

        expect($deal2->sort_order)->toBe(0);
        expect($deal1->sort_order)->toBe(1);
    });

    it('reorders existing deals when moving a deal across stages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $existingDeal = Deal::factory()->forCustomer($customer)->quoted()->create([
            'title' => 'Existing Quoted Deal',
            'created_by' => $user->id,
            'sort_order' => 0,
        ]);

        $movingDeal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Moving Deal',
            'created_by' => $user->id,
            'sort_order' => 0,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->call('moveToStage', $movingDeal->id, 'quoted', 0);

        $movingDeal->refresh();
        $existingDeal->refresh();

        expect($movingDeal->stage)->toBe('quoted');
        expect($movingDeal->sort_order)->toBe(0);
        expect($existingDeal->sort_order)->toBe(1);
    });

    it('ignores move to an invalid stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Stay Put Deal',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->call('moveToStage', $deal->id, 'invalid_stage', 0);

        $deal->refresh();
        expect($deal->stage)->toBe('lead');
    });

    it('can filter by assigned user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee1 = User::factory()->internal()->withFullAccess()->create(['name' => 'John Assigned']);
        $assignee2 = User::factory()->internal()->withFullAccess()->create(['name' => 'Jane Assigned']);
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
            ->test('deals.pipeline')
            ->set('assignedToFilter', $assignee1->id)
            ->assertSee('Johns Deal')
            ->assertDontSee('Janes Deal');
    });

    it('can filter by customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
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
            ->test('deals.pipeline')
            ->set('customerFilter', $customer1->id)
            ->assertSee('Alices Project')
            ->assertDontSee('Bobs Project');
    });

    it('can search deals by title', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
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
            ->test('deals.pipeline')
            ->set('search', 'Kitchen')
            ->assertSee('Kitchen Renovation')
            ->assertDontSee('Bathroom Remodel');
    });

    it('can search deals by customer name', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
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
            ->test('deals.pipeline')
            ->set('search', 'Alice')
            ->assertSee('Project Alpha')
            ->assertDontSee('Project Beta');
    });

    it('displays deal estimated value', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        $deal = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Big Project',
            'estimated_value' => 15000.00,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('$15,000');
    });

    it('displays customer name on deal cards', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ]);

        $deal = Deal::factory()->forCustomer($customer)->create([
            'title' => 'Customer Project',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('Test Customer');
    });

    it('shows empty state when no deals in a stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('No deals');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSet('search', '')
            ->assertSet('assignedToFilter', '')
            ->assertSet('customerFilter', '');
    });

    it('shows deal count in stage header', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        Deal::factory()->forCustomer($customer)->lead()->count(3)->create([
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.pipeline')
            ->assertSee('3');
    });
});
