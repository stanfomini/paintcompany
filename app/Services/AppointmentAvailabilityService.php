<?php

namespace App\Services;

use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeProfile;
use App\Models\LifeCommitment;
use App\Models\PtoRequest;
use App\Models\SlotHold;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AppointmentAvailabilityService
{
    /**
     * Get dates that have at least one available slot.
     *
     * @return array<int, array{date: string, day_of_week: string}>
     */
    public function getAvailableDates(?int $lookaheadDays = null): array
    {
        $settings = CompanySetting::getInstance();
        $lookahead = $lookaheadDays ?? $settings->getBookingLookaheadDays();
        $minNotice = $settings->getBookingMinNoticeHours();
        $timezone = $settings->timezone ?? 'America/New_York';

        $start = Carbon::now($timezone)->addHours($minNotice)->startOfDay();
        if ($start->isToday() && Carbon::now($timezone)->addHours($minNotice)->isToday()) {
            $start = Carbon::now($timezone)->addHours($minNotice)->startOfDay();
        }

        $end = Carbon::now($timezone)->addDays($lookahead);
        $dates = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $slots = $this->getAvailableSlotsForDate($date->copy());
            if (count($slots) > 0) {
                $dates[] = [
                    'date' => $date->toDateString(),
                    'day_of_week' => $date->format('l'),
                ];
            }
        }

        return $dates;
    }

    /**
     * Get available time slots for a specific date.
     *
     * @return array<int, array{start: string, end: string, estimator_id: int, estimator_name: string}>
     */
    public function getAvailableSlotsForDate(Carbon $date): array
    {
        $settings = CompanySetting::getInstance();
        $estimators = $this->getBookableEstimators();

        if ($estimators->isEmpty()) {
            return [];
        }

        $businessDays = $settings->business_days ?? [1, 2, 3, 4, 5];
        if (! in_array($date->dayOfWeekIso, $businessDays)) {
            return [];
        }

        $slotDuration = $settings->getBookingSlotDuration();
        $buffer = $settings->getBookingBuffer();
        $minNotice = $settings->getBookingMinNoticeHours();
        $timezone = $settings->timezone ?? 'America/New_York';
        $businessStart = $settings->business_hours_start ?? '08:00';
        $businessEnd = $settings->business_hours_end ?? '17:00';

        $allSlots = [];

        foreach ($estimators as $user) {
            $profile = $user->employeeProfile;
            if (! $profile) {
                continue;
            }

            $freeWindows = $this->getFreeWindows(
                $user,
                $profile,
                $date,
                $businessStart,
                $businessEnd,
            );

            $slots = $this->splitIntoSlots($freeWindows, $slotDuration, $buffer);

            // Filter out slots within min notice hours
            $nowWithNotice = Carbon::now($timezone)->addHours($minNotice);
            $slots = array_filter($slots, function ($slot) use ($date, $nowWithNotice, $timezone) {
                $slotStart = Carbon::parse($date->toDateString().' '.$slot['start'], $timezone);

                return $slotStart->gte($nowWithNotice);
            });

            foreach ($slots as $slot) {
                $allSlots[] = [
                    'start' => $slot['start'],
                    'end' => $slot['end'],
                    'estimator_id' => $user->id,
                    'estimator_name' => $user->name,
                ];
            }
        }

        // Deduplicate time slots — for the same time, pick the estimator with fewest bookings this week
        $grouped = [];
        foreach ($allSlots as $slot) {
            $key = $slot['start'].'-'.$slot['end'];
            $grouped[$key][] = $slot;
        }

        $result = [];
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);

        foreach ($grouped as $slots) {
            if (count($slots) === 1) {
                $result[] = $slots[0];

                continue;
            }

            // Load-balance: pick estimator with fewest bookings this week
            $best = null;
            $bestCount = PHP_INT_MAX;

            foreach ($slots as $slot) {
                $count = AppointmentBooking::query()
                    ->forEstimator($slot['estimator_id'])
                    ->confirmed()
                    ->whereBetween('booked_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                    ->count();

                if ($count < $bestCount) {
                    $bestCount = $count;
                    $best = $slot;
                }
            }

            $result[] = $best;
        }

        // Sort by start time
        usort($result, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return array_values($result);
    }

    /**
     * Check if a specific slot is available for a given estimator.
     */
    public function isSlotAvailable(int $estimatorId, Carbon $date, string $startTime, string $endTime): bool
    {
        $user = User::find($estimatorId);
        if (! $user || ! $user->employeeProfile) {
            return false;
        }

        $settings = CompanySetting::getInstance();

        $businessDays = $settings->business_days ?? [1, 2, 3, 4, 5];
        if (! in_array($date->dayOfWeekIso, $businessDays)) {
            return false;
        }

        $freeWindows = $this->getFreeWindows(
            $user,
            $user->employeeProfile,
            $date,
            $settings->business_hours_start ?? '08:00',
            $settings->business_hours_end ?? '17:00',
        );

        // Check if the requested slot fits within any free window
        foreach ($freeWindows as $window) {
            if ($startTime >= $window['start'] && $endTime <= $window['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a temporary hold on a slot (10 minutes).
     */
    public function holdSlot(string $sessionId, int $estimatorId, Carbon $date, string $startTime, string $endTime): SlotHold
    {
        // Release any existing holds for this session
        $this->releaseHold($sessionId);

        return SlotHold::create([
            'session_id' => $sessionId,
            'estimator_id' => $estimatorId,
            'hold_date' => $date->toDateString(),
            'hold_start_time' => $startTime,
            'hold_end_time' => $endTime,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /**
     * Release all holds for a session.
     */
    public function releaseHold(string $sessionId): void
    {
        SlotHold::forSession($sessionId)->delete();
    }

    /**
     * Get users configured as bookable estimators.
     */
    public function getBookableEstimators(): Collection
    {
        $settings = CompanySetting::getInstance();
        $ids = $settings->getBookableEstimatorIds();

        if (empty($ids)) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('type', 'internal')
            ->with('employeeProfile')
            ->get();
    }

    /**
     * Get free time windows for an estimator on a specific date.
     *
     * @return array<int, array{start: string, end: string}>
     */
    protected function getFreeWindows(User $user, EmployeeProfile $profile, Carbon $date, string $businessStart, string $businessEnd): array
    {
        $dayOfWeek = $date->dayOfWeek;

        // 1. Check employee availability for this day of week
        $availability = EmployeeAvailability::query()
            ->where('employee_profile_id', $profile->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if ($availability && ! $availability->is_available) {
            return [];
        }

        // Intersect employee availability with business hours
        $windowStart = $businessStart;
        $windowEnd = $businessEnd;

        if ($availability) {
            if ($availability->available_from) {
                $windowStart = max($windowStart, $availability->available_from);
            }
            if ($availability->available_to) {
                $windowEnd = min($windowEnd, $availability->available_to);
            }
        }

        if ($windowStart >= $windowEnd) {
            return [];
        }

        // Start with full availability window
        $freeWindows = [['start' => $windowStart, 'end' => $windowEnd]];

        // 2. Check for approved PTO
        $hasPto = PtoRequest::query()
            ->where('employee_profile_id', $profile->id)
            ->approved()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();

        if ($hasPto) {
            return [];
        }

        // 3. Subtract LifeCommitment recurring events
        $commitments = LifeCommitment::active()->get();
        foreach ($commitments as $commitment) {
            if ($this->commitmentOccursOnDate($commitment, $date)) {
                $startTime = $commitment->recurrence_time ?? '09:00';
                $endTime = $commitment->recurrence_end_time ?? Carbon::parse($startTime)->addHour()->format('H:i');
                $freeWindows = $this->subtractBlock($freeWindows, $startTime, $endTime);
            }
        }

        // 5. Subtract existing confirmed appointment bookings
        $bookings = AppointmentBooking::query()
            ->forEstimator($user->id)
            ->confirmed()
            ->forDate($date->toDateString())
            ->get();

        foreach ($bookings as $booking) {
            $freeWindows = $this->subtractBlock($freeWindows, $booking->booked_start_time, $booking->booked_end_time);
        }

        // 6. Subtract active slot holds (not from this session)
        $holds = SlotHold::query()
            ->forEstimator($user->id)
            ->forDate($date->toDateString())
            ->active()
            ->get();

        foreach ($holds as $hold) {
            $freeWindows = $this->subtractBlock($freeWindows, $hold->hold_start_time, $hold->hold_end_time);
        }

        return $freeWindows;
    }

    /**
     * Check if a LifeCommitment occurs on a specific date.
     */
    protected function commitmentOccursOnDate(LifeCommitment $commitment, Carbon $date): bool
    {
        $recurrenceDays = $commitment->recurrence_days ?? [];
        $dayOfMonth = $commitment->recurrence_day_of_month;

        // Check if commitment has started
        if ($commitment->start_date && $date->lt($commitment->start_date)) {
            return false;
        }

        // Weekly recurrence (by day of week)
        if (! empty($recurrenceDays)) {
            $dayName = strtolower($date->format('l'));

            return in_array($dayName, array_map('strtolower', $recurrenceDays));
        }

        // Monthly recurrence (by day of month)
        if ($dayOfMonth) {
            return $date->day === $dayOfMonth;
        }

        return false;
    }

    /**
     * Subtract a blocked time range from free windows.
     *
     * @param  array<int, array{start: string, end: string}>  $windows
     * @return array<int, array{start: string, end: string}>
     */
    protected function subtractBlock(array $windows, string $blockStart, string $blockEnd): array
    {
        $result = [];

        foreach ($windows as $window) {
            // No overlap — block is entirely outside this window
            if ($blockEnd <= $window['start'] || $blockStart >= $window['end']) {
                $result[] = $window;

                continue;
            }

            // Before the block
            if ($blockStart > $window['start']) {
                $result[] = ['start' => $window['start'], 'end' => $blockStart];
            }

            // After the block
            if ($blockEnd < $window['end']) {
                $result[] = ['start' => $blockEnd, 'end' => $window['end']];
            }
        }

        return $result;
    }

    /**
     * Split free windows into fixed-duration slots with buffer gaps.
     *
     * @param  array<int, array{start: string, end: string}>  $windows
     * @return array<int, array{start: string, end: string}>
     */
    protected function splitIntoSlots(array $windows, int $durationMinutes, int $bufferMinutes): array
    {
        $slots = [];

        foreach ($windows as $window) {
            $current = Carbon::parse($window['start']);
            $windowEnd = Carbon::parse($window['end']);

            while ($current->copy()->addMinutes($durationMinutes)->lte($windowEnd)) {
                $slotEnd = $current->copy()->addMinutes($durationMinutes);

                $slots[] = [
                    'start' => $current->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                ];

                $current = $slotEnd->addMinutes($bufferMinutes);
            }
        }

        return $slots;
    }
}
