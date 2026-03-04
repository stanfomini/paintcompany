<?php

use App\Exceptions\SlotUnavailableException;
use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeProfile;
use App\Models\Property;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\AppointmentCancelledNotification;
use App\Notifications\NewAppointmentNotification;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->service = app(AppointmentBookingService::class);

    // Create company settings
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

    // Create estimator with profile and availability
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
        'first_name' => 'John',
        'last_name' => 'Smith',
        'email' => 'john.smith@example.com',
        'phone' => '555-123-4567',
        'address' => '123 Main St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80202',
        'description' => 'Need interior painting estimate',
        'estimate_preference' => 'in_person',
    ];
});

describe('bookAppointment', function () {
    it('creates all related records for a full booking', function () {
        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        expect($booking)->toBeInstanceOf(AppointmentBooking::class)
            ->and($booking->status)->toBe(AppointmentBooking::STATUS_CONFIRMED)
            ->and($booking->estimator_id)->toBe($this->estimator->id)
            ->and($booking->prospect_first_name)->toBe('John')
            ->and($booking->prospect_email)->toBe('john.smith@example.com')
            ->and($booking->booked_start_time)->toBe('09:00')
            ->and($booking->booking_token)->not->toBeEmpty();

        // Customer created
        expect($booking->customer_id)->not->toBeNull();
        $this->assertDatabaseHas('customers', ['email' => 'john.smith@example.com']);

        // Property created
        $this->assertDatabaseHas('properties', ['address' => '123 Main St']);

        // Deal created
        expect($booking->deal_id)->not->toBeNull();
        $deal = Deal::find($booking->deal_id);
        expect($deal->stage)->toBe('lead')
            ->and($deal->lead_source)->toBe('vendor_booking')
            ->and($deal->assigned_to)->toBe($this->estimator->id)
            ->and($deal->appointment_booking_id)->toBe($booking->id);

        // No schedule entry created (appointments show via UnifiedCalendarService)
        expect($booking->schedule_id)->toBeNull();
    });

    it('sends notifications to customer and estimator', function () {
        $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        Notification::assertSentTo(
            $this->estimator,
            NewAppointmentNotification::class,
        );
    });

    it('throws SlotUnavailableException when slot is taken', function () {
        // Block the slot with an existing confirmed booking
        AppointmentBooking::factory()->confirmed()->create([
            'estimator_id' => $this->estimator->id,
            'booked_date' => $this->monday->toDateString(),
            'booked_start_time' => '09:00',
            'booked_end_time' => '11:00',
        ]);

        expect(fn () => $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        ))->toThrow(SlotUnavailableException::class);
    });

    it('reuses existing customer with same email', function () {
        Customer::factory()->create([
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'email' => 'john.smith@example.com',
            'status' => 'active',
        ]);

        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $customers = Customer::where('email', 'john.smith@example.com')->get();
        expect($customers)->toHaveCount(1)
            ->and($customers->first()->first_name)->toBe('Existing');
    });

    it('attributes booking to vendor when provided', function () {
        $vendor = Vendor::factory()->create([
            'booking_enabled' => true,
            'booking_slug' => 'test-vendor',
        ]);

        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
            $vendor,
            'vendor',
        );

        expect($booking->vendor_id)->toBe($vendor->id)
            ->and($booking->booked_by)->toBe('vendor');

        $deal = Deal::find($booking->deal_id);
        expect($deal->origin_vendor_id)->toBe($vendor->id);
    });

    it('succeeds when session has a slot hold for the same slot', function () {
        $sessionId = 'test-session-123';

        // Create a slot hold (simulates step 1 of booking flow)
        $availabilityService = app(\App\Services\AppointmentAvailabilityService::class);
        $availabilityService->holdSlot(
            $sessionId,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        // Booking should succeed — the hold is released before checking availability
        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
            null,
            'prospect',
            $sessionId,
        );

        expect($booking)->toBeInstanceOf(AppointmentBooking::class)
            ->and($booking->status)->toBe(AppointmentBooking::STATUS_CONFIRMED);

        // Slot hold should be deleted
        expect(\App\Models\SlotHold::where('session_id', $sessionId)->count())->toBe(0);
    });

    it('prevents double-booking same slot', function () {
        $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        expect(fn () => $this->service->bookAppointment(
            array_merge($this->prospectData, ['email' => 'other@example.com']),
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        ))->toThrow(SlotUnavailableException::class);
    });
});

describe('cancelBooking', function () {
    it('cancels a booking and notifies estimator', function () {
        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $result = $this->service->cancelBooking($booking->booking_token, 'Changed my mind');

        expect($result->status)->toBe(AppointmentBooking::STATUS_CANCELLED)
            ->and($result->cancelled_at)->not->toBeNull()
            ->and($result->cancellation_reason)->toBe('Changed my mind');

        // Estimator should be notified
        Notification::assertSentTo(
            $this->estimator,
            AppointmentCancelledNotification::class,
        );
    });

    it('does not re-cancel already cancelled booking', function () {
        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $this->service->cancelBooking($booking->booking_token);
        $result = $this->service->cancelBooking($booking->booking_token);

        expect($result->status)->toBe(AppointmentBooking::STATUS_CANCELLED);
    });
});

describe('rescheduleBooking', function () {
    it('creates new booking and marks old as rescheduled', function () {
        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $tuesday = $this->monday->copy()->addDay();

        $newBooking = $this->service->rescheduleBooking(
            $booking->booking_token,
            $tuesday,
            '14:00',
            '15:00',
            $this->estimator->id,
        );

        // Old booking rescheduled
        $booking->refresh();
        expect($booking->status)->toBe(AppointmentBooking::STATUS_RESCHEDULED);

        // New booking created
        expect($newBooking)->toBeInstanceOf(AppointmentBooking::class)
            ->and($newBooking->id)->not->toBe($booking->id)
            ->and($newBooking->booked_date->toDateString())->toBe($tuesday->toDateString())
            ->and($newBooking->booked_start_time)->toBe('14:00')
            ->and($newBooking->prospect_email)->toBe('john.smith@example.com');
    });
});

describe('markCompleted and markNoShow', function () {
    it('marks booking as completed', function () {
        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $this->service->markCompleted($booking);

        $booking->refresh();
        expect($booking->status)->toBe(AppointmentBooking::STATUS_COMPLETED);
    });

    it('marks booking as no-show', function () {
        $booking = $this->service->bookAppointment(
            $this->prospectData,
            $this->estimator->id,
            $this->monday,
            '09:00',
            '10:00',
        );

        $this->service->markNoShow($booking);

        $booking->refresh();
        expect($booking->status)->toBe(AppointmentBooking::STATUS_NO_SHOW);
    });
});
