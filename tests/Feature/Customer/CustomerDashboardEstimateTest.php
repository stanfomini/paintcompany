<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->forCustomer($this->customer)->create();
});

describe('Dashboard - Estimate CTA Card', function () {
    it('shows estimate CTA with price button for sent estimates', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create([
            'estimate_number' => 'EST-2026-00001',
            'total' => 3000.00,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Your Painting Estimate')
            ->assertSee('EST-2026-00001')
            ->assertSee('Tap to view your full proposal');
    });

    it('shows estimate CTA with "Help Build Your Estimate" for draft estimates', function () {
        Estimate::factory()->forDeal($this->deal)->draft()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Your Painting Estimate')
            ->assertSee('Help Build Your Estimate');
    });

    it('shows estimate CTA with "View Your Estimate" for accepted estimates', function () {
        Estimate::factory()->forDeal($this->deal)->accepted()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Your Painting Estimate')
            ->assertSee('View Your Estimate');
    });

    it('shows total price for sent estimates', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create(['total' => 5250.00]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('$5,250.00');
    });

    it('shows "Tap to view your full proposal" for sent estimates', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create(['total' => 1000.00]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Tap to view your full proposal');
    });

    it('opens estimate overlay on button click for draft estimates', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->draft()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->call('openEstimateOverlay', $estimate->id)
            ->assertSet('showAreaCarousel', true)
            ->assertSet('carouselEstimateId', $estimate->id);
    });

    it('closes estimate overlay', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->call('openEstimateOverlay', $estimate->id)
            ->assertSet('showAreaCarousel', true)
            ->call('closeEstimateOverlay')
            ->assertSet('showAreaCarousel', false)
            ->assertSet('carouselEstimateId', null);
    });
});

describe('Dashboard - Schedule Card', function () {
    it('shows schedule section even without estimates', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Schedule')
            ->assertSee('Book a time to discuss your project')
            ->assertSee('Propose a Meeting');
    });

    it('shows schedule section with an estimate', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Schedule')
            ->assertSee('Propose a Meeting');
    });

    it('shows upcoming meetings in schedule card', function () {
        Activity::create([
            'user_id' => $this->customerUser->id,
            'subject_type' => Deal::class,
            'subject_id' => $this->deal->id,
            'type' => 'meeting',
            'title' => 'Walk-through appointment',
            'due_at' => now()->addDays(3),
            'meeting_type' => 'in_person',
            'meeting_status' => 'proposed',
            'proposed_by' => $this->customerUser->id,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Walk-through appointment')
            ->assertSee('Proposed');
    });

    it('validates meeting form fields', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->set('showScheduleModal', true)
            ->call('proposeMeeting')
            ->assertHasErrors(['meetingTitle', 'meetingDateTime']);
    });
});

describe('Dashboard - Action Items', function () {
    it('shows Review Your Estimate button for sent estimates', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Review Your Estimate');
    });
});

describe('Dashboard - Empty States', function () {
    it('shows no projects message when no deals exist', function () {
        // Delete the deal we created in beforeEach
        $this->deal->delete();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('No Active Projects');
    });
});
