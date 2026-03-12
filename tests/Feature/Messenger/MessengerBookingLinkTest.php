<?php

use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentBookingService;
use App\Services\MessengerLeadCreationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    CompanySetting::clearInstanceCache();

    $this->assignedUser = User::factory()->internal()->create();
    $settings = MessengerSetting::getInstance();
    $settings->update(['default_assigned_to' => $this->assignedUser->id]);
    MessengerSetting::clearInstanceCache();

    // Enable booking by default for all tests
    $companySettings = CompanySetting::getInstance();
    $companySettings->update(['booking_enabled' => true]);
    CompanySetting::clearInstanceCache();

    // Mock the availability service so mount() doesn't require real estimators
    $this->mock(AppointmentAvailabilityService::class, function ($mock) {
        $mock->shouldReceive('getAvailableDates')->andReturn([
            ['date' => now()->addDay()->toDateString(), 'day_of_week' => now()->addDay()->format('l')],
        ]);
        $mock->shouldReceive('getBookableEstimators')->andReturn(collect());
        $mock->shouldReceive('getAvailableSlotsForDate')->andReturn([
            ['start' => '09:00', 'end' => '10:00', 'estimator_id' => 1, 'estimator_name' => 'Test Estimator'],
        ]);
        $mock->shouldReceive('holdSlot')->andReturn(new \App\Models\SlotHold);
        $mock->shouldReceive('releaseHold')->andReturnNull();
        $mock->shouldReceive('isSlotAvailable')->andReturn(true);
    });
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
    CompanySetting::clearInstanceCache();
});

describe('booking token', function () {
    it('generates a valid booking token', function () {
        $conversation = MessengerConversation::factory()->create();

        $token = $conversation->generateBookingToken();

        expect($token)->toHaveLength(64);
        expect($conversation->booking_token)->toBe($token);
        expect($conversation->booking_token_expires_at)->not->toBeNull();
        expect($conversation->isBookingTokenValid())->toBeTrue();
    });

    it('generates booking URL from token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();

        $url = $conversation->getBookingUrl();

        expect($url)->toContain('/book/messenger/');
        expect($url)->toContain($conversation->booking_token);
    });

    it('returns null for booking URL when no token exists', function () {
        $conversation = MessengerConversation::factory()->create();

        expect($conversation->getBookingUrl())->toBeNull();
    });

    it('detects expired booking token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();
        $conversation->update(['booking_token_expires_at' => now()->subDay()]);

        expect($conversation->fresh()->isBookingTokenValid())->toBeFalse();
    });

    it('invalidates booking token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();
        expect($conversation->isBookingTokenValid())->toBeTrue();

        $conversation->invalidateBookingToken();

        expect($conversation->booking_token)->toBeNull();
        expect($conversation->booking_token_expires_at)->toBeNull();
        expect($conversation->isBookingTokenValid())->toBeFalse();
    });
});

