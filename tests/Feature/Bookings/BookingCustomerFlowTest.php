<?php

use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealCollaborator;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Invitation;
use App\Models\Property;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\InvitationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->settings = CompanySetting::firstOrCreate([], [
        'company_name' => 'Test Painting Co',
        'booking_enabled' => true,
        'booking_slot_duration_minutes' => 60,
        'booking_buffer_minutes' => 15,
        'booking_lookahead_days' => 30,
        'booking_min_notice_hours' => 24,
        'business_hours_start' => '08:00',
        'business_hours_end' => '17:00',
        'business_days' => [1, 2, 3, 4, 5],
        'timezone' => 'America/New_York',
    ]);

    $this->estimator = User::factory()->internal()->create();
    $this->profile = EmployeeProfile::factory()->create(['user_id' => $this->estimator->id]);

    $this->settings->update(['bookable_estimator_ids' => [$this->estimator->id]]);
    CompanySetting::clearInstanceCache();

    foreach ([1, 2, 3, 4, 5] as $day) {
        EmployeeAvailability::create([
            'employee_profile_id' => $this->profile->id,
            'day_of_week' => $day,
            'available_from' => '08:00',
            'available_to' => '17:00',
            'is_available' => true,
        ]);
    }

    $this->monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

    $this->prospectData = [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.doe@example.com',
        'phone' => '555-987-6543',
        'address' => '456 Oak Ave',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80202',
        'description' => 'Need exterior painting',
        'estimate_preference' => 'in_person',
    ];
});

describe('bookAppointment creates estimate', function () {
    it('creates a draft estimate linked to the deal', function () {
        $service = app(AppointmentBookingService::class);

        $booking = $service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $deal = Deal::find($booking->deal_id);
        expect($deal)->not->toBeNull();

        $estimate = $deal->estimates()->first();
        expect($estimate)->not->toBeNull()
            ->and($estimate->status)->toBe('draft')
            ->and($estimate->created_by)->toBe($this->estimator->id)
            ->and($estimate->title)->toBe($deal->title)
            ->and($estimate->estimator_mode)->toBe(Estimate::MODE_TEMPLATE);
    });

    it('sets created_by on the deal', function () {
        $service = app(AppointmentBookingService::class);

        $booking = $service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $deal = Deal::find($booking->deal_id);
        expect($deal->created_by)->toBe($this->estimator->id);
    });
});

describe('invitation acceptance preserves onboarding', function () {
    it('keeps needs_onboarding true when accepting booking invitation with deal_id', function () {
        $service = app(AppointmentBookingService::class);

        $booking = $service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $invitation = Invitation::where('email', 'jane.doe@example.com')->first();
        expect($invitation)->not->toBeNull()
            ->and($invitation->deal_id)->not->toBeNull();

        $invitationService = app(InvitationService::class);
        $user = $invitationService->acceptInvitation($invitation, [
            'name' => 'Jane Doe',
            'password' => 'password123',
        ]);

        expect($user->needs_onboarding)->toBeTrue();
    });

    it('still creates DealCollaborator when accepting booking invitation', function () {
        $service = app(AppointmentBookingService::class);

        $booking = $service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $invitation = Invitation::where('email', 'jane.doe@example.com')->first();

        $invitationService = app(InvitationService::class);
        $user = $invitationService->acceptInvitation($invitation, [
            'name' => 'Jane Doe',
            'password' => 'password123',
        ]);

        $customer = Customer::where('user_id', $user->id)->first();
        expect($customer)->not->toBeNull();

        $collaborator = DealCollaborator::where('customer_id', $customer->id)
            ->where('deal_id', $invitation->deal_id)
            ->first();
        expect($collaborator)->not->toBeNull();
    });
});

describe('onboarding for booking customers', function () {
    it('detects booking-sourced customer and sets appointment data', function () {
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '456 Oak Ave',
        ]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'lead_source' => 'vendor_booking',
            'stage' => 'lead',
        ]);

        $booking = AppointmentBooking::factory()->confirmed()->create([
            'deal_id' => $deal->id,
            'customer_id' => $customer->id,
            'estimator_id' => $this->estimator->id,
            'booked_date' => $this->monday->toDateString(),
            'booked_start_time' => '09:00',
            'booked_end_time' => '10:00',
            'prospect_description' => 'Need exterior painting',
        ]);

        $deal->update(['appointment_booking_id' => $booking->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->assertSet('isBookingSourced', true)
            ->assertSet('appointmentDate', $this->monday->format('l, F j, Y'))
            ->assertSet('appointmentTime', '09:00')
            ->assertSet('projectNotes', 'Need exterior painting');
    });

    it('shows appointment confirmation on step 4 for booking customers', function () {
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'phone' => '555-111-2222',
        ]);

        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '789 Pine St',
            'city' => 'Denver',
        ]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'lead_source' => 'vendor_booking',
            'stage' => 'lead',
        ]);

        $booking = AppointmentBooking::factory()->confirmed()->create([
            'deal_id' => $deal->id,
            'customer_id' => $customer->id,
            'estimator_id' => $this->estimator->id,
            'booked_date' => $this->monday->toDateString(),
            'booked_start_time' => '10:00',
            'booked_end_time' => '11:00',
        ]);

        $deal->update(['appointment_booking_id' => $booking->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->set('step', 4)
            ->assertSee('Your Estimate Appointment')
            ->assertSee('10:00')
            ->assertSee('Continue to Dashboard')
            ->assertDontSee('Virtual Estimate')
            ->assertDontSee('In-Person Estimate');
    });

    it('completes onboarding without creating duplicate deal or estimate for booking customer', function () {
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'phone' => '555-111-2222',
        ]);

        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '789 Pine St',
            'city' => 'Denver',
        ]);

        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'lead_source' => 'vendor_booking',
            'stage' => 'lead',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'created_by' => $this->estimator->id,
            'status' => 'draft',
        ]);

        $booking = AppointmentBooking::factory()->confirmed()->create([
            'deal_id' => $deal->id,
            'customer_id' => $customer->id,
            'estimator_id' => $this->estimator->id,
            'booked_date' => $this->monday->toDateString(),
            'booked_start_time' => '10:00',
            'booked_end_time' => '11:00',
        ]);

        $deal->update(['appointment_booking_id' => $booking->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->set('step', 4)
            ->call('complete');

        // Should not create duplicate deals or estimates
        expect($customer->deals()->count())->toBe(1);
        expect($deal->estimates()->count())->toBe(1);

        // Should mark onboarding complete
        $customerUser->refresh();
        expect($customerUser->needs_onboarding)->toBeFalse();
    });
});
