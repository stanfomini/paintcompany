<?php

namespace App\Services;

use App\Models\EmployeeAvailability;
use App\Models\EmployeeProfile;
use App\Models\PtoRequest;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\PtoRequested;
use App\Notifications\PtoStatusChanged;
use App\Notifications\SchedulePublished;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class SchedulingService
{
    /**
     * Create a new schedule entry after checking for conflicts and availability.
     *
     * @throws InvalidArgumentException
     */
    public function createSchedule(array $data): Schedule
    {
        $conflicts = $this->detectConflicts(
            $data['employee_profile_id'],
            $data['date'],
            $data['start_time'],
            $data['end_time'],
        );

        if (count($conflicts) > 0) {
            throw new InvalidArgumentException('Schedule conflicts with existing entries.');
        }

        $employee = EmployeeProfile::findOrFail($data['employee_profile_id']);

        if (! $this->isAvailable($employee, $data['date'], $data['start_time'], $data['end_time'])) {
            throw new InvalidArgumentException('Employee is not available during this time.');
        }

        $schedule = Schedule::create($data);

        // Notify the employee about the new schedule
        $employee->load('user');
        if ($employee->user) {
            $employee->user->notify(new SchedulePublished($schedule));
        }

        return $schedule;
    }

    /**
     * Detect scheduling conflicts for an employee on a given date and time range.
     *
     * @return array<Schedule>
     */
    public function detectConflicts(int $employeeProfileId, string $date, string $startTime, string $endTime, ?int $excludeId = null): array
    {
        return Schedule::query()
            ->forEmployee($employeeProfileId)
            ->forDate($date)
            ->where('status', '!=', 'cancelled')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->get()
            ->all();
    }

    /**
     * Get the total scheduled hours for an employee in a given week.
     */
    public function getWeeklyHours(EmployeeProfile $employee, Carbon $weekStart): float
    {
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $schedules = Schedule::query()
            ->forEmployee($employee->id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
            ->get();

        return $schedules->sum(fn (Schedule $s) => $s->getScheduledHours());
    }

    /**
     * Check if an employee is approaching overtime (>= 38 hours scheduled).
     */
    public function hasOvertimeRisk(EmployeeProfile $employee, Carbon $weekStart): bool
    {
        return $this->getWeeklyHours($employee, $weekStart) >= 38;
    }

    /**
     * Check if an employee is available for a given date and time range.
     * Checks against their weekly availability and approved PTO.
     */
    public function isAvailable(EmployeeProfile $employee, string $date, string $startTime, string $endTime): bool
    {
        $carbonDate = Carbon::parse($date);
        $dayOfWeek = (int) $carbonDate->dayOfWeek;

        // Check weekly availability
        $availability = EmployeeAvailability::query()
            ->where('employee_profile_id', $employee->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if ($availability) {
            if (! $availability->is_available) {
                return false;
            }

            if ($availability->available_from && $startTime < $availability->available_from) {
                return false;
            }

            if ($availability->available_to && $endTime > $availability->available_to) {
                return false;
            }
        }

        // Check for approved PTO on this date
        $hasPto = PtoRequest::query()
            ->where('employee_profile_id', $employee->id)
            ->approved()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();

        if ($hasPto) {
            return false;
        }

        return true;
    }

    /**
     * Approve a PTO request.
     */
    public function approvePto(PtoRequest $request, User $approvedBy): void
    {
        $request->approve($approvedBy);

        $request->load('employeeProfile.user');
        if ($request->employeeProfile?->user) {
            $request->employeeProfile->user->notify(new PtoStatusChanged($request, 'approved'));
        }
    }

    /**
     * Deny a PTO request.
     */
    public function denyPto(PtoRequest $request, User $deniedBy, string $reason): void
    {
        $request->deny($deniedBy, $reason);

        $request->load('employeeProfile.user');
        if ($request->employeeProfile?->user) {
            $request->employeeProfile->user->notify(new PtoStatusChanged($request, 'denied', $reason));
        }
    }

    /**
     * Request PTO and notify managers.
     */
    public function requestPto(array $data): PtoRequest
    {
        $ptoRequest = PtoRequest::create($data);

        // Notify managers with scheduling.manage permission
        $managers = User::permission('scheduling.manage')->get();
        if ($managers->isNotEmpty()) {
            Notification::send($managers, new PtoRequested($ptoRequest));
        }

        return $ptoRequest;
    }
}
