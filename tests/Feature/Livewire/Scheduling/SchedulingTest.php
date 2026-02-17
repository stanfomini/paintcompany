<?php

use App\Models\EmployeeAvailability;
use App\Models\EmployeeProfile;
use App\Models\PtoRequest;
use App\Models\Schedule;
use App\Models\User;
use App\Services\SchedulingService;
use Carbon\Carbon;

// ──────────────────────────────────────────────────
// Schedule Model Tests
// ──────────────────────────────────────────────────

describe('Schedule model', function () {
    it('creates a schedule with default values', function () {
        $schedule = Schedule::factory()->create();

        expect($schedule->status)->toBe('scheduled');
        expect($schedule->break_duration)->toBe(30);
    });

    it('identifies confirmed schedules', function () {
        $schedule = Schedule::factory()->confirmed()->create();

        expect($schedule->isConfirmed())->toBeTrue();
    });

    it('identifies unconfirmed schedules', function () {
        $schedule = Schedule::factory()->create(['status' => 'scheduled']);

        expect($schedule->isConfirmed())->toBeFalse();
    });

    it('calculates scheduled hours excluding break', function () {
        $schedule = Schedule::factory()->create([
            'start_time' => '08:00',
            'end_time' => '16:30',
            'break_duration' => 30,
        ]);

        expect($schedule->getScheduledHours())->toBe(8.0);
    });

    it('detects overlapping schedules on same date for same employee', function () {
        $employee = EmployeeProfile::factory()->create();
        $date = now()->addDay()->toDateString();

        $schedule1 = Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $schedule2 = Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => $date,
            'start_time' => '14:00',
            'end_time' => '22:00',
        ]);

        expect($schedule1->overlaps($schedule2))->toBeTrue();
    });

    it('does not detect overlap on different dates', function () {
        $employee = EmployeeProfile::factory()->create();

        $schedule1 = Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $schedule2 = Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => now()->addDays(2)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        expect($schedule1->overlaps($schedule2))->toBeFalse();
    });

    it('does not detect overlap for different employees', function () {
        $emp1 = EmployeeProfile::factory()->create();
        $emp2 = EmployeeProfile::factory()->create();
        $date = now()->addDay()->toDateString();

        $schedule1 = Schedule::factory()->create([
            'employee_profile_id' => $emp1->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $schedule2 = Schedule::factory()->create([
            'employee_profile_id' => $emp2->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        expect($schedule1->overlaps($schedule2))->toBeFalse();
    });

    it('scopes upcoming schedules', function () {
        Schedule::factory()->create(['date' => now()->addDay()]);
        Schedule::factory()->create(['date' => now()->subDay()]);

        expect(Schedule::upcoming()->count())->toBe(1);
    });

    it('scopes for specific date', function () {
        $targetDate = now()->addDay()->toDateString();
        Schedule::factory()->create(['date' => $targetDate]);
        Schedule::factory()->create(['date' => now()->addDays(5)->toDateString()]);

        expect(Schedule::forDate($targetDate)->count())->toBe(1);
    });

    it('scopes for specific employee', function () {
        $employee = EmployeeProfile::factory()->create();
        Schedule::factory()->create(['employee_profile_id' => $employee->id]);
        Schedule::factory()->create(); // Different employee

        expect(Schedule::forEmployee($employee->id)->count())->toBe(1);
    });
});

// ──────────────────────────────────────────────────
// PtoRequest Model Tests
// ──────────────────────────────────────────────────

describe('PtoRequest model', function () {
    it('creates a pending PTO request by default', function () {
        $request = PtoRequest::factory()->create();

        expect($request->status)->toBe('pending');
        expect($request->isPending())->toBeTrue();
    });

    it('calculates total days inclusive', function () {
        $request = PtoRequest::factory()->create([
            'start_date' => '2026-02-16',
            'end_date' => '2026-02-18',
        ]);

        expect($request->getTotalDays())->toBe(3);
    });

    it('calculates single day request', function () {
        $request = PtoRequest::factory()->singleDay()->create();

        expect($request->getTotalDays())->toBe(1);
    });

    it('approves a PTO request', function () {
        $request = PtoRequest::factory()->create();
        $approver = User::factory()->internal()->create();

        $request->approve($approver);
        $request->refresh();

        expect($request->status)->toBe('approved');
        expect($request->approved_by)->toBe($approver->id);
        expect($request->approved_at)->not->toBeNull();
    });

    it('denies a PTO request with reason', function () {
        $request = PtoRequest::factory()->create();
        $denier = User::factory()->internal()->create();

        $request->deny($denier, 'Short staffed that week');
        $request->refresh();

        expect($request->status)->toBe('denied');
        expect($request->approved_by)->toBe($denier->id);
        expect($request->denial_reason)->toBe('Short staffed that week');
    });

    it('scopes pending requests', function () {
        PtoRequest::factory()->create(['status' => 'pending']);
        PtoRequest::factory()->approved()->create();
        PtoRequest::factory()->denied()->create();

        expect(PtoRequest::pending()->count())->toBe(1);
    });

    it('scopes approved requests', function () {
        PtoRequest::factory()->create(['status' => 'pending']);
        PtoRequest::factory()->approved()->create();

        expect(PtoRequest::approved()->count())->toBe(1);
    });

    it('scopes for date range', function () {
        PtoRequest::factory()->create([
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-15',
        ]);
        PtoRequest::factory()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-05',
        ]);

        $inRange = PtoRequest::forDateRange('2026-02-01', '2026-02-28')->get();

        expect($inRange)->toHaveCount(1);
    });
});

