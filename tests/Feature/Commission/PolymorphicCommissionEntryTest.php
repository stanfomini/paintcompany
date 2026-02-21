<?php

use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets commissionable columns via factory default', function () {
    $entry = CommissionEntry::factory()->create();

    expect($entry->commissionable_type)->toBe(EmployeeProfile::class)
        ->and($entry->commissionable_id)->toBe($entry->employee_profile_id);
});

it('creates entry with forCommissionable state for vendor', function () {
    $vendor = Vendor::factory()->create();
    $entry = CommissionEntry::factory()
        ->forCommissionable($vendor)
        ->create();

    expect($entry->commissionable_type)->toBe(Vendor::class)
        ->and($entry->commissionable_id)->toBe($vendor->id)
        ->and($entry->employee_profile_id)->toBeNull();
});

it('creates entry with forCommissionable state for user', function () {
    $user = User::factory()->create();
    $entry = CommissionEntry::factory()
        ->forCommissionable($user)
        ->create();

    expect($entry->commissionable_type)->toBe(User::class)
        ->and($entry->commissionable_id)->toBe($user->id)
        ->and($entry->employee_profile_id)->toBeNull();
});

it('resolves commissionable morphTo relationship', function () {
    $vendor = Vendor::factory()->create();
    $entry = CommissionEntry::factory()
        ->forCommissionable($vendor)
        ->create();

    $entry->refresh();
    expect($entry->commissionable)->toBeInstanceOf(Vendor::class)
        ->and($entry->commissionable->id)->toBe($vendor->id);
});

it('allows null employee_profile_id', function () {
    $user = User::factory()->create();
    $entry = CommissionEntry::factory()
        ->forCommissionable($user)
        ->create();

    expect($entry->employee_profile_id)->toBeNull();
    // Verify it persisted correctly
    $fresh = CommissionEntry::find($entry->id);
    expect($fresh->employee_profile_id)->toBeNull();
});

it('dual-writes employee_profile_id and commissionable for employees', function () {
    $profile = EmployeeProfile::factory()->create();
    $service = app(CommissionService::class);
    $entry = $service->createManual($profile, 100.00, 'flat', 'Test');

    expect($entry->employee_profile_id)->toBe($profile->id)
        ->and($entry->commissionable_type)->toBe(EmployeeProfile::class)
        ->and($entry->commissionable_id)->toBe($profile->id);
});

it('scopes forCommissionable correctly', function () {
    $vendor = Vendor::factory()->create();
    $user = User::factory()->create();

    CommissionEntry::factory()->forCommissionable($vendor)->create();
    CommissionEntry::factory()->forCommissionable($vendor)->create();
    CommissionEntry::factory()->forCommissionable($user)->create();

    expect(CommissionEntry::forCommissionable($vendor)->count())->toBe(2)
        ->and(CommissionEntry::forCommissionable($user)->count())->toBe(1);
});

it('keeps existing forEmployee scope working', function () {
    $profile = EmployeeProfile::factory()->create();
    CommissionEntry::factory()->create(['employee_profile_id' => $profile->id]);

    expect(CommissionEntry::forEmployee($profile->id)->count())->toBe(1);
});
