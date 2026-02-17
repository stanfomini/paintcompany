<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// === Deal Assignment Commission Triggers ===

it('creates lead commission when deal is assigned', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->withLeadCommission('flat', 25)
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => null]);

    // Assign the deal - this should trigger commission via model event
    $deal->update(['assigned_to' => $user->id]);

    $entries = CommissionEntry::where('employee_profile_id', $employee->id)
        ->where('source_type', Deal::class)
        ->where('source_id', $deal->id)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->amount)->toBe(25.00)
        ->and($entries->first()->commission_type)->toBe('flat')
        ->and($entries->first()->status)->toBe('pending');
});

it('creates lead commission when deal is claimed (assigned_to set)', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->withLeadCommission('flat', 50)
        ->create(['user_id' => $user->id]);

    // Create deal without assignment
    $deal = Deal::factory()->create(['assigned_to' => null]);

    // Simulate claiming (setting assigned_to for first time via update)
    $deal->update(['assigned_to' => $user->id]);

    expect(CommissionEntry::where('employee_profile_id', $employee->id)->count())->toBe(1);
});

it('prevents duplicate commission on deal re-assignment', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->withLeadCommission('flat', 25)
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => null]);

    // First assignment
    $deal->update(['assigned_to' => $user->id]);
    expect(CommissionEntry::where('employee_profile_id', $employee->id)->count())->toBe(1);

    // Unassign and reassign same user
    $deal->update(['assigned_to' => null]);
    $deal->update(['assigned_to' => $user->id]);

    // Should still be 1 entry (duplicate prevention)
    expect(CommissionEntry::where('employee_profile_id', $employee->id)->count())->toBe(1);
});

it('does not create lead commission when assigned_to does not change', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->withLeadCommission('flat', 25)
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);

    // Update something else on the deal
    $deal->update(['title' => 'Updated Title']);

    expect(CommissionEntry::count())->toBe(0);
});

// === Job Completion Commission Triggers ===

it('creates job commission on completeJob()', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->withJobCommission('revenue_percentage', 0.03)
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
        'total' => 10000,
        'status' => 'accepted',
    ]);
    $job = Job::factory()->inProgress()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    app(JobService::class)->completeJob($job);

    $entries = CommissionEntry::where('employee_profile_id', $employee->id)
        ->where('source_type', Job::class)
        ->where('source_id', $job->id)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->amount)->toBe(300.00)
        ->and($entries->first()->commission_type)->toBe('revenue_percentage');
});

it('prevents duplicate commission on double completeJob()', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->withJobCommission('revenue_percentage', 0.03)
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
        'total' => 10000,
        'status' => 'accepted',
    ]);
    $job = Job::factory()->inProgress()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $service = app(JobService::class);
    $service->completeJob($job);

    // Reset job state and try to complete again
    $job->update(['stage' => 'in_progress', 'actual_end_date' => null]);
    $service->completeJob($job);

    expect(CommissionEntry::where('source_type', Job::class)
        ->where('source_id', $job->id)
        ->count()
    )->toBe(1);
});

// === auto_trigger Flag ===

it('respects auto_trigger false to prevent automatic commission', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true, 'auto_trigger' => false],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => null]);
    $deal->update(['assigned_to' => $user->id]);

    // Should NOT create commission because auto_trigger is false
    expect(CommissionEntry::count())->toBe(0);
});

it('auto_trigger defaults to true for backward compatibility', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->commissioned([
            // No auto_trigger key at all - should default to true
            ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => null]);
    $deal->update(['assigned_to' => $user->id]);

    expect(CommissionEntry::count())->toBe(1);
});

it('manual commission still works when auto_trigger is false', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true, 'auto_trigger' => false],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);

    // Manual trigger should still work (uses all active rules, not just auto)
    $service = new CommissionService;
    $entries = $service->applyRulesForSource($employee, 'lead', $deal, 0, 0, autoOnly: false);

    expect($entries)->toHaveCount(1)
        ->and((float) $entries[0]->amount)->toBe(25.00);
});

// === Estimate Commission Regression ===

it('estimate commission still fires on signature (regression)', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->withEstimateCommission('revenue_percentage', 0.05)
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
        'total' => 10000,
        'status' => 'accepted',
    ]);

    $service = new CommissionService;
    $entries = $service->createFromEstimate($estimate);

    expect($entries)->toHaveCount(1)
        ->and((float) $entries[0]->amount)->toBe(500.00)
        ->and($entries[0]->commission_type)->toBe('revenue_percentage');
});

// === hasDuplicateCommission Method ===

it('hasDuplicateCommission returns false for reversed entries', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->withLeadCommission('flat', 25)
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);

    // Create a reversed commission entry
    CommissionEntry::create([
        'employee_profile_id' => $employee->id,
        'source_type' => Deal::class,
        'source_id' => $deal->id,
        'source_label' => 'Lead: Test',
        'commission_type' => 'flat',
        'basis_amount' => 0,
        'rate' => 0,
        'amount' => 25,
        'status' => 'reversed',
        'earned_date' => now()->toDateString(),
    ]);

    $service = new CommissionService;
    expect($service->hasDuplicateCommission($employee, $deal, 'lead'))->toBeFalse();
});
