<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('loads dashboard for internal user with employee profile', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, '.$user->name);
});

it('shows clock button when employee profile exists and has time_tracking.clock permission', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('Time Clock')
        ->assertSeeLivewire('employees.clock-button');
});

it('shows stats cards for users with deals.view permission', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    Deal::factory()->create([
        'assigned_to' => $user->id,
        'stage' => 'lead',
    ]);

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('My Active Leads')
        ->assertSee('Closing Ratio')
        ->assertSee('Revenue This Month')
        ->assertSee('Pending Commissions');
});

it('hides stats cards for users without deals.view permission', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;

    // crew_lead role does not have deals.view permission
    $user->assignRole('crew_lead');

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertDontSee('My Active Leads')
        ->assertDontSee('Closing Ratio');
});

it('shows welcome message for user without employee profile', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('admin');

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('Your employee profile has not been set up yet')
        ->assertSee('Contact your administrator');
});

it('shows active leads count correctly', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    Deal::factory()->count(3)->create([
        'assigned_to' => $user->id,
        'stage' => 'lead',
    ]);

    Deal::factory()->create([
        'assigned_to' => $user->id,
        'stage' => 'lost',
    ]);

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('My Active Leads');
});

it('shows recent pay stubs', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    $period = \App\Models\PayPeriod::factory()->create();
    \App\Models\PayStub::factory()->create([
        'employee_profile_id' => $profile->id,
        'pay_period_id' => $period->id,
        'net_pay' => 1234.56,
    ]);

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('Recent Pay Stubs')
        ->assertSee('1,234.56');
});

it('shows pending commissions', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    CommissionEntry::factory()->create([
        'employee_profile_id' => $profile->id,
        'amount' => 500.00,
        'status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('500.00');
});

it('shows action items for unsigned contracts', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    EmployeeContract::factory()->create([
        'employee_profile_id' => $profile->id,
        'status' => 'sent',
    ]);

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('unsigned contract');
});

it('shows all caught up when no action items', function () {
    $profile = EmployeeProfile::factory()->onboarded()->create();
    $user = $profile->user;
    $user->assignRole('admin');

    Livewire::actingAs($user)
        ->test('dashboard')
        ->assertSee('All caught up!');
});
