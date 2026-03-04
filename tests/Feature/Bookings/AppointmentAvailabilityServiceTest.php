<?php

use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeProfile;
use App\Models\PtoRequest;
use App\Models\Schedule;
use App\Models\SlotHold;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use Carbon\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new AppointmentAvailabilityService;

    // Create company settings with booking enabled
    $this->settings = CompanySetting::firstOrCreate([], [
        'company_name' => 'Test Painting Co',
        'booking_enabled' => true,
        'booking_slot_duration_minutes' => 60,
        'booking_buffer_minutes' => 15,
        'booking_lookahead_days' => 30,
        'booking_min_notice_hours' => 24,
        'business_hours_start' => '08:00',
        'business_hours_end' => '17:00',
        'business_days' => [1, 2, 3, 4, 5], // Mon-Fri
        'timezone' => 'America/New_York',
    ]);

    // Create a bookable estimator with employee profile
    $this->estimator = User::factory()->internal()->create();
    $this->profile = EmployeeProfile::factory()->create(['user_id' => $this->estimator->id]);

    // Set bookable estimator IDs
    $this->settings->update(['bookable_estimator_ids' => [$this->estimator->id]]);
    CompanySetting::clearInstanceCache();

    // Set availability for weekdays (Mon-Fri)
    foreach ([1, 2, 3, 4, 5] as $day) {
        EmployeeAvailability::create([
            'employee_profile_id' => $this->profile->id,
            'day_of_week' => $day,
            'available_from' => '08:00',
            'available_to' => '17:00',
            'is_available' => true,
        ]);
    }
});

