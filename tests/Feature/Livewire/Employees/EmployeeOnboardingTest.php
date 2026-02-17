<?php

use App\Models\EmployeeProfile;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ──────────────────────────────────────────────────
// Role Assignment → Auto-Create EmployeeProfile
// ──────────────────────────────────────────────────

describe('Role assignment auto-creates EmployeeProfile', function () {
    it('creates EmployeeProfile when a role is assigned via saveRole', function () {
        $owner = User::factory()->internal()->withFullAccess()->create();
        $owner->assignRole('owner');

        $teamMember = User::factory()->internal()->create();

        expect($teamMember->employeeProfile)->toBeNull();

        Livewire::actingAs($owner)
            ->test('pages::settings.roles')
            ->call('startEditing', $teamMember->id)
            ->set('selectedRole', 'estimator')
            ->call('saveRole');

        $teamMember->refresh();
        expect($teamMember->employeeProfile)->not->toBeNull();
        expect($teamMember->employeeProfile->employment_status)->toBe('active');
        expect($teamMember->employeeProfile->pay_type)->toBe('hourly');
        expect((float) $teamMember->employeeProfile->pay_rate)->toBe(0.0);
        expect($teamMember->employeeProfile->employee_number)->toStartWith('EMP-');
        expect($teamMember->employeeProfile->onboarding_completed_at)->toBeNull();
    });

    it('does NOT create duplicate profile if one already exists', function () {
        $owner = User::factory()->internal()->withFullAccess()->create();
        $owner->assignRole('owner');

        $teamMember = User::factory()->internal()->create();
        $existingProfile = EmployeeProfile::factory()->onboarded()->create(['user_id' => $teamMember->id]);

        Livewire::actingAs($owner)
            ->test('pages::settings.roles')
            ->call('startEditing', $teamMember->id)
            ->set('selectedRole', 'admin')
            ->call('saveRole');

        expect(EmployeeProfile::where('user_id', $teamMember->id)->count())->toBe(1);
        $teamMember->refresh();
        expect($teamMember->employeeProfile->id)->toBe($existingProfile->id);
    });

    it('does NOT delete EmployeeProfile when all roles are removed', function () {
        $owner = User::factory()->internal()->withFullAccess()->create();
        $owner->assignRole('owner');

        $teamMember = User::factory()->internal()->create();
        $teamMember->assignRole('estimator');
        EmployeeProfile::factory()->onboarded()->create(['user_id' => $teamMember->id]);

        Livewire::actingAs($owner)
            ->test('pages::settings.roles')
            ->call('removeRole', $teamMember->id);

        $teamMember->refresh();
        expect($teamMember->roles)->toHaveCount(0);
        expect($teamMember->employeeProfile)->not->toBeNull();
    });
});

// ──────────────────────────────────────────────────
// Onboarding Wizard
// ──────────────────────────────────────────────────

describe('Employee onboarding wizard', function () {
    it('loads for user with skeleton profile (not yet onboarded)', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('employee.onboarding'))
            ->assertOk();
    });

    it('redirects away if already onboarded', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->onboarded()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('employees.employee-onboarding')
            ->assertRedirect(route('dashboard'));
    });

    it('redirects away if no employee profile', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test('employees.employee-onboarding')
            ->assertRedirect(route('dashboard'));
    });

    it('validates phone and date of birth on step 1', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
            'phone' => null,
            'date_of_birth' => null,
        ]);

        Livewire::actingAs($user)
            ->test('employees.employee-onboarding')
            ->assertSet('step', 1)
            ->set('phone', '')
            ->set('dateOfBirth', '')
            ->call('nextStep')
            ->assertHasErrors(['phone', 'dateOfBirth'])
            ->assertSet('step', 1);
    });

    it('validates address fields on step 2', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test('employees.employee-onboarding')
            ->set('step', 2)
            ->set('addressLine1', '')
            ->set('city', '')
            ->set('state', '')
            ->set('zipCode', '')
            ->call('nextStep')
            ->assertHasErrors(['addressLine1', 'city', 'state', 'zipCode'])
            ->assertSet('step', 2);
    });

    it('validates emergency contact on step 3', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test('employees.employee-onboarding')
            ->set('step', 3)
            ->set('emergencyContactName', '')
            ->set('emergencyContactPhone', '')
            ->set('emergencyContactRelation', '')
            ->call('nextStep')
            ->assertHasErrors(['emergencyContactName', 'emergencyContactPhone', 'emergencyContactRelation'])
            ->assertSet('step', 3);
    });

    it('saves profile and redirects to onboarding checklist', function () {
        $user = User::factory()->internal()->create();
        $profile = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
            'phone' => null,
            'date_of_birth' => null,
            'address_line_1' => null,
            'city' => null,
            'state' => null,
            'zip_code' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'emergency_contact_relation' => null,
        ]);

        Livewire::actingAs($user)
            ->test('employees.employee-onboarding')
            ->set('phone', '555-123-4567')
            ->set('dateOfBirth', '1990-05-15')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->set('addressLine1', '123 Main St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zipCode', '80202')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->set('emergencyContactName', 'Jane Doe')
            ->set('emergencyContactPhone', '555-987-6543')
            ->set('emergencyContactRelation', 'Spouse')
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('completeOnboarding')
            ->assertRedirect(route('my.onboarding'));

        $profile->refresh();
        // Profile data saved but onboarding_completed_at not set (completion happens via checklist)
        expect($profile->onboarding_completed_at)->toBeNull();
        expect($profile->phone)->toBe('555-123-4567');
        expect($profile->address_line_1)->toBe('123 Main St');
        expect($profile->city)->toBe('Denver');
        expect($profile->state)->toBe('CO');
        expect($profile->zip_code)->toBe('80202');
        expect($profile->emergency_contact_name)->toBe('Jane Doe');
        expect($profile->emergency_contact_phone)->toBe('555-987-6543');
        expect($profile->emergency_contact_relation)->toBe('Spouse');
    });
});

// ──────────────────────────────────────────────────
// Dashboard Redirect
// ──────────────────────────────────────────────────

describe('Dashboard onboarding redirect', function () {
    it('redirects to onboarding wizard only if profile truly empty', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
            'phone' => null,
            'address_line_1' => null,
        ]);

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertRedirect(route('employee.onboarding'));
    });

    it('does not redirect if profile has data even when onboarding incomplete', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
            'phone' => '555-123-4567',
            'address_line_1' => '123 Main St',
        ]);

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertSuccessful();
    });

    it('loads normally after onboarding complete', function () {
        $profile = EmployeeProfile::factory()->onboarded()->create();
        $user = $profile->user;
        $user->assignRole('admin');

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertSee('Welcome back');
    });
});

// ──────────────────────────────────────────────────
// Roles Page Status Badges
// ──────────────────────────────────────────────────

describe('Roles page onboarding status badges', function () {
    it('shows onboarding status badges for team members', function () {
        $owner = User::factory()->internal()->withFullAccess()->create();
        $owner->assignRole('owner');

        $onboardedUser = User::factory()->internal()->create(['name' => 'Onboarded Employee']);
        EmployeeProfile::factory()->onboarded()->create(['user_id' => $onboardedUser->id]);

        $pendingUser = User::factory()->internal()->create(['name' => 'Pending Employee']);
        EmployeeProfile::factory()->create([
            'user_id' => $pendingUser->id,
            'onboarding_completed_at' => null,
        ]);

        Livewire::actingAs($owner)
            ->test('pages::settings.roles')
            ->assertSee('Onboarded')
            ->assertSee('Pending Onboarding');
    });
});
