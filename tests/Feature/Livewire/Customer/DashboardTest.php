<?php

use App\Livewire\Customer\Dashboard;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Dashboard component', function () {
    it('renders the dashboard for authenticated customers', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertStatus(200)
            ->assertSee('Welcome');
    });

    it('shows welcome message when no customer record exists', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertSee('Welcome to Your Customer Portal')
            ->assertSee('Your account has been set up');
    });

    it('displays stats when customer record exists', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Property::factory()->count(3)->create(['customer_id' => $customer->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'in_progress',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertSee('Active Projects')
            ->assertSee('Pending Estimates')
            ->assertSee('Properties');
    });

    it('displays recent projects', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Exterior Painting Project',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertSee('Recent Projects')
            ->assertSee('Exterior Painting Project');
    });

    it('displays pending estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Kitchen Remodel',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
            'total' => 5000.00,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertSee('Estimates Awaiting Response')
            ->assertSee('EST-2026-00001')
            ->assertSee('Kitchen Remodel');
    });

    it('does not display draft estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'draft',
            'estimate_number' => 'EST-DRAFT-001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertDontSee('EST-DRAFT-001');
    });

    it('shows empty state for projects when none exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertSee('No projects yet');
    });

    it('shows empty state for estimates when none pending', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Dashboard::class)
            ->assertSee('No pending estimates');
    });
});
