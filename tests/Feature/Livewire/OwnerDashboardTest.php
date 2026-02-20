<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('shows command center for owner', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('Cash Flow')
        ->assertSee('Active Jobs')
        ->assertSee('Sales Pipeline')
        ->assertSee('Quick Actions');
});

it('shows command center for admin', function () {
    $admin = User::factory()->internal()->withRole('admin')->create();

    Livewire::actingAs($admin)
        ->test('dashboard')
        ->assertSuccessful()
        ->assertSee('Cash Flow')
        ->assertSee('Active Jobs')
        ->assertSee('Sales Pipeline');
});

it('shows employee dashboard for non-owner internal user', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('Time Clock')
        ->assertDontSee('Cash Flow');
});

it('shows alerts for overdue invoices', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Invoice::factory()->create([
        'status' => 'overdue',
        'total' => 5000,
        'balance_due' => 5000,
    ]);

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('overdue invoice');
});

it('shows alerts for sent invoices past due date', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Invoice::factory()->sent()->create([
        'due_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('overdue invoice');
});

it('shows alerts for pending payables', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    JobPayable::factory()->pending()->create();

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('payable');
});

it('shows alerts for pending commissions', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    $profile = EmployeeProfile::factory()->create();
    CommissionEntry::factory()->create([
        'employee_profile_id' => $profile->id,
        'status' => 'pending',
    ]);

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('commission');
});

it('shows no alerts when nothing is pending', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertDontSee('overdue invoice')
        ->assertDontSee('payable(s) awaiting')
        ->assertDontSee('commission(s) awaiting');
});

it('shows cash flow data', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('Outstanding AR')
        ->assertSee('Outstanding AP')
        ->assertSee('Collected MTD')
        ->assertSee('Paid Out MTD')
        ->assertSee('Collected YTD');
});

it('shows jobs snapshot with active jobs', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Job::factory()->inProgress()->create();

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('Active Jobs')
        ->assertSee('In Progress');
});

it('shows pipeline data with active deals', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Deal::factory()->lead()->create(['estimated_value' => 10000]);
    Deal::factory()->quoted()->create(['estimated_value' => 15000]);

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('Sales Pipeline')
        ->assertSee('Pipeline Value')
        ->assertSee('Active Deals');
});

it('shows both owner and employee sections when owner has employee profile', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $owner = $profile->user;
    $owner->assignRole('owner');

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('Cash Flow')
        ->assertSee('My Work')
        ->assertSee('Time Clock');
});

it('shows quick action buttons for owner', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($owner)
        ->test('dashboard')
        ->assertSee('Quick Actions')
        ->assertSee('Review Payables')
        ->assertSee('Manage Jobs')
        ->assertSee('View Pipeline');
});

it('does not show command center for estimator role', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('estimator');

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertDontSee('Cash Flow')
        ->assertDontSee('Outstanding AR')
        ->assertSee('Time Clock');
});