describe('getAvailableSlotsForDate', function () {
    it('generates slots from employee availability windows', function () {
        // Use a known Monday
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $slots = $this->service->getAvailableSlotsForDate($monday);

        expect($slots)->not->toBeEmpty()
            ->and($slots[0])->toHaveKeys(['start', 'end', 'estimator_id', 'estimator_name'])
            ->and($slots[0]['estimator_id'])->toBe($this->estimator->id);
    });

    it('returns no slots on weekends', function () {
        $saturday = Carbon::now()->next(Carbon::SATURDAY);

        $slots = $this->service->getAvailableSlotsForDate($saturday);

        expect($slots)->toBeEmpty();
    });

    it('does not subtract schedule entries from available slots', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $slotsBefore = $this->service->getAvailableSlotsForDate($monday);

        // Add a schedule entry — this represents the employee working, NOT a conflict
        Schedule::create([
            'employee_profile_id' => $this->profile->id,
            'schedule_type' => 'job',
            'date' => $monday->toDateString(),
            'start_time' => '08:00',
            'end_time' => '12:00',
            'status' => 'confirmed',
        ]);

        $slotsAfter = $this->service->getAvailableSlotsForDate($monday);

        // Schedule entries should NOT reduce available slots
        expect(count($slotsAfter))->toBe(count($slotsBefore));
    });

    it('blocks entire day when employee has approved PTO', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        PtoRequest::create([
            'employee_profile_id' => $this->profile->id,
            'type' => 'vacation',
            'start_date' => $monday->toDateString(),
            'end_date' => $monday->toDateString(),
            'hours' => 8,
            'status' => 'approved',
        ]);

        $slots = $this->service->getAvailableSlotsForDate($monday);

        expect($slots)->toBeEmpty();
    });

    it('subtracts confirmed appointment bookings', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        AppointmentBooking::factory()->create([
            'estimator_id' => $this->estimator->id,
            'booked_date' => $monday->toDateString(),
            'booked_start_time' => '09:00',
            'booked_end_time' => '10:00',
            'status' => 'confirmed',
        ]);

        $slots = $this->service->getAvailableSlotsForDate($monday);

        // No slot should have start=09:00
        $nineAmSlots = collect($slots)->filter(fn ($s) => $s['start'] === '09:00');
        expect($nineAmSlots)->toBeEmpty();
    });

    it('does not subtract cancelled bookings', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        // First check what slots are available without the booking
        $slotsBefore = $this->service->getAvailableSlotsForDate($monday);
        $firstSlotStart = $slotsBefore[0]['start'];

        $booking = AppointmentBooking::factory()->cancelled()->create([
            'estimator_id' => $this->estimator->id,
            'booked_date' => $monday->toDateString(),
            'booked_start_time' => $firstSlotStart,
            'booked_end_time' => Carbon::parse($firstSlotStart)->addHour()->format('H:i'),
        ]);

        expect($booking->status)->toBe(AppointmentBooking::STATUS_CANCELLED);

        $slotsAfter = $this->service->getAvailableSlotsForDate($monday);

        // The slot should still be available since the booking is cancelled
        expect(count($slotsAfter))->toBe(count($slotsBefore));
    });

    it('subtracts active slot holds', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        SlotHold::create([
            'session_id' => 'test-session',
            'estimator_id' => $this->estimator->id,
            'hold_date' => $monday->toDateString(),
            'hold_start_time' => '10:00',
            'hold_end_time' => '11:00',
            'expires_at' => now()->addMinutes(10),
        ]);

        $slots = $this->service->getAvailableSlotsForDate($monday);

        $tenAmSlots = collect($slots)->filter(fn ($s) => $s['start'] === '10:00');
        expect($tenAmSlots)->toBeEmpty();
    });

    it('does not subtract expired slot holds', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        // Get available slots first
        $slotsBefore = $this->service->getAvailableSlotsForDate($monday);
        $firstSlotStart = $slotsBefore[0]['start'];

        SlotHold::create([
            'session_id' => 'test-session',
            'estimator_id' => $this->estimator->id,
            'hold_date' => $monday->toDateString(),
            'hold_start_time' => $firstSlotStart,
            'hold_end_time' => Carbon::parse($firstSlotStart)->addHour()->format('H:i'),
            'expires_at' => now()->subMinute(),
        ]);

        $slotsAfter = $this->service->getAvailableSlotsForDate($monday);

        // Expired hold should not block the slot
        expect(count($slotsAfter))->toBe(count($slotsBefore));
    });

    it('enforces buffer between slots', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $slots = $this->service->getAvailableSlotsForDate($monday);

        // With 60 min slots and 15 min buffer, consecutive slots should be 75 min apart
        if (count($slots) >= 2) {
            $firstEnd = Carbon::parse($slots[0]['end']);
            $secondStart = Carbon::parse($slots[1]['start']);
            $gap = $firstEnd->diffInMinutes($secondStart);

            expect($gap)->toBeGreaterThanOrEqual(15);
        }
    });

    it('returns no slots when no estimators are configured', function () {
        $this->settings->update(['bookable_estimator_ids' => []]);
        CompanySetting::clearInstanceCache();

        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);
        $slots = $this->service->getAvailableSlotsForDate($monday);

        expect($slots)->toBeEmpty();
    });

    it('returns no slots when employee is unavailable on that day', function () {
        // Mark Monday as unavailable
        EmployeeAvailability::where('employee_profile_id', $this->profile->id)
            ->where('day_of_week', 1)
            ->update(['is_available' => false]);

        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);
        $slots = $this->service->getAvailableSlotsForDate($monday);

        expect($slots)->toBeEmpty();
    });
});

describe('isSlotAvailable', function () {
    it('returns true for an available slot', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $result = $this->service->isSlotAvailable(
            $this->estimator->id,
            $monday,
            '09:00',
            '10:00',
        );

        expect($result)->toBeTrue();
    });

    it('returns false when slot conflicts with existing booking', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        AppointmentBooking::factory()->confirmed()->create([
            'estimator_id' => $this->estimator->id,
            'booked_date' => $monday->toDateString(),
            'booked_start_time' => '09:00',
            'booked_end_time' => '11:00',
        ]);

        $result = $this->service->isSlotAvailable(
            $this->estimator->id,
            $monday,
            '09:00',
            '10:00',
        );

        expect($result)->toBeFalse();
    });

    it('returns false for non-existent estimator', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $result = $this->service->isSlotAvailable(9999, $monday, '09:00', '10:00');

        expect($result)->toBeFalse();
    });
});