describe('public booking page', function () {
    it('loads the calendar booking page with valid token', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'John', 'last_name' => 'Doe'],
        ]);
        $conversation->generateBookingToken();

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('Book Your Free Estimate');
        $response->assertSee('Choose a Date');
    });

    it('shows expired message for invalid token', function () {
        $response = $this->get('/book/messenger/invalidtoken123');

        $response->assertOk();
        $response->assertSee('Link Expired');
    });

    it('shows expired message for expired token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();
        $conversation->update(['booking_token_expires_at' => now()->subDay()]);

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('Link Expired');
    });

    it('shows calendar when conversation already has customer', function () {
        $customer = Customer::factory()->create();
        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $customer->id,
        ]);
        $conversation->generateBookingToken();

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('Book Your Free Estimate');
        $response->assertSee('Choose a Date');
        $response->assertDontSee('Thank You');
    });

    it('pre-fills form with collected data and FB profile', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $step = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);

        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'Jane', 'last_name' => 'Smith'],
            'answers' => ["step_{$step->id}" => 'jane@example.com'],
        ]);
        $conversation->generateBookingToken();

        Livewire\Livewire::test('public.messenger-booking', ['token' => $conversation->booking_token])
            ->assertSet('firstName', 'Jane')
            ->assertSet('lastName', 'Smith')
            ->assertSet('email', 'jane@example.com');
    });

    it('creates appointment booking via submitBooking', function () {
        Notification::fake();

        $estimator = User::factory()->internal()->create();

        $flow = MessengerBotFlow::factory()->active()->create();
        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
        ]);
        $conversation->generateBookingToken();
        $token = $conversation->booking_token;

        $tomorrow = now()->addDay()->toDateString();

        // Mock the booking service to return a real booking
        $mockBooking = AppointmentBooking::factory()->create([
            'estimator_id' => $estimator->id,
            'customer_id' => Customer::factory()->create()->id,
            'deal_id' => Deal::factory()->create()->id,
            'booked_date' => $tomorrow,
            'booked_start_time' => '09:00',
            'booked_end_time' => '10:00',
        ]);

        $this->mock(AppointmentBookingService::class, function ($mock) use ($mockBooking) {
            $mock->shouldReceive('bookAppointment')->once()->andReturn($mockBooking);
        });

        Livewire\Livewire::test('public.messenger-booking', ['token' => $token])
            ->set('selectedDate', $tomorrow)
            ->set('selectedSlot', ['start' => '09:00', 'end' => '10:00', 'estimator_id' => $estimator->id, 'estimator_name' => 'Test'])
            ->set('step', 2)
            ->set('firstName', 'Alice')
            ->set('lastName', 'Wonder')
            ->set('email', 'alice@example.com')
            ->set('phone', '555-111-2222')
            ->set('address', '123 Main St')
            ->call('submitBooking')
            ->assertSet('step', 3)
            ->assertNotSet('bookingResult', null);

        // Conversation should be linked to customer/deal
        $conversation->refresh();
        expect($conversation->customer_id)->toBe($mockBooking->customer_id);
        expect($conversation->deal_id)->toBe($mockBooking->deal_id);
        // Token should NOT be invalidated (allow repeat bookings)
        expect($conversation->booking_token)->not->toBeNull();
    });

    it('rejects honeypot submissions', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();

        Livewire\Livewire::test('public.messenger-booking', ['token' => $conversation->booking_token])
            ->set('firstName', 'Bot')
            ->set('honeypot', 'http://spam.com')
            ->set('selectedDate', now()->addDay()->toDateString())
            ->set('selectedSlot', ['start' => '09:00', 'end' => '10:00', 'estimator_id' => 1, 'estimator_name' => 'Test'])
            ->set('step', 2)
            ->call('submitBooking')
            ->assertSet('step', 2); // step should not advance

        $conversation->refresh();
        expect($conversation->customer_id)->toBeNull();
    });

    it('shows appointment cards for returning customer with existing bookings', function () {
        $customer = Customer::factory()->create();
        $estimator = User::factory()->internal()->create();

        AppointmentBooking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'estimator_id' => $estimator->id,
            'prospect_first_name' => 'Jane',
            'prospect_address' => '456 Oak Ave',
            'prospect_city' => 'Denver',
            'prospect_state' => 'CO',
            'booked_date' => now()->addDays(3),
            'booked_start_time' => '10:00',
        ]);

        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $customer->id,
        ]);
        $conversation->generateBookingToken();

        Livewire\Livewire::test('public.messenger-booking', ['token' => $conversation->booking_token])
            ->assertSee('Your Appointments')
            ->assertSee('Appointment')
            ->assertSee('Jane');
    });

    it('shows job cards for returning customer with active jobs', function () {
        $customer = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '789 Pine St',
            'city' => 'Boulder',
            'state' => 'CO',
        ]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
        ]);
        Job::factory()->inProgress()->create([
            'deal_id' => $deal->id,
            'title' => 'Interior Repaint',
        ]);

        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $customer->id,
        ]);
        $conversation->generateBookingToken();

        Livewire\Livewire::test('public.messenger-booking', ['token' => $conversation->booking_token])
            ->assertSee('Your Appointments')
            ->assertSee('Interior Repaint')
            ->assertSee('In Progress');
    });

    it('blurs address for unauthenticated user', function () {
        $customer = Customer::factory()->create();
        $estimator = User::factory()->internal()->create();

        AppointmentBooking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'estimator_id' => $estimator->id,
            'prospect_address' => '456 Oak Ave',
            'prospect_city' => 'Denver',
            'prospect_state' => 'CO',
            'booked_date' => now()->addDays(3),
        ]);

        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $customer->id,
        ]);
        $conversation->generateBookingToken();

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        // The address should have blur-sm class for unauthenticated users
        $response->assertSee('blur-sm', false);
        // City should be visible
        $response->assertSee('Denver');
    });

    it('shows full address for authenticated customer', function () {
        $customer = Customer::factory()->create();
        $customerUser = User::factory()->create(['type' => 'customer']);
        $estimator = User::factory()->internal()->create();

        AppointmentBooking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'estimator_id' => $estimator->id,
            'prospect_address' => '456 Oak Ave',
            'prospect_city' => 'Denver',
            'prospect_state' => 'CO',
            'booked_date' => now()->addDays(3),
        ]);

        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $customer->id,
        ]);
        $conversation->generateBookingToken();

        $this->actingAs($customerUser, 'customer');
        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('456 Oak Ave');
        // Should NOT have blur on the address when authenticated
        $response->assertDontSee('blur-sm', false);
    });

    it('links conversation to customer and deal on first booking', function () {
        Notification::fake();

        $estimator = User::factory()->internal()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();

        expect($conversation->customer_id)->toBeNull();
        expect($conversation->deal_id)->toBeNull();

        $mockBooking = AppointmentBooking::factory()->create([
            'estimator_id' => $estimator->id,
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'booked_date' => now()->addDay(),
        ]);

        $this->mock(AppointmentBookingService::class, function ($mock) use ($mockBooking) {
            $mock->shouldReceive('bookAppointment')->once()->andReturn($mockBooking);
        });

        $tomorrow = now()->addDay()->toDateString();

        Livewire\Livewire::test('public.messenger-booking', ['token' => $conversation->booking_token])
            ->set('selectedDate', $tomorrow)
            ->set('selectedSlot', ['start' => '09:00', 'end' => '10:00', 'estimator_id' => $estimator->id, 'estimator_name' => 'Test'])
            ->set('step', 2)
            ->set('firstName', 'Test')
            ->set('lastName', 'User')
            ->set('email', 'test@example.com')
            ->set('address', '123 Main St')
            ->call('submitBooking')
            ->assertSet('step', 3);

        $conversation->refresh();
        expect($conversation->customer_id)->toBe($customer->id);
        expect($conversation->deal_id)->toBe($deal->id);
    });

    it('shows unavailable message when booking is disabled', function () {
        $companySettings = CompanySetting::getInstance();
        $companySettings->update(['booking_enabled' => false]);
        CompanySetting::clearInstanceCache();

        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('Online Booking Unavailable');
        $response->assertDontSee('Choose a Date');
    });
});

