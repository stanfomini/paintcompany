<?php

use App\Models\EmployeeProfile;
use App\Models\PayStub;
use App\Models\User;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// Self-Service Route Access Tests
// ──────────────────────────────────────────────────

describe('Employee self-service routes', function () {
    it('employee can access /my/schedule', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.schedule'))
            ->assertSuccessful();
    });

    it('employee can access /my/timesheet', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.timesheet'))
            ->assertSuccessful();
    });

    it('employee can access /my/pay-stubs', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.pay-stubs'))
            ->assertSuccessful();
    });

    it('employee can access /my/contracts', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.contracts'))
            ->assertSuccessful();
    });

    it('employee can access /my/pto', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.pto'))
            ->assertSuccessful();
    });

    it('non-employee cannot access self-service routes', function () {
        $user = User::factory()->internal()->create();
        // No employee profile created

        $this->actingAs($user)
            ->get(route('my.schedule'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('my.timesheet'))
            ->assertForbidden();
    });
});

// ──────────────────────────────────────────────────
// Pay Stub Ownership Tests
// ──────────────────────────────────────────────────

describe('Pay stub self-access', function () {
    it('employee can view their own pay stub', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $payStub = PayStub::factory()->create(['employee_profile_id' => $employee->id]);

        Livewire::actingAs($user)
            ->test('payroll.pay-stub', ['payStub' => $payStub])
            ->assertSuccessful();
    });

    it('employee cannot view another employees pay stub', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $otherEmployee = EmployeeProfile::factory()->create();
        $otherPayStub = PayStub::factory()->create(['employee_profile_id' => $otherEmployee->id]);

        Livewire::actingAs($user)
            ->test('payroll.pay-stub', ['payStub' => $otherPayStub])
            ->assertForbidden();
    });
});

// ──────────────────────────────────────────────────
// Sidebar Visibility Tests
// ──────────────────────────────────────────────────

describe('My Work sidebar group', function () {
    it('shows My Work group for employees', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->onboarded()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('My Work')
            ->assertSee('My Schedule')
            ->assertSee('My Timesheet')
            ->assertSee('My Pay Stubs')
            ->assertSee('My Contracts')
            ->assertSee('My PTO');
    });

    it('does not show My Work group for non-employees', function () {
        $user = User::factory()->internal()->create();
        // No employee profile

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertDontSee('My Work');
    });
});
