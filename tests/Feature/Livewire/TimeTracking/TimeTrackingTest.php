<?php

use App\Jobs\ReverseGeocodeJob;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Job;
use App\Models\Property;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\LocationIQService;
use App\Services\TimeTrackingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

// ──────────────────────────────────────────────────
// TimeEntry Model Tests
// ──────────────────────────────────────────────────

describe('TimeEntry model', function () {
    it('identifies active entries (clocked in, not out)', function () {
        $entry = TimeEntry::factory()->create([
            'clock_in' => now(),
            'clock_out' => null,
        ]);

        expect($entry->isActive())->toBeTrue();
    });

    it('identifies completed entries (clocked out)', function () {
        $entry = TimeEntry::factory()->completed()->create();

        expect($entry->isActive())->toBeFalse();
    });

    it('identifies on break status', function () {
        $entry = TimeEntry::factory()->onBreak()->create();

        expect($entry->isOnBreak())->toBeTrue();
    });

    it('identifies not on break when break_end is set', function () {
        $entry = TimeEntry::factory()->create([
            'break_start' => now()->subMinutes(30),
            'break_end' => now(),
        ]);

        expect($entry->isOnBreak())->toBeFalse();
    });

    it('calculates total hours for completed entry', function () {
        $entry = TimeEntry::factory()->create([
            'clock_in' => Carbon::parse('2026-02-16 08:00:00'),
            'clock_out' => Carbon::parse('2026-02-16 16:30:00'),
            'break_minutes' => 30,
        ]);

        expect($entry->calculateTotalHours())->toBe(8.0);
    });

    it('calculates total hours returns zero when not clocked out', function () {
        $entry = TimeEntry::factory()->create([
            'clock_in' => now(),
            'clock_out' => null,
        ]);

        expect($entry->calculateTotalHours())->toBe(0.0);
    });

    it('subtracts break minutes from total hours', function () {
        $entry = TimeEntry::factory()->create([
            'clock_in' => Carbon::parse('2026-02-16 07:00:00'),
            'clock_out' => Carbon::parse('2026-02-16 15:00:00'),
            'break_minutes' => 60,
        ]);

        // 8 hours - 1 hour break = 7 hours
        expect($entry->calculateTotalHours())->toBe(7.0);
    });

    it('starts a break', function () {
        $entry = TimeEntry::factory()->create();

        $entry->startBreak();
        $entry->refresh();

        expect($entry->break_start)->not->toBeNull();
    });

    it('ends a break and accumulates break minutes', function () {
        Carbon::setTestNow(Carbon::parse('2026-02-16 12:00:00'));

        $entry = TimeEntry::factory()->create([
            'break_start' => Carbon::parse('2026-02-16 11:30:00'),
            'break_minutes' => 0,
        ]);

        $entry->endBreak();
        $entry->refresh();

        expect($entry->break_end)->not->toBeNull();
        expect($entry->break_minutes)->toBe(30);

        Carbon::setTestNow();
    });

    it('does not end break when not on break', function () {
        $entry = TimeEntry::factory()->create([
            'break_start' => null,
            'break_minutes' => 0,
        ]);

        $entry->endBreak();
        $entry->refresh();

        expect($entry->break_end)->toBeNull();
        expect($entry->break_minutes)->toBe(0);
    });

    it('belongs to employee profile', function () {
        $employee = EmployeeProfile::factory()->create();
        $entry = TimeEntry::factory()->create([
            'employee_profile_id' => $employee->id,
        ]);

        expect($entry->employeeProfile->id)->toBe($employee->id);
    });

    it('belongs to approved by user', function () {
        $approver = User::factory()->internal()->create();
        $entry = TimeEntry::factory()->approved()->create([
            'approved_by' => $approver->id,
        ]);

        expect($entry->approvedBy->id)->toBe($approver->id);
    });
});

// ──────────────────────────────────────────────────
// TimeTrackingService Tests
// ──────────────────────────────────────────────────

