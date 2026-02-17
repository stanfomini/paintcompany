<?php

use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a pay period with factory', function () {
    $period = PayPeriod::factory()->create();

    expect($period->start_date)->not->toBeNull()
        ->and($period->end_date)->not->toBeNull()
        ->and($period->status)->toBe('open');
});

it('checks if period is open', function () {
    $period = PayPeriod::factory()->create(['status' => 'open']);

    expect($period->isOpen())->toBeTrue()
        ->and($period->isCompleted())->toBeFalse();
});

it('checks if period is completed', function () {
    $period = PayPeriod::factory()->completed()->create();

    expect($period->isCompleted())->toBeTrue()
        ->and($period->isOpen())->toBeFalse();
});

it('calculates duration days', function () {
    $period = PayPeriod::factory()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-14',
    ]);

    expect($period->getDurationDays())->toBe(14);
});

it('has many pay stubs', function () {
    $period = PayPeriod::factory()->create();
    PayStub::factory()->count(3)->create(['pay_period_id' => $period->id]);

    expect($period->payStubs)->toHaveCount(3);
});

it('creates employee deduction with factory', function () {
    $deduction = EmployeeDeduction::factory()->create();

    expect($deduction->type)->not->toBeNull()
        ->and($deduction->is_active)->toBeTrue()
        ->and($deduction->is_pretax)->toBeTrue();
});

it('calculates flat deduction amount', function () {
    $deduction = EmployeeDeduction::factory()->create([
        'amount' => 150.00,
        'is_percentage' => false,
    ]);

    expect($deduction->calculateAmount(2000))->toBe(150.00);
});

it('calculates percentage deduction amount', function () {
    $deduction = EmployeeDeduction::factory()->percentage()->create([
        'amount' => 6,
    ]);

    expect($deduction->calculateAmount(2000))->toBe(120.00);
});

it('filters active deductions', function () {
    $employee = EmployeeProfile::factory()->create();
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $employee->id,
        'is_active' => true,
    ]);
    EmployeeDeduction::factory()->inactive()->create([
        'employee_profile_id' => $employee->id,
    ]);

    expect($employee->deductions()->active()->count())->toBe(1);
});

it('filters pretax and posttax deductions', function () {
    $employee = EmployeeProfile::factory()->create();
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $employee->id,
        'is_pretax' => true,
    ]);
    EmployeeDeduction::factory()->posttax()->create([
        'employee_profile_id' => $employee->id,
    ]);

    expect($employee->deductions()->pretax()->count())->toBe(1)
        ->and($employee->deductions()->posttax()->count())->toBe(1);
});

it('creates pay stub with factory', function () {
    $stub = PayStub::factory()->create();

    expect($stub->status)->toBe('draft')
        ->and((float) $stub->gross_pay)->toBeGreaterThan(0)
        ->and((float) $stub->net_pay)->toBeGreaterThan(0);
});

it('checks if pay stub is finalized', function () {
    $draft = PayStub::factory()->create(['status' => 'draft']);
    $finalized = PayStub::factory()->finalized()->create();

    expect($draft->isFinalized())->toBeFalse()
        ->and($finalized->isFinalized())->toBeTrue();
});
