<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Dashboard component', function () {
    it('renders the dashboard for authenticated customers', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertStatus(200)
            ->assertSee('Welcome');
    });

    it('shows welcome message when no customer record exists', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Welcome to Your Customer Portal')
            ->assertSee('Your account has been set up');
    });

    it('shows no active projects message when customer has no deals', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('No Active Projects')
            ->assertSee('Ready to Get Started?');
    });

    it('displays the status timeline when a deal exists', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'quoted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Estimate')
            ->assertSee('Painting')
            ->assertSee('Invoice');
    });

    it('displays estimates for the selected deal', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'quoted',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
            'total' => 5000.00,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Estimates')
            ->assertSee('EST-2026-00001')
            ->assertSee('Review');
    });

    it('shows action items for sent estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'quoted',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-ACTION-001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Action Needed')
            ->assertSee('Review your estimate');
    });

    it('shows accepted estimate with view button', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'quoted',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
            'estimate_number' => 'EST-ACCEPTED-001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Accepted')
            ->assertSeeInOrder(['Estimates', 'Accepted']);
    });

    it('shows empty estimates state when deal has no estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'lead',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('No estimates yet for this project');
    });

    it('shows job progress when a job exists', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'quoted',
        ]);

        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
        ]);

        Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
            'stage' => 'scheduling_colors',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Job Progress')
            ->assertSee('In Progress')
            ->assertSee('View Details');
    });

    it('shows action items for unconfirmed scheduling', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'quoted',
        ]);

        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
        ]);

        Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
            'stage' => 'scheduling_colors',
            'customer_date_confirmed' => false,
            'customer_colors_confirmed' => false,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Action Needed')
            ->assertSee('Confirm your scheduled date')
            ->assertSee('Confirm your color selections');
    });

    it('shows project switcher when multiple active deals exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal1 = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Kitchen Painting',
            'stage' => 'quoted',
        ]);

        $deal2 = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Bedroom Painting',
            'stage' => 'lead',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Kitchen Painting')
            ->assertSee('Bedroom Painting');
    });

    it('can switch between deals', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal1 = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'First Project',
            'stage' => 'quoted',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal1->id,
            'status' => 'sent',
            'estimate_number' => 'EST-FIRST-001',
        ]);

        $deal2 = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Second Project',
            'stage' => 'lead',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->call('selectDeal', $deal2->id)
            ->assertSet('selectedDealId', $deal2->id)
            ->assertSee('No estimates yet for this project');
    });

    it('shows property info when deal has a property', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main Street',
            'city' => 'Denver',
            'state' => 'CO',
        ]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'stage' => 'quoted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Property')
            ->assertSee('123 Main Street')
            ->assertSee('Denver');
    });

    it('shows lost deal if it has an active job', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Lost But Active Job',
            'stage' => 'lost',
        ]);

        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
        ]);

        Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
            'stage' => 'job_prep',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Lost But Active Job')
            ->assertSee('Job Progress');
    });

    it('shows deals from multiple customer records for same user', function () {
        $customerUser = User::factory()->customer()->create();
        $customer1 = Customer::factory()->create(['user_id' => $customerUser->id]);
        $customer2 = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer1->id,
            'title' => 'Customer 1 Project',
            'stage' => 'quoted',
        ]);

        Deal::factory()->create([
            'customer_id' => $customer2->id,
            'title' => 'Customer 2 Project',
            'stage' => 'lead',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Customer 1 Project')
            ->assertSee('Customer 2 Project');
    });

    it('always shows project switcher even with one deal', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Solo Project',
            'stage' => 'quoted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Projects:')
            ->assertSee('Solo Project');
    });

    it('shows the contact card', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'lead',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Need Help?')
            ->assertSee('Send a Message');
    });
});
