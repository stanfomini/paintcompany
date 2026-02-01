<?php

use App\Livewire\Customer\Projects\Show;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Projects Show component', function () {
    it('renders the project details for authenticated customer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Kitchen Painting Project',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $deal])
            ->assertStatus(200)
            ->assertSee('Kitchen Painting Project');
    });

    it('displays project details', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Master Bedroom',
            'description' => 'Complete repaint of master bedroom',
            'stage' => 'in_progress',
            'estimated_value' => 2500.00,
            'expected_start_date' => '2026-02-01',
            'expected_end_date' => '2026-02-15',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $deal])
            ->assertSee('Master Bedroom')
            ->assertSee('Complete repaint of master bedroom')
            ->assertSee('In Progress')
            ->assertSee('$2,500.00')
            ->assertSee('Feb 1, 2026')
            ->assertSee('Feb 15, 2026');
    });

    it('cannot view another customers project', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();
        $otherDeal = Deal::factory()->create(['customer_id' => $otherCustomer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $otherDeal])
            ->assertNotFound();
    });

    it('displays linked property information', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'type' => 'residential',
            'square_footage' => 2500,
        ]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $deal])
            ->assertSee('Property')
            ->assertSee('123 Main Street')
            ->assertSee('Springfield')
            ->assertSee('IL')
            ->assertSee('residential')
            ->assertSee('2,500 sq ft');
    });

    it('displays estimates for the project', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
            'total' => 3500.00,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $deal])
            ->assertSee('Estimates')
            ->assertSee('EST-2026-00001')
            ->assertSee('$3,500.00')
            ->assertSee('Action Required');
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
            ->test(Show::class, ['deal' => $deal])
            ->assertDontSee('EST-DRAFT-001');
    });

    it('displays activity timeline', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Activity::factory()->forDeal($deal)->create([
            'type' => 'note',
            'title' => 'Project update',
            'description' => 'Work is progressing well',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $deal])
            ->assertSee('Activity Timeline')
            ->assertSee('Project update')
            ->assertSee('Work is progressing well');
    });

    it('shows empty state for activities when none exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $deal])
            ->assertSee('No activity recorded yet');
    });

    it('shows empty state for estimates when none exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['deal' => $deal])
            ->assertSee('No estimates available yet');
    });
});