describe('TimeTrackingService', function () {
    beforeEach(function () {
        Queue::fake();
        $this->locationIQ = Mockery::mock(LocationIQService::class)->makePartial();
        $this->service = new TimeTrackingService($this->locationIQ);
    });

    it('clocks in an employee', function () {
        $employee = EmployeeProfile::factory()->create();

        $entry = $this->service->clockIn($employee);

        expect($entry)->toBeInstanceOf(TimeEntry::class);
        expect($entry->employee_profile_id)->toBe($employee->id);
        expect($entry->clock_in)->not->toBeNull();
        expect($entry->clock_out)->toBeNull();
        expect($entry->status)->toBe('active');
    });

    it('clocks in with GPS coordinates and dispatches geocode job', function () {
        $employee = EmployeeProfile::factory()->create();

        $entry = $this->service->clockIn($employee, 39.7392, -104.9903);

        expect($entry->clock_in_lat)->not->toBeNull();
        expect($entry->clock_in_lng)->not->toBeNull();

        Queue::assertPushed(ReverseGeocodeJob::class, function ($job) use ($entry) {
            return $job->entryId === $entry->id
                && $job->latitude === 39.7392
                && $job->longitude === -104.9903
                && $job->type === 'clock_in';
        });
    });

    it('clocks out an active entry', function () {
        $employee = EmployeeProfile::factory()->create();
        $entry = $this->service->clockIn($employee);

        Carbon::setTestNow(now()->addHours(8));

        $result = $this->service->clockOut($entry);

        expect($result->clock_out)->not->toBeNull();
        expect($result->status)->toBe('pending');
        expect((float) $result->total_hours)->toBeGreaterThan(0);

        Carbon::setTestNow();
    });

    it('dispatches geocode job on clock out with GPS', function () {
        $employee = EmployeeProfile::factory()->create();
        $entry = $this->service->clockIn($employee);

        Carbon::setTestNow(now()->addHours(8));

        $result = $this->service->clockOut($entry, 39.7500, -104.9950);

        Queue::assertPushed(ReverseGeocodeJob::class, function ($job) use ($result) {
            return $job->entryId === $result->id
                && $job->type === 'clock_out';
        });

        Carbon::setTestNow();
    });

    it('starts and ends a break through service', function () {
        $employee = EmployeeProfile::factory()->create();
        $entry = $this->service->clockIn($employee);

        $result = $this->service->startBreak($entry);
        expect($result->break_start)->not->toBeNull();

        Carbon::setTestNow(now()->addMinutes(30));

        $result = $this->service->endBreak($entry->fresh());
        expect($result->break_end)->not->toBeNull();
        expect($result->break_minutes)->toBeGreaterThan(0);

        Carbon::setTestNow();
    });

    it('gets active entry for employee', function () {
        $employee = EmployeeProfile::factory()->create();

        $entry = $this->service->clockIn($employee);

        $active = $this->service->getActiveEntry($employee);

        expect($active)->not->toBeNull();
        expect($active->id)->toBe($entry->id);
    });

    it('returns null when no active entry exists', function () {
        $employee = EmployeeProfile::factory()->create();

        $active = $this->service->getActiveEntry($employee);

        expect($active)->toBeNull();
    });

    it('approves a time entry', function () {
        $approver = User::factory()->internal()->create();
        $entry = TimeEntry::factory()->completed()->create();

        $this->service->approveEntry($entry, $approver);

        $entry->refresh();

        expect($entry->status)->toBe('approved');
        expect($entry->approved_by)->toBe($approver->id);
        expect($entry->approved_at)->not->toBeNull();
    });

    it('edits a time entry and preserves originals', function () {
        $editor = User::factory()->internal()->create();
        $entry = TimeEntry::factory()->completed()->create([
            'clock_in' => Carbon::parse('2026-02-16 08:00:00'),
            'clock_out' => Carbon::parse('2026-02-16 16:00:00'),
        ]);

        $this->service->editEntry($entry, $editor, [
            'clock_in' => Carbon::parse('2026-02-16 07:30:00'),
            'clock_out' => Carbon::parse('2026-02-16 16:30:00'),
        ]);

        $entry->refresh();

        expect($entry->status)->toBe('edited');
        expect($entry->edited_by)->toBe($editor->id);
        expect($entry->edited_at)->not->toBeNull();
        expect($entry->original_clock_in->toDateTimeString())->toBe('2026-02-16 08:00:00');
        expect($entry->original_clock_out->toDateTimeString())->toBe('2026-02-16 16:00:00');
    });

    it('preserves original clock times on first edit only', function () {
        $editor = User::factory()->internal()->create();
        $entry = TimeEntry::factory()->completed()->create([
            'clock_in' => Carbon::parse('2026-02-16 08:00:00'),
            'clock_out' => Carbon::parse('2026-02-16 16:00:00'),
        ]);

        // First edit
        $this->service->editEntry($entry, $editor, [
            'clock_in' => Carbon::parse('2026-02-16 07:00:00'),
        ]);

        expect($entry->original_clock_in->toDateTimeString())->toBe('2026-02-16 08:00:00');

        // Second edit - originals should remain from first edit
        $this->service->editEntry($entry, $editor, [
            'clock_in' => Carbon::parse('2026-02-16 06:00:00'),
        ]);

        expect($entry->original_clock_in->toDateTimeString())->toBe('2026-02-16 08:00:00');
    });

    it('calculates weekly overtime', function () {
        CompanySetting::getInstance()->update(['overtime_threshold_weekly' => 40]);

        $employee = EmployeeProfile::factory()->create();
        $weekStart = Carbon::parse('2026-02-09')->startOfWeek(Carbon::MONDAY);

        // Create entries totaling 45 hours in the week
        TimeEntry::factory()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => $weekStart->copy()->addHours(8),
            'clock_out' => $weekStart->copy()->addHours(18),
            'total_hours' => 10.0,
        ]);
        TimeEntry::factory()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => $weekStart->copy()->addDay()->addHours(8),
            'clock_out' => $weekStart->copy()->addDay()->addHours(18),
            'total_hours' => 10.0,
        ]);
        TimeEntry::factory()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => $weekStart->copy()->addDays(2)->addHours(8),
            'clock_out' => $weekStart->copy()->addDays(2)->addHours(18),
            'total_hours' => 10.0,
        ]);
        TimeEntry::factory()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => $weekStart->copy()->addDays(3)->addHours(8),
            'clock_out' => $weekStart->copy()->addDays(3)->addHours(18),
            'total_hours' => 10.0,
        ]);
        TimeEntry::factory()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => $weekStart->copy()->addDays(4)->addHours(8),
            'clock_out' => $weekStart->copy()->addDays(4)->addHours(13),
            'total_hours' => 5.0,
        ]);

        $overtime = $this->service->calculateWeeklyOvertime($employee, $weekStart);

        // 45 hours - 40 threshold = 5 hours overtime
        expect($overtime)->toBe(5.0);
    });

    it('returns zero overtime when under threshold', function () {
        CompanySetting::getInstance()->update(['overtime_threshold_weekly' => 40]);

        $employee = EmployeeProfile::factory()->create();
        $weekStart = Carbon::parse('2026-02-09')->startOfWeek(Carbon::MONDAY);

        TimeEntry::factory()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => $weekStart->copy()->addHours(8),
            'clock_out' => $weekStart->copy()->addHours(16),
            'total_hours' => 8.0,
        ]);

        $overtime = $this->service->calculateWeeklyOvertime($employee, $weekStart);

        expect($overtime)->toBe(0.0);
    });
});

