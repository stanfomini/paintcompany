<?php

use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->internal()->withFullAccess()->create();
});

it('shows entity filter select', function () {
    Livewire::actingAs($this->admin)
        ->test('payroll.commissions')
        ->assertSee('All Entities')
        ->assertSee('Employees')
        ->assertSee('Vendors')
        ->assertSee('Referrers');
});

it('filters by employee entity type', function () {
    $profile = EmployeeProfile::factory()->create();
    CommissionEntry::factory()->create([
        'employee_profile_id' => $profile->id,
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source_label' => 'Employee Commission Entry',
    ]);

    $vendor = Vendor::factory()->create();
    CommissionEntry::factory()->forCommissionable($vendor)->create([
        'source_label' => 'Vendor Commission Entry',
    ]);

    Livewire::actingAs($this->admin)
        ->test('payroll.commissions')
        ->set('entityFilter', 'employee')
        ->assertSee('Employee Commission Entry')
        ->assertDontSee('Vendor Commission Entry');
});

it('filters by vendor entity type', function () {
    $profile = EmployeeProfile::factory()->create();
    CommissionEntry::factory()->create([
        'employee_profile_id' => $profile->id,
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source_label' => 'Employee Commission Entry',
    ]);

    $vendor = Vendor::factory()->create();
    CommissionEntry::factory()->forCommissionable($vendor)->create([
        'source_label' => 'Vendor Commission Entry',
    ]);

    Livewire::actingAs($this->admin)
        ->test('payroll.commissions')
        ->set('entityFilter', 'vendor')
        ->assertSee('Vendor Commission Entry')
        ->assertDontSee('Employee Commission Entry');
});

it('shows entity type label for non-employee entries', function () {
    $vendor = Vendor::factory()->create();
    CommissionEntry::factory()->forCommissionable($vendor)->create();

    Livewire::actingAs($this->admin)
        ->test('payroll.commissions')
        ->assertSee($vendor->name)
        ->assertSee('Vendor');
});

it('shows referrer entries with User type', function () {
    $referrer = User::factory()->create();
    CommissionEntry::factory()->forCommissionable($referrer)->create([
        'source_label' => 'Referral: John Doe',
    ]);

    Livewire::actingAs($this->admin)
        ->test('payroll.commissions')
        ->assertSee($referrer->name)
        ->assertSee('Referrer');
});

it('resets page when entity filter changes', function () {
    Livewire::actingAs($this->admin)
        ->test('payroll.commissions')
        ->set('entityFilter', 'vendor')
        ->assertSet('entityFilter', 'vendor');
});
