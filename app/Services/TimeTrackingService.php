<?php

namespace App\Services;

use App\Jobs\ReverseGeocodeJob;
use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;

class TimeTrackingService
{
    public function __construct(
        protected LocationIQService $locationIQ,
    ) {}

    /**
     * Clock in an employee.
     */
    public function clockIn(EmployeeProfile $employee, ?float $lat = null, ?float $lng = null, ?int $jobId = null): TimeEntry
    {
        $entry = TimeEntry::create([
            'employee_profile_id' => $employee->id,
            'job_id' => $jobId,
            'clock_in' => now(),
            'clock_in_lat' => $lat,
            'clock_in_lng' => $lng,
            'status' => 'active',
        ]);

        if ($lat !== null && $lng !== null) {
            if ($entry->job_id) {
                $this->checkGeofence($entry, $lat, $lng, 'clock_in');
            }

            ReverseGeocodeJob::dispatch($entry->id, $lat, $lng, 'clock_in');
        }

        return $entry->fresh();
    }

    /**
     * Clock out an active time entry.
     */
    public function clockOut(TimeEntry $entry, ?float $lat = null, ?float $lng = null): TimeEntry
    {
        $entry->update([
            'clock_out' => now(),
            'clock_out_lat' => $lat,
            'clock_out_lng' => $lng,
            'status' => 'pending',
        ]);

        // Calculate and save total hours
        $entry->update([
            'total_hours' => $entry->calculateTotalHours(),
        ]);

        if ($lat !== null && $lng !== null) {
            if ($entry->job_id) {
                $this->checkGeofence($entry, $lat, $lng, 'clock_out');
            }

            ReverseGeocodeJob::dispatch($entry->id, $lat, $lng, 'clock_out');
        }

        return $entry->fresh();
    }

    /**
     * Start a break on an active entry.
     */
    public function startBreak(TimeEntry $entry): TimeEntry
    {
        $entry->startBreak();

        return $entry->fresh();
    }

    /**
     * End a break on an active entry.
     */
    public function endBreak(TimeEntry $entry): TimeEntry
    {
        $entry->endBreak();

        return $entry->fresh();
    }

    /**
     * Calculate weekly overtime hours for an employee.
     */
    public function calculateWeeklyOvertime(EmployeeProfile $employee, Carbon $weekStart): float
    {
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $totalHours = (float) TimeEntry::query()
            ->where('employee_profile_id', $employee->id)
            ->where('clock_in', '>=', $weekStart)
            ->where('clock_in', '<=', $weekEnd)
            ->whereNotNull('total_hours')
            ->sum('total_hours');

        $threshold = (float) CompanySetting::getInstance()->overtime_threshold_weekly;

        return max(0, round($totalHours - $threshold, 2));
    }

    /**
     * Get the currently active time entry for an employee.
     */
    public function getActiveEntry(EmployeeProfile $employee): ?TimeEntry
    {
        return TimeEntry::query()
            ->where('employee_profile_id', $employee->id)
            ->where('status', 'active')
            ->whereNull('clock_out')
            ->latest('clock_in')
            ->first();
    }

    /**
     * Approve a time entry.
     */
    public function approveEntry(TimeEntry $entry, User $approvedBy): void
    {
        $entry->update([
            'status' => 'approved',
            'approved_by' => $approvedBy->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Edit a time entry, saving original values for audit.
     */
    public function editEntry(TimeEntry $entry, User $editedBy, array $changes): void
    {
        // Save originals before editing (only on first edit)
        if (! $entry->original_clock_in) {
            $entry->original_clock_in = $entry->clock_in;
            $entry->original_clock_out = $entry->clock_out;
        }

        $entry->fill($changes);
        $entry->edited_by = $editedBy->id;
        $entry->edited_at = now();
        $entry->status = 'edited';

        // Recalculate total hours if clock times changed
        if (isset($changes['clock_in']) || isset($changes['clock_out'])) {
            $entry->total_hours = $entry->calculateTotalHours();
        }

        $entry->save();
    }

    /**
     * Check geofence distance between the employee's location and the job's property.
     */
    private function checkGeofence(TimeEntry $entry, float $lat, float $lng, string $type): void
    {
        $job = $entry->job;

        if (! $job) {
            return;
        }

        $property = $job->deal?->property;

        if (! $property || ! $property->latitude || ! $property->longitude) {
            return;
        }

        $distance = $this->locationIQ->calculateDistanceFeet(
            $lat, $lng,
            (float) $property->latitude, (float) $property->longitude
        );

        $radius = CompanySetting::getInstance()->geofence_radius_feet ?? 500;
        $status = $distance <= $radius ? 'within' : 'outside';

        $field = $type.'_geofence_status';
        $entry->update([
            $field => $status,
            'geofence_distance_ft' => (int) round($distance),
        ]);
    }
}
