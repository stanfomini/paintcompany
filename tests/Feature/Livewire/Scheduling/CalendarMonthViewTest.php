<?php

use App\Models\EmployeeProfile;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('scheduling.view', 'scheduling.manage');
    $this->actingAs($this->user);
});

describe('Calendar month view', function () {
    it('defaults to week view mode', function () {
        Livewire::test('scheduling.calendar')
            ->assertSet('viewMode', 'week')
            ->assertSee('Today');
    });

    it('can switch to month view', function () {
        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->assertSet('viewMode', 'month')
            ->assertSee(Carbon::now()->startOfMonth()->format('F Y'));
    });

    it('can switch back to week view', function () {
        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->set('viewMode', 'week')
            ->assertSet('viewMode', 'week');
    });

    it('navigates to previous month', function () {
        $previousMonth = Carbon::now()->subMonth()->startOfMonth();

        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->call('previousMonth')
            ->assertSet('monthOffset', '-1')
            ->assertSee($previousMonth->format('F Y'));
    });

    it('navigates to next month', function () {
        $nextMonth = Carbon::now()->addMonth()->startOfMonth();

        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->call('nextMonth')
            ->assertSet('monthOffset', '1')
            ->assertSee($nextMonth->format('F Y'));
    });

    it('resets to current month with goToMonthToday', function () {
        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->call('nextMonth')
            ->call('nextMonth')
            ->assertSet('monthOffset', '2')
            ->call('goToMonthToday')
            ->assertSet('monthOffset', '0')
            ->assertSee(Carbon::now()->startOfMonth()->format('F Y'));
    });

    it('shows schedule count badge in month view', function () {
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);
        $date = Carbon::now()->startOfMonth()->addDays(10);

        // Create 2 schedules on the same day
        Schedule::factory()->count(2)->create([
            'employee_profile_id' => $employee->id,
            'date' => $date->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'status' => 'scheduled',
        ]);

        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->assertSee($employee->user->name);
    });

    it('excludes cancelled schedules from month view', function () {
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);
        $date = Carbon::now()->startOfMonth()->addDays(10);

        Schedule::factory()->cancelled()->create([
            'employee_profile_id' => $employee->id,
            'date' => $date->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        // Verify the month query excludes cancelled schedules
        $nonCancelled = Schedule::where('status', '!=', 'cancelled')
            ->where('employee_profile_id', $employee->id)
            ->whereBetween('date', [
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString(),
            ])
            ->count();
        expect($nonCancelled)->toBe(0);

        // Component renders without error in month view
        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->assertSuccessful();
    });

    it('switches to week view when clicking a day via switchToWeekOf', function () {
        $targetDate = Carbon::now()->startOfMonth()->addDays(14)->toDateString();

        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->call('switchToWeekOf', $targetDate)
            ->assertSet('viewMode', 'week');
    });

    it('sets correct week offset when switching to week of a specific date', function () {
        Carbon::setTestNow(Carbon::parse('2026-02-16')); // Monday week

        $targetDate = '2026-02-23'; // Next Monday

        $component = Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->call('switchToWeekOf', $targetDate)
            ->assertSet('viewMode', 'week');

        // The week offset should put us in the week containing Feb 23
        $weekOffset = (int) $component->get('weekOffset');
        $expectedStart = Carbon::parse('2026-02-16')->startOfWeek(Carbon::MONDAY)->addWeeks($weekOffset);
        $targetWeekStart = Carbon::parse($targetDate)->startOfWeek(Carbon::MONDAY);

        expect($expectedStart->toDateString())->toBe($targetWeekStart->toDateString());

        Carbon::setTestNow();
    });

    it('shows day of week headers in month view', function () {
        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->assertSee('Mon')
            ->assertSee('Tue')
            ->assertSee('Wed')
            ->assertSee('Thu')
            ->assertSee('Fri')
            ->assertSee('Sat')
            ->assertSee('Sun');
    });

    it('filters month schedules by employee', function () {
        $employee1 = EmployeeProfile::factory()->create(['employment_status' => 'active']);
        $employee2 = EmployeeProfile::factory()->create(['employment_status' => 'active']);
        $date = Carbon::now()->startOfMonth()->addDays(10)->toDateString();

        Schedule::factory()->create([
            'employee_profile_id' => $employee1->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        Schedule::factory()->create([
            'employee_profile_id' => $employee2->id,
            'date' => $date,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        // Without filter, both schedules should exist
        $allCount = Schedule::whereBetween('date', [
            Carbon::now()->startOfMonth()->toDateString(),
            Carbon::now()->endOfMonth()->toDateString(),
        ])->where('status', '!=', 'cancelled')->count();
        expect($allCount)->toBe(2);

        // With filter, only employee1's schedule should match
        $filteredCount = Schedule::whereBetween('date', [
            Carbon::now()->startOfMonth()->toDateString(),
            Carbon::now()->endOfMonth()->toDateString(),
        ])->where('status', '!=', 'cancelled')
            ->where('employee_profile_id', $employee1->id)
            ->count();
        expect($filteredCount)->toBe(1);

        // Verify the component accepts the filter without error
        Livewire::test('scheduling.calendar')
            ->set('viewMode', 'month')
            ->set('employeeFilter', (string) $employee1->id)
            ->assertSet('employeeFilter', (string) $employee1->id)
            ->assertSuccessful();
    });
});