describe('fb profile fallback', function () {
    it('uses Facebook profile name when bot did not collect name', function () {
        Notification::fake();

        // Flow without a name step
        $flow = MessengerBotFlow::factory()->active()->create();
        MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);

        $steps = $flow->steps()->orderBy('sort_order')->get();

        $conversation = MessengerConversation::factory()->completed()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'Facebook', 'last_name' => 'User'],
            'answers' => ["step_{$steps[0]->id}" => 'fb@example.com'],
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->first_name)->toBe('Facebook');
        expect($result['customer']->last_name)->toBe('User');
    });

    it('prefers bot-collected name over Facebook profile', function () {
        Notification::fake();

        $flow = MessengerBotFlow::factory()->active()->create();
        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);
        $step2 = MessengerBotStep::factory()->textQuestion('customer.last_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
        ]);

        $conversation = MessengerConversation::factory()->completed()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'Facebook', 'last_name' => 'Name'],
            'answers' => [
                "step_{$step1->id}" => 'Bot',
                "step_{$step2->id}" => 'Collected',
            ],
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->first_name)->toBe('Bot');
        expect($result['customer']->last_name)->toBe('Collected');
    });
});

describe('auto-create draft estimate', function () {
    it('creates draft estimate when conversation completes', function () {
        Notification::fake();

        $flow = MessengerBotFlow::factory()->active()->create();
        $step = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);

        $conversation = MessengerConversation::factory()->completed()->create([
            'flow_id' => $flow->id,
            'answers' => ["step_{$step->id}" => 'EstimateTest'],
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result)->toHaveKey('estimate');
        expect($result['estimate'])->toBeInstanceOf(Estimate::class);
        expect($result['estimate']->status)->toBe('draft');
        expect($result['estimate']->deal_id)->toBe($result['deal']->id);
        expect($result['estimate']->estimate_number)->not->toBeEmpty();
    });
});

describe('manual conversion', function () {
    it('creates customer, deal, and estimate from manual conversion', function () {
        Notification::fake();

        $conversation = MessengerConversation::factory()->abandoned()->create();

        $service = new MessengerLeadCreationService;
        $result = $service->createFromManualConversion($conversation, [
            'first_name' => 'Manual',
            'last_name' => 'Convert',
            'email' => 'manual@test.com',
            'phone' => '555-999-8888',
            'notes' => 'Interior repaint needed',
        ]);

        expect($result['customer']->first_name)->toBe('Manual');
        expect($result['customer']->last_name)->toBe('Convert');
        expect($result['deal']->stage)->toBe('lead');
        expect($result['deal']->description)->toBe('Interior repaint needed');
        expect($result['estimate']->status)->toBe('draft');
        expect($result['is_new_customer'])->toBeTrue();

        $conversation->refresh();
        expect($conversation->customer_id)->toBe($result['customer']->id);
        expect($conversation->deal_id)->toBe($result['deal']->id);
    });

    it('links to existing customer when email matches', function () {
        Notification::fake();

        $existing = Customer::factory()->create(['email' => 'existing@test.com']);
        $conversation = MessengerConversation::factory()->abandoned()->create();

        $service = new MessengerLeadCreationService;
        $result = $service->createFromManualConversion($conversation, [
            'first_name' => 'Updated',
            'email' => 'existing@test.com',
        ]);

        expect($result['is_new_customer'])->toBeFalse();
        expect($result['customer']->id)->toBe($existing->id);
    });
});

describe('abandoned conversations', function () {
    it('does not create customer when conversation is abandoned', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
            'status' => 'in_progress',
        ]);

        app(\App\Services\MessengerBotFlowService::class)->abandonConversation($conversation);

        $conversation->refresh();
        expect($conversation->status)->toBe('abandoned');
        expect($conversation->customer_id)->toBeNull();
    });
});
