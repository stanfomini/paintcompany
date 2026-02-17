<?php

use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\User;
use Livewire\Livewire;

it('loads for employee with commissions', function () {
    $employee = EmployeeProfile::factory()->commissioned()->create();
    CommissionEntry::factory()->count(3)->create([
        'employee_profile_id' => $employee->id,
    ]);

    $this->actingAs($employee->user)
        ->get(route('my.commissions'))
        ->assertSuccessful()
        ->assertSee('My Commissions');
});

it('is forbidden without employee profile', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('my.commissions'))
        ->assertForbidden();
});

it('only shows own commissions', function () {
    $employee1 = EmployeeProfile::factory()->commissioned()->create();
    $employee2 = EmployeeProfile::factory()->commissioned()->create();

    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee1->id,
        'source_label' => 'My Own Entry',
        'amount' => 100,
    ]);

    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee2->id,
        'source_label' => 'Other Employee Entry',
        'amount' => 200,
    ]);

    Livewire::actingAs($employee1->user)
        ->test('payroll.my-commissions')
        ->assertSee('My Own Entry')
        ->assertDontSee('Other Employee Entry');
});

it('shows correct summary card totals', function () {
    $employee = EmployeeProfile::factory()->commissioned()->create();

    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
        'amount' => 150.00,
        'earned_date' => now(),
    ]);

    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'approved',
        'amount' => 250.00,
        'earned_date' => now(),
        'approved_by' => User::factory()->internal(),
        'approved_at' => now(),
    ]);

    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'paid',
        'amount' => 300.00,
        'earned_date' => now(),
        'approved_by' => User::factory()->internal(),
        'approved_at' => now()->subDay(),
    ]);

    $component = Livewire::actingAs($employee->user)
        ->test('payroll.my-commissions');

    $summary = $component->get('summaryCards');

    expect($summary['pending'])->toBe(150.0)
        ->and($summary['approved'])->toBe(250.0)
        ->and($summary['paidThisMonth'])->toBe(300.0)
        ->and($summary['ytdTotal'])->toBe(550.0); // approved + paid
});

it('filters by status', function () {
    $employee = EmployeeProfile::factory()->commissioned()->create();

    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
        'source_label' => 'Pending Commission',
    ]);

    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'source_label' => 'Approved Commission',
    ]);

    Livewire::actingAs($employee->user)
        ->test('payroll.my-commissions')
        ->assertSee('Pending Commission')
        ->assertSee('Approved Commission')
        ->set('statusFilter', 'pending')
        ->assertSee('Pending Commission')
        ->assertDontSee('Approved Commission');
});

it('does not show approve or reverse action buttons', function () {
    $employee = EmployeeProfile::factory()->commissioned()->create();

    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
    ]);

    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
    ]);

    $html = Livewire::actingAs($employee->user)
        ->test('payroll.my-commissions')
        ->html();

    // The admin commissions page has approve/reverse buttons with wire:click handlers.
    // The employee view should not have any such action buttons.
    expect($html)->not->toContain('wire:click="approveEntry')
        ->and($html)->not->toContain('wire:click="openReverseModal');
});