describe('holdSlot', function () {
    it('creates a slot hold record', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $hold = $this->service->holdSlot(
            'test-session-123',
            $this->estimator->id,
            $monday,
            '09:00',
            '10:00',
        );

        expect($hold)->toBeInstanceOf(SlotHold::class)
            ->and($hold->session_id)->toBe('test-session-123')
            ->and($hold->estimator_id)->toBe($this->estimator->id)
            ->and($hold->hold_start_time)->toBe('09:00')
            ->and($hold->expires_at)->not->toBeNull();

        $this->assertDatabaseHas('slot_holds', [
            'session_id' => 'test-session-123',
            'estimator_id' => $this->estimator->id,
        ]);
    });

    it('releases existing hold for same session before creating new one', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $this->service->holdSlot('session-1', $this->estimator->id, $monday, '09:00', '10:00');
        $this->service->holdSlot('session-1', $this->estimator->id, $monday, '10:00', '11:00');

        expect(SlotHold::where('session_id', 'session-1')->count())->toBe(1);
        expect(SlotHold::where('session_id', 'session-1')->first()->hold_start_time)->toBe('10:00');
    });
});

describe('releaseHold', function () {
    it('deletes all holds for a session', function () {
        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        $this->service->holdSlot('session-to-release', $this->estimator->id, $monday, '09:00', '10:00');
        expect(SlotHold::where('session_id', 'session-to-release')->count())->toBe(1);

        $this->service->releaseHold('session-to-release');
        expect(SlotHold::where('session_id', 'session-to-release')->count())->toBe(0);
    });
});

describe('getBookableEstimators', function () {
    it('returns configured estimators', function () {
        $estimators = $this->service->getBookableEstimators();

        expect($estimators)->toHaveCount(1)
            ->and($estimators->first()->id)->toBe($this->estimator->id);
    });

    it('returns empty when no estimators configured', function () {
        $this->settings->update(['bookable_estimator_ids' => []]);
        CompanySetting::clearInstanceCache();

        $estimators = $this->service->getBookableEstimators();

        expect($estimators)->toBeEmpty();
    });
});

describe('getAvailableDates', function () {
    it('returns dates with available slots', function () {
        $dates = $this->service->getAvailableDates(7);

        expect($dates)->not->toBeEmpty();

        foreach ($dates as $dateInfo) {
            expect($dateInfo)->toHaveKeys(['date', 'day_of_week']);
        }
    });

    it('excludes weekends', function () {
        $dates = $this->service->getAvailableDates(14);

        foreach ($dates as $dateInfo) {
            $dayOfWeek = Carbon::parse($dateInfo['date'])->dayOfWeek;
            expect($dayOfWeek)->not->toBe(Carbon::SATURDAY)
                ->and($dayOfWeek)->not->toBe(Carbon::SUNDAY);
        }
    });
});

describe('multi-estimator load balancing', function () {
    it('assigns slot to estimator with fewest bookings this week', function () {
        $estimator2 = User::factory()->internal()->create();
        $profile2 = EmployeeProfile::factory()->create(['user_id' => $estimator2->id]);

        $this->settings->update(['bookable_estimator_ids' => [$this->estimator->id, $estimator2->id]]);
        CompanySetting::clearInstanceCache();

        // Set availability for estimator 2
        foreach ([1, 2, 3, 4, 5] as $day) {
            EmployeeAvailability::create([
                'employee_profile_id' => $profile2->id,
                'day_of_week' => $day,
                'available_from' => '08:00',
                'available_to' => '17:00',
                'is_available' => true,
            ]);
        }

        $monday = Carbon::now()->addWeek()->next(Carbon::MONDAY);

        // Give estimator 1 more bookings this week
        AppointmentBooking::factory()->count(3)->create([
            'estimator_id' => $this->estimator->id,
            'booked_date' => $monday->toDateString(),
            'booked_start_time' => '14:00',
            'booked_end_time' => '15:00',
            'status' => 'confirmed',
        ]);

        $slots = $this->service->getAvailableSlotsForDate($monday);

        // For slots where both are available, estimator2 should be preferred (fewer bookings)
        $eightAmSlot = collect($slots)->firstWhere('start', '08:00');
        if ($eightAmSlot) {
            expect($eightAmSlot['estimator_id'])->toBe($estimator2->id);
        }
    });
});