// ──────────────────────────────────────────────────
// SchedulingService Tests
// ──────────────────────────────────────────────────

describe('SchedulingService', function () {
    it('creates a schedule without conflicts', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();

        $schedule = $service->createSchedule([
            'employee_profile_id' => $employee->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'break_duration' => 30,
            'status' => 'scheduled',
        ]);

        expect($schedule)->toBeInstanceOf(Schedule::class);
        expect($schedule->employee_profile_id)->toBe($employee->id);
    });

    it('throws exception when schedule conflicts exist', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $date = now()->addDay()->toDateString();

        // Create an existing schedule
        Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        // Try to create overlapping schedule
        expect(fn () => $service->createSchedule([
            'employee_profile_id' => $employee->id,
            'date' => $date,
            'start_time' => '14:00',
            'end_time' => '22:00',
            'break_duration' => 30,
            'status' => 'scheduled',
        ]))->toThrow(InvalidArgumentException::class, 'Schedule conflicts');
    });

    it('detects conflicts for overlapping time ranges', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $date = now()->addDay()->toDateString();

        Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $conflicts = $service->detectConflicts($employee->id, $date, '14:00', '22:00');

        expect($conflicts)->toHaveCount(1);
    });

    it('returns no conflicts for non-overlapping time ranges', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $date = now()->addDay()->toDateString();

        Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $conflicts = $service->detectConflicts($employee->id, $date, '13:00', '17:00');

        expect($conflicts)->toBeEmpty();
    });

    it('ignores cancelled schedules in conflict detection', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $date = now()->addDay()->toDateString();

        Schedule::factory()->cancelled()->create([
            'employee_profile_id' => $employee->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $conflicts = $service->detectConflicts($employee->id, $date, '08:00', '16:00');

        expect($conflicts)->toBeEmpty();
    });

    it('calculates weekly hours for an employee', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $weekStart = Carbon::parse('next monday');

        // Create 5 days of 8-hour schedules with 30-min breaks = 7.5 hrs/day
        for ($i = 0; $i < 5; $i++) {
            Schedule::factory()->create([
                'employee_profile_id' => $employee->id,
                'date' => $weekStart->copy()->addDays($i)->toDateString(),
                'start_time' => '08:00',
                'end_time' => '16:00',
                'break_duration' => 30,
            ]);
        }

        $hours = $service->getWeeklyHours($employee, $weekStart);

        // 5 days * 7.5 hours = 37.5 hours
        expect($hours)->toBe(37.5);
    });

    it('detects overtime risk when approaching threshold', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $weekStart = Carbon::parse('next monday');

        // Create 5 days of 8-hour schedules = 40 hours
        for ($i = 0; $i < 5; $i++) {
            Schedule::factory()->create([
                'employee_profile_id' => $employee->id,
                'date' => $weekStart->copy()->addDays($i)->toDateString(),
                'start_time' => '08:00',
                'end_time' => '16:00',
                'break_duration' => 0,
            ]);
        }

        expect($service->hasOvertimeRisk($employee, $weekStart))->toBeTrue();
    });

    it('returns no overtime risk when under threshold', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $weekStart = Carbon::parse('next monday');

        Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => $weekStart->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'break_duration' => 0,
        ]);

        expect($service->hasOvertimeRisk($employee, $weekStart))->toBeFalse();
    });

    it('checks availability against employee schedule', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();

        // Set employee as unavailable on Sundays (day_of_week = 0)
        EmployeeAvailability::factory()->unavailable()->create([
            'employee_profile_id' => $employee->id,
            'day_of_week' => 0,
        ]);

        // Find next Sunday
        $sunday = Carbon::now()->next(Carbon::SUNDAY);

        expect($service->isAvailable($employee, $sunday->toDateString(), '08:00', '16:00'))->toBeFalse();
    });

    it('blocks scheduling when employee has approved PTO', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();
        $date = now()->addWeek()->toDateString();

        PtoRequest::factory()->approved()->create([
            'employee_profile_id' => $employee->id,
            'start_date' => $date,
            'end_date' => $date,
        ]);

        expect($service->isAvailable($employee, $date, '08:00', '16:00'))->toBeFalse();
    });

    it('throws exception when employee is unavailable', function () {
        $service = new SchedulingService;
        $employee = EmployeeProfile::factory()->create();

        // Set employee as unavailable on Sundays
        EmployeeAvailability::factory()->unavailable()->create([
            'employee_profile_id' => $employee->id,
            'day_of_week' => 0,
        ]);

        $sunday = Carbon::now()->next(Carbon::SUNDAY);

        expect(fn () => $service->createSchedule([
            'employee_profile_id' => $employee->id,
            'date' => $sunday->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'break_duration' => 30,
            'status' => 'scheduled',
        ]))->toThrow(InvalidArgumentException::class, 'not available');
    });

    it('approves PTO through service', function () {
        $service = new SchedulingService;
        $request = PtoRequest::factory()->create();
        $approver = User::factory()->internal()->create();

        $service->approvePto($request, $approver);

        $request->refresh();

        expect($request->status)->toBe('approved');
        expect($request->approved_by)->toBe($approver->id);
    });

    it('denies PTO through service', function () {
        $service = new SchedulingService;
        $request = PtoRequest::factory()->create();
        $denier = User::factory()->internal()->create();

        $service->denyPto($request, $denier, 'Peak season');

        $request->refresh();

        expect($request->status)->toBe('denied');
        expect($request->denial_reason)->toBe('Peak season');
    });
});