// ──────────────────────────────────────────────────
// Geofence Tests
// ──────────────────────────────────────────────────

describe('Geofence checking', function () {
    beforeEach(function () {
        Queue::fake();
        $this->locationIQ = new LocationIQService;
        $this->service = new TimeTrackingService($this->locationIQ);
    });

    it('marks clock in as within geofence when at job site', function () {
        CompanySetting::getInstance()->update(['geofence_radius_feet' => 500]);

        $property = Property::factory()->withCoordinates(39.7392, -104.9903)->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $employee = EmployeeProfile::factory()->create();

        // Clock in at basically the same location (within 500ft)
        $entry = $this->service->clockIn($employee, 39.7392, -104.9903, $job->id);

        expect($entry->clock_in_geofence_status)->toBe('within');
        expect($entry->geofence_distance_ft)->toBeLessThanOrEqual(500);
    });

    it('marks clock in as outside geofence when far from job site', function () {
        CompanySetting::getInstance()->update(['geofence_radius_feet' => 500]);

        $property = Property::factory()->withCoordinates(39.7392, -104.9903)->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $employee = EmployeeProfile::factory()->create();

        // Clock in from a mile away (~5280 ft)
        $entry = $this->service->clockIn($employee, 39.7530, -104.9903, $job->id);

        expect($entry->clock_in_geofence_status)->toBe('outside');
        expect($entry->geofence_distance_ft)->toBeGreaterThan(500);
    });

    it('marks clock out as within geofence when at job site', function () {
        CompanySetting::getInstance()->update(['geofence_radius_feet' => 500]);

        $property = Property::factory()->withCoordinates(39.7392, -104.9903)->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $employee = EmployeeProfile::factory()->create();
        $entry = $this->service->clockIn($employee, 39.7392, -104.9903, $job->id);

        Carbon::setTestNow(now()->addHours(8));

        $result = $this->service->clockOut($entry->fresh(), 39.7392, -104.9903);

        expect($result->clock_out_geofence_status)->toBe('within');

        Carbon::setTestNow();
    });

    it('marks clock out as outside geofence when far from job site', function () {
        CompanySetting::getInstance()->update(['geofence_radius_feet' => 500]);

        $property = Property::factory()->withCoordinates(39.7392, -104.9903)->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $employee = EmployeeProfile::factory()->create();
        $entry = $this->service->clockIn($employee, 39.7392, -104.9903, $job->id);

        Carbon::setTestNow(now()->addHours(8));

        // Clock out from far away
        $result = $this->service->clockOut($entry->fresh(), 39.7530, -104.9903);

        expect($result->clock_out_geofence_status)->toBe('outside');

        Carbon::setTestNow();
    });

    it('skips geofence check when no job is assigned', function () {
        $employee = EmployeeProfile::factory()->create();

        $entry = $this->service->clockIn($employee, 39.7392, -104.9903);

        expect($entry->clock_in_geofence_status)->toBeNull();
        expect($entry->geofence_distance_ft)->toBeNull();
    });

    it('skips geofence check when property has no coordinates', function () {
        $property = Property::factory()->create(); // No coordinates
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $employee = EmployeeProfile::factory()->create();

        $entry = $this->service->clockIn($employee, 39.7392, -104.9903, $job->id);

        expect($entry->clock_in_geofence_status)->toBeNull();
    });

    it('uses company setting for geofence radius', function () {
        CompanySetting::getInstance()->update(['geofence_radius_feet' => 1000]);

        $property = Property::factory()->withCoordinates(39.7392, -104.9903)->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $employee = EmployeeProfile::factory()->create();

        // This point is about 800ft away - outside 500ft but within 1000ft
        $entry = $this->service->clockIn($employee, 39.73945, -104.99030, $job->id);

        expect($entry->clock_in_geofence_status)->toBe('within');
    });
});

