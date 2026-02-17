<?php

use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\User;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// Page Access Tests
// ──────────────────────────────────────────────────

describe('My Profile page access', function () {
    it('loads for employee with profile', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.profile'))
            ->assertSuccessful();
    });

    it('returns 403 for user without employee profile', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('my.profile'))
            ->assertForbidden();
    });

    it('shows employee details on personal tab', function () {
        $user = User::factory()->internal()->create(['name' => 'John Smith']);
        $profile = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-042',
            'job_title' => 'Lead Painter',
            'department' => 'painting',
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->assertSee('John Smith')
            ->assertSee('EMP-042')
            ->assertSee('Lead Painter')
            ->assertSee('Painting');
    });
});

// ──────────────────────────────────────────────────
// Personal Info Update Tests
// ──────────────────────────────────────────────────

describe('Personal info updates', function () {
    it('can update phone, email, address, and emergency contact', function () {
        $user = User::factory()->internal()->create(['email' => 'old@example.com']);
        $profile = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'phone' => '555-0000',
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->set('phone', '555-1234')
            ->set('email', 'new@example.com')
            ->set('address_line_1', '123 Main St')
            ->set('address_line_2', 'Apt 4')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip_code', '80202')
            ->set('emergency_contact_name', 'Jane Doe')
            ->set('emergency_contact_phone', '555-5678')
            ->set('emergency_contact_relation', 'Spouse')
            ->call('savePersonalInfo')
            ->assertHasNoErrors();

        $profile->refresh();
        $user->refresh();

        expect($profile->phone)->toBe('555-1234')
            ->and($user->email)->toBe('new@example.com')
            ->and($profile->address_line_1)->toBe('123 Main St')
            ->and($profile->address_line_2)->toBe('Apt 4')
            ->and($profile->city)->toBe('Denver')
            ->and($profile->state)->toBe('CO')
            ->and($profile->zip_code)->toBe('80202')
            ->and($profile->emergency_contact_name)->toBe('Jane Doe')
            ->and($profile->emergency_contact_phone)->toBe('555-5678')
            ->and($profile->emergency_contact_relation)->toBe('Spouse');
    });

    it('validates email is required', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->set('email', '')
            ->call('savePersonalInfo')
            ->assertHasErrors(['email']);
    });

    it('validates email format', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->set('email', 'not-an-email')
            ->call('savePersonalInfo')
            ->assertHasErrors(['email']);
    });

    it('cannot update pay rate, job title, department, or SSN via save', function () {
        $user = User::factory()->internal()->create();
        $profile = EmployeeProfile::factory()->complete()->create([
            'user_id' => $user->id,
            'pay_rate' => 25.00,
            'job_title' => 'Painter',
            'department' => 'painting',
        ]);

        $originalSsn = $profile->ssn;

        // The savePersonalInfo method only updates specific fields
        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->set('phone', '555-9999')
            ->call('savePersonalInfo')
            ->assertHasNoErrors();

        $profile->refresh();

        // These fields should remain unchanged
        expect((float) $profile->pay_rate)->toBe(25.00)
            ->and($profile->job_title)->toBe('Painter')
            ->and($profile->department)->toBe('painting')
            ->and($profile->ssn)->toBe($originalSsn);
    });
});

// ──────────────────────────────────────────────────
// Documents Tab Tests
// ──────────────────────────────────────────────────

describe('Documents tab', function () {
    it('shows signed contracts', function () {
        $user = User::factory()->internal()->create();
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $contract = EmployeeContract::factory()->signed()->create([
            'employee_profile_id' => $profile->id,
            'title' => 'Employment Agreement',
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'documents')
            ->assertSee('Employment Agreement')
            ->assertSee('Signed');
    });

    it('shows pending contracts with sign button', function () {
        $user = User::factory()->internal()->create();
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $profile->id,
            'title' => 'New Contract',
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'documents')
            ->assertSee('New Contract')
            ->assertSee('Sign');
    });

    it('shows empty state when no contracts', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'documents')
            ->assertSee('No Contracts');
    });
});

// ──────────────────────────────────────────────────
// Pay Tab Tests
// ──────────────────────────────────────────────────

describe('Pay tab', function () {
    it('shows YTD summary', function () {
        $user = User::factory()->internal()->create();
        $profile = EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'pay_rate' => 30.00,
            'pay_type' => 'hourly',
        ]);

        $payPeriod = PayPeriod::factory()->create([
            'start_date' => now()->startOfYear(),
            'end_date' => now()->startOfYear()->addDays(13),
        ]);

        PayStub::factory()->create([
            'employee_profile_id' => $profile->id,
            'pay_period_id' => $payPeriod->id,
            'gross_pay' => 1200.00,
            'federal_tax' => 144.00,
            'state_tax' => 52.80,
            'social_security' => 74.40,
            'medicare' => 17.40,
            'total_deductions' => 288.60,
            'net_pay' => 911.40,
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'pay')
            ->assertSee('$1,200.00')
            ->assertSee('$911.40');
    });

    it('shows commission rules for commissioned employee', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->commissioned([
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
        ])->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'pay')
            ->assertSee('Commission Rules')
            ->assertSee('Estimate')
            ->assertSee('5.0%');
    });
});

// ──────────────────────────────────────────────────
// Certifications Tab Tests
// ──────────────────────────────────────────────────

describe('Certifications tab', function () {
    it('shows lead safe certification info', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->leadSafeCertified()->create([
            'user_id' => $user->id,
            'lead_safe_cert_number' => 'LS-123456',
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'certifications')
            ->assertSee('LS-123456')
            ->assertSee('Valid');
    });

    it('shows expired certification status', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'lead_safe_cert_number' => 'LS-999999',
            'lead_safe_cert_expiration' => now()->subMonth(),
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'certifications')
            ->assertSee('Expired');
    });

    it('shows onboarding completion status', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->onboarded()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'certifications')
            ->assertSee('Completed');
    });

    it('shows in-progress onboarding for incomplete employees', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'onboarding_completed_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test('employees.my-profile')
            ->call('setTab', 'certifications')
            ->assertSee('In Progress');
    });
});
