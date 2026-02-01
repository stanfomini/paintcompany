<?php

use App\Livewire\Estimates\Index;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

describe('Estimate Index component', function () {
    it('renders the estimate index component for internal users', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertStatus(200)
            ->assertSee('Estimates');
    });

    it('denies access to non-internal users', function () {
        $user = User::factory()->agent()->create();

        $this->actingAs($user)
            ->get(route('estimates.index'))
            ->assertForbidden();
    });

    it('displays estimates in the list', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'title' => 'Test Estimate Title',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Test Estimate Title')
            ->assertSee($estimate->estimate_number);
    });

    it('can search estimates by number', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $estimate1 = Estimate::factory()->forDeal($deal)->create([
            'title' => 'First Estimate',
            'created_by' => $user->id,
        ]);
        $estimate2 = Estimate::factory()->forDeal($deal)->create([
            'title' => 'Second Estimate',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', $estimate1->estimate_number)
            ->assertSee('First Estimate')
            ->assertDontSee('Second Estimate');
    });

    it('can search estimates by title', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Estimate::factory()->forDeal($deal)->create([
            'title' => 'Living Room Painting',
            'created_by' => $user->id,
        ]);
        Estimate::factory()->forDeal($deal)->create([
            'title' => 'Kitchen Cabinets',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Living Room')
            ->assertSee('Living Room Painting')
            ->assertDontSee('Kitchen Cabinets');
    });

    it('can filter estimates by status', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Draft Estimate',
            'created_by' => $user->id,
        ]);
        Estimate::factory()->forDeal($deal)->sent()->create([
            'title' => 'Sent Estimate',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'draft')
            ->assertSee('Draft Estimate')
            ->assertDontSee('Sent Estimate');
    });

    it('can filter estimates by deal', function () {
        $user = User::factory()->internal()->create();
        $deal1 = Deal::factory()->create(['title' => 'Deal One']);
        $deal2 = Deal::factory()->create(['title' => 'Deal Two']);

        Estimate::factory()->forDeal($deal1)->create([
            'title' => 'Estimate for Deal One',
            'created_by' => $user->id,
        ]);
        Estimate::factory()->forDeal($deal2)->create([
            'title' => 'Estimate for Deal Two',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('dealFilter', $deal1->id)
            ->assertSee('Estimate for Deal One')
            ->assertDontSee('Estimate for Deal Two');
    });

    it('shows all statuses by default', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Draft Estimate',
            'created_by' => $user->id,
        ]);
        Estimate::factory()->forDeal($deal)->sent()->create([
            'title' => 'Sent Estimate',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('statusFilter', 'all')
            ->assertSee('Draft Estimate')
            ->assertSee('Sent Estimate');
    });

    it('resets pagination when search changes', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            Estimate::factory()->forDeal($deal)->create(['created_by' => $user->id]);
        }

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when status filter changes', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            Estimate::factory()->forDeal($deal)->create(['created_by' => $user->id]);
        }

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'draft')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no estimates exist', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('No estimates found');
    });

    it('shows appropriate message when search yields no results', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        Estimate::factory()->forDeal($deal)->create([
            'title' => 'Real Estimate',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'NonexistentEstimate')
            ->assertSee('No estimates found')
            ->assertSee('Try adjusting your search or filter criteria');
    });

    it('displays correct status badges', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Estimate::factory()->forDeal($deal)->draft()->create([
            'title' => 'Draft Test',
            'created_by' => $user->id,
        ]);
        Estimate::factory()->forDeal($deal)->accepted()->create([
            'title' => 'Accepted Test',
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Draft')
            ->assertSee('Accepted');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('search', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('dealFilter', null)
            ->assertSet('perPage', 15);
    });

    it('displays customer name for each estimate', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('John Smith');
    });

    it('displays total amount for each estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        Estimate::factory()->forDeal($deal)->create([
            'total' => 1500.00,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('$1,500.00');
    });
});