// ──────────────────────────────────────────────────
// ReverseGeocodeJob Tests
// ──────────────────────────────────────────────────

describe('ReverseGeocodeJob', function () {
    it('updates clock_in address on entry', function () {
        $entry = TimeEntry::factory()->create([
            'clock_in_lat' => 39.7392,
            'clock_in_lng' => -104.9903,
            'clock_in_address' => null,
        ]);

        $locationIQ = Mockery::mock(LocationIQService::class);
        $locationIQ->shouldReceive('reverseGeocode')
            ->with(39.7392, -104.9903)
            ->once()
            ->andReturn('123 Main St, Denver, CO');

        $job = new ReverseGeocodeJob($entry->id, 39.7392, -104.9903, 'clock_in');
        $job->handle($locationIQ);

        $entry->refresh();
        expect($entry->clock_in_address)->toBe('123 Main St, Denver, CO');
    });

    it('updates clock_out address on entry', function () {
        $entry = TimeEntry::factory()->completed()->create([
            'clock_out_lat' => 39.7500,
            'clock_out_lng' => -104.9950,
            'clock_out_address' => null,
        ]);

        $locationIQ = Mockery::mock(LocationIQService::class);
        $locationIQ->shouldReceive('reverseGeocode')
            ->with(39.7500, -104.9950)
            ->once()
            ->andReturn('456 Oak Ave, Denver, CO');

        $job = new ReverseGeocodeJob($entry->id, 39.7500, -104.9950, 'clock_out');
        $job->handle($locationIQ);

        $entry->refresh();
        expect($entry->clock_out_address)->toBe('456 Oak Ave, Denver, CO');
    });

    it('does nothing if entry no longer exists', function () {
        $locationIQ = Mockery::mock(LocationIQService::class);
        $locationIQ->shouldNotReceive('reverseGeocode');

        $job = new ReverseGeocodeJob(99999, 39.7392, -104.9903, 'clock_in');
        $job->handle($locationIQ);
    });

    it('does not update address if geocode returns null', function () {
        $entry = TimeEntry::factory()->create([
            'clock_in_lat' => 39.7392,
            'clock_in_lng' => -104.9903,
            'clock_in_address' => null,
        ]);

        $locationIQ = Mockery::mock(LocationIQService::class);
        $locationIQ->shouldReceive('reverseGeocode')
            ->andReturn(null);

        $job = new ReverseGeocodeJob($entry->id, 39.7392, -104.9903, 'clock_in');
        $job->handle($locationIQ);

        $entry->refresh();
        expect($entry->clock_in_address)->toBeNull();
    });

    it('has retry configuration', function () {
        $job = new ReverseGeocodeJob(1, 39.7392, -104.9903, 'clock_in');

        expect($job->tries)->toBe(3);
        expect($job->backoff)->toBe([10, 30, 60]);
    });
});

// ──────────────────────────────────────────────────
// LocationIQService Tests
// ──────────────────────────────────────────────────

describe('LocationIQService', function () {
    it('calculates distance between two GPS coordinates', function () {
        $service = new LocationIQService;

        // Denver City Center to Denver International Airport (~23 miles)
        $distance = $service->calculateDistanceFeet(39.7392, -104.9903, 39.8561, -104.6737);

        // Should be approximately 90,000-100,000 feet (17-19 miles)
        expect($distance)->toBeGreaterThan(80000);
        expect($distance)->toBeLessThan(120000);
    });

    it('returns zero distance for same coordinates', function () {
        $service = new LocationIQService;

        $distance = $service->calculateDistanceFeet(39.7392, -104.9903, 39.7392, -104.9903);

        expect($distance)->toBe(0.0);
    });
});
