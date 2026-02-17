<?php

use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\Schedule;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\EmployeeService;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// Model Tests
// ──────────────────────────────────────────────────

describe('EmployeeProfile model', function () {
    it('generates first employee number as EMP-001', function () {
        // No employees exist yet
        expect(EmployeeProfile::count())->toBe(0);

        $number = EmployeeProfile::generateEmployeeNumber();

        expect($number)->toBe('EMP-001');
    });

    it('generates sequential employee numbers', function () {
        EmployeeProfile::factory()->create(['employee_number' => 'EMP-001']);

        $number = EmployeeProfile::generateEmployeeNumber();

        expect($number)->toBe('EMP-002');
    });

    it('identifies active employees', function () {
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);

        expect($employee->isActive())->toBeTrue();
        expect($employee->isOnLeave())->toBeFalse();
        expect($employee->isTerminated())->toBeFalse();
    });

    it('identifies on-leave employees', function () {
        $employee = EmployeeProfile::factory()->onLeave()->create();

        expect($employee->isOnLeave())->toBeTrue();
        expect($employee->isActive())->toBeFalse();
        expect($employee->isTerminated())->toBeFalse();
    });

    it('identifies terminated employees', function () {
        $employee = EmployeeProfile::factory()->terminated()->create();

        expect($employee->isTerminated())->toBeTrue();
        expect($employee->isActive())->toBeFalse();
        expect($employee->isOnLeave())->toBeFalse();
    });

    it('detects onboarding complete when timestamp set', function () {
        $employee = EmployeeProfile::factory()->onboarded()->create();

        expect($employee->isOnboardingComplete())->toBeTrue();
    });

    it('detects onboarding incomplete when timestamp is null', function () {
        $employee = EmployeeProfile::factory()->create(['onboarding_completed_at' => null]);

        expect($employee->isOnboardingComplete())->toBeFalse();
    });

    it('detects expired lead safe certification', function () {
        $employee = EmployeeProfile::factory()->create([
            'lead_safe_cert_expiration' => now()->subDay(),
        ]);

        expect($employee->isLeadSafeCertExpired())->toBeTrue();
    });

    it('detects valid lead safe certification', function () {
        $employee = EmployeeProfile::factory()->leadSafeCertified()->create();

        expect($employee->isLeadSafeCertExpired())->toBeFalse();
    });

    it('returns false for lead safe cert expired when no cert exists', function () {
        $employee = EmployeeProfile::factory()->create([
            'lead_safe_cert_expiration' => null,
        ]);

        expect($employee->isLeadSafeCertExpired())->toBeFalse();
    });

    it('detects lead safe certification expiring soon within 30 days', function () {
        $employee = EmployeeProfile::factory()->create([
            'lead_safe_cert_expiration' => now()->addDays(15),
        ]);

        expect($employee->isLeadSafeCertExpiringSoon())->toBeTrue();
    });

    it('does not flag lead safe cert as expiring soon when more than 30 days away', function () {
        $employee = EmployeeProfile::factory()->create([
            'lead_safe_cert_expiration' => now()->addDays(60),
        ]);

        expect($employee->isLeadSafeCertExpiringSoon())->toBeFalse();
    });

    it('generates full address string', function () {
        $employee = EmployeeProfile::factory()->create([
            'address_line_1' => '123 Main St',
            'address_line_2' => 'Suite 100',
            'city' => 'Denver',
            'state' => 'CO',
            'zip_code' => '80202',
        ]);

        expect($employee->getFullAddress())->toBe('123 Main St, Suite 100, Denver, CO 80202');
    });

    it('generates full address without address line 2', function () {
        $employee = EmployeeProfile::factory()->create([
            'address_line_1' => '123 Main St',
            'address_line_2' => null,
            'city' => 'Denver',
            'state' => 'CO',
            'zip_code' => '80202',
        ]);

        expect($employee->getFullAddress())->toBe('123 Main St, Denver, CO 80202');
    });

    it('returns null for full address when no address fields set', function () {
        $employee = EmployeeProfile::factory()->create([
            'address_line_1' => null,
            'address_line_2' => null,
            'city' => null,
            'state' => null,
            'zip_code' => null,
        ]);

        expect($employee->getFullAddress())->toBeNull();
    });

    it('calculates estimated annual pay for hourly employees', function () {
        $employee = EmployeeProfile::factory()->create([
            'pay_type' => 'hourly',
            'pay_rate' => 25.00,
        ]);

        // 25 * 40 * 52 = 52000
        expect($employee->getEstimatedAnnualPay())->toBe(52000.00);
    });

    it('returns salary directly for salaried employees', function () {
        $employee = EmployeeProfile::factory()->salaried()->create([
            'pay_rate' => 65000.00,
        ]);

        expect($employee->getEstimatedAnnualPay())->toBe(65000.00);
    });

    it('returns null for annual pay when no pay rate set', function () {
        $employee = EmployeeProfile::factory()->create(['pay_rate' => null]);

        expect($employee->getEstimatedAnnualPay())->toBeNull();
    });

    it('returns null for annual pay for commission-only employees', function () {
        $employee = EmployeeProfile::factory()->commissionOnly()->create();

        expect($employee->getEstimatedAnnualPay())->toBeNull();
    });

    it('returns salary for salary plus commission employees', function () {
        $employee = EmployeeProfile::factory()->salaryPlusCommission()->create([
            'pay_rate' => 60000.00,
        ]);

        expect($employee->getEstimatedAnnualPay())->toBe(60000.00);
    });

    it('calculates annual pay for hourly plus commission employees', function () {
        $employee = EmployeeProfile::factory()->hourlyPlusCommission()->create([
            'pay_rate' => 25.00,
        ]);

        expect($employee->getEstimatedAnnualPay())->toBe(52000.00);
    });

    it('identifies commission-only employees', function () {
        $employee = EmployeeProfile::factory()->commissionOnly()->create();

        expect($employee->isCommissionOnly())->toBeTrue();
        expect($employee->hasCommissionComponent())->toBeTrue();
        expect($employee->hasHourlyBase())->toBeFalse();
        expect($employee->hasSalaryBase())->toBeFalse();
    });

    it('identifies hourly plus commission employees', function () {
        $employee = EmployeeProfile::factory()->hourlyPlusCommission()->create();

        expect($employee->isCommissionOnly())->toBeFalse();
        expect($employee->hasCommissionComponent())->toBeTrue();
        expect($employee->hasHourlyBase())->toBeTrue();
        expect($employee->hasSalaryBase())->toBeFalse();
    });

    it('identifies salary plus commission employees', function () {
        $employee = EmployeeProfile::factory()->salaryPlusCommission()->create();

        expect($employee->isCommissionOnly())->toBeFalse();
        expect($employee->hasCommissionComponent())->toBeTrue();
        expect($employee->hasHourlyBase())->toBeFalse();
        expect($employee->hasSalaryBase())->toBeTrue();
    });

    it('generates correct pay rate label for commission-only', function () {
        $employee = EmployeeProfile::factory()->commissionOnly()->create();

        expect($employee->getPayRateLabel())->toBe('Commission');
    });

    it('generates correct pay rate label for hourly', function () {
        $employee = EmployeeProfile::factory()->create([
            'pay_type' => 'hourly',
            'pay_rate' => 25.00,
        ]);

        expect($employee->getPayRateLabel())->toBe('$25.00/hr');
    });

    it('generates correct pay rate label for hourly plus commission', function () {
        $employee = EmployeeProfile::factory()->hourlyPlusCommission()->create([
            'pay_rate' => 20.00,
        ]);

        expect($employee->getPayRateLabel())->toBe('$20.00/hr + Commission');
    });

    it('generates correct pay rate label for salary plus commission', function () {
        $employee = EmployeeProfile::factory()->salaryPlusCommission()->create([
            'pay_rate' => 55000.00,
        ]);

        expect($employee->getPayRateLabel())->toBe('$55,000.00/yr + Commission');
    });

    it('terminates employee and deactivates user', function () {
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);
        $user = $employee->user;

        expect($user->is_active)->toBeTrue();

        $employee->terminate('Poor performance');

        $employee->refresh();
        $user->refresh();

        expect($employee->employment_status)->toBe('terminated');
        expect($employee->termination_date)->not->toBeNull();
        expect($employee->notes)->toContain('Poor performance');
        expect($user->is_active)->toBeFalse();
    });

    it('terminates employee without notes', function () {
        $employee = EmployeeProfile::factory()->create([
            'employment_status' => 'active',
            'notes' => null,
        ]);

        $employee->terminate();

        $employee->refresh();

        expect($employee->employment_status)->toBe('terminated');
        expect($employee->notes)->toBeNull();
    });

    it('appends termination notes to existing notes', function () {
        $employee = EmployeeProfile::factory()->create([
            'employment_status' => 'active',
            'notes' => 'Existing notes here',
        ]);

        $employee->terminate('Terminated for cause');

        $employee->refresh();

        expect($employee->notes)->toContain('Existing notes here');
        expect($employee->notes)->toContain('Terminated for cause');
    });

    it('encrypts SSN field in database', function () {
        $employee = EmployeeProfile::factory()->create([
            'ssn' => '123-45-6789',
        ]);

        // Reading through Eloquent returns decrypted value
        expect($employee->ssn)->toBe('123-45-6789');

        // Raw DB value should be encrypted (not the plain text)
        $rawValue = \DB::table('employee_profiles')
            ->where('id', $employee->id)
            ->value('ssn');

        expect($rawValue)->not->toBe('123-45-6789');
        expect($rawValue)->not->toBeNull();
    });

    it('encrypts bank routing number in database', function () {
        $employee = EmployeeProfile::factory()->withDirectDeposit()->create([
            'bank_routing_number' => '111000025',
        ]);

        expect($employee->bank_routing_number)->toBe('111000025');

        $rawValue = \DB::table('employee_profiles')
            ->where('id', $employee->id)
            ->value('bank_routing_number');

        expect($rawValue)->not->toBe('111000025');
    });

    it('encrypts bank account number in database', function () {
        $employee = EmployeeProfile::factory()->withDirectDeposit()->create([
            'bank_account_number' => '1234567890',
        ]);

        expect($employee->bank_account_number)->toBe('1234567890');

        $rawValue = \DB::table('employee_profiles')
            ->where('id', $employee->id)
            ->value('bank_account_number');

        expect($rawValue)->not->toBe('1234567890');
    });

    it('belongs to a user', function () {
        $employee = EmployeeProfile::factory()->create();

        expect($employee->user)->toBeInstanceOf(User::class);
    });
});

// ──────────────────────────────────────────────────
// Service Tests
// ──────────────────────────────────────────────────

describe('EmployeeService', function () {
    it('creates user and employee profile together', function () {
        $service = new EmployeeService;

        $profile = $service->createEmployee([
            'name' => 'John Painter',
            'email' => 'john@painters.com',
            'password' => 'password123',
            'job_title' => 'Lead Painter',
            'department' => 'painting',
            'pay_rate' => '25.00',
            'pay_type' => 'hourly',
        ]);

        expect($profile)->toBeInstanceOf(EmployeeProfile::class);
        expect($profile->user->name)->toBe('John Painter');
        expect($profile->user->email)->toBe('john@painters.com');
        expect($profile->user->type)->toBe('internal');
        expect($profile->user->is_active)->toBeTrue();
        expect($profile->job_title)->toBe('Lead Painter');
        expect($profile->department)->toBe('painting');
        expect($profile->employment_status)->toBe('active');
    });

    it('auto-generates employee number on create', function () {
        $service = new EmployeeService;

        $profile = $service->createEmployee([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        expect($profile->employee_number)->toMatch('/^EMP-\d{3}$/');
    });

    it('sets hire_date to today by default', function () {
        $service = new EmployeeService;

        $profile = $service->createEmployee([
            'name' => 'Test Employee',
            'email' => 'test@example.com',
        ]);

        expect($profile->hire_date->toDateString())->toBe(now()->toDateString());
    });

    it('uses custom hire date when provided', function () {
        $service = new EmployeeService;

        $profile = $service->createEmployee([
            'name' => 'Test Employee',
            'email' => 'test@example.com',
            'hire_date' => '2025-06-15',
        ]);

        expect($profile->hire_date->toDateString())->toBe('2025-06-15');
    });

    it('updates employee profile fields', function () {
        $service = new EmployeeService;
        $employee = EmployeeProfile::factory()->create([
            'job_title' => 'Painter',
            'department' => 'painting',
        ]);

        $updated = $service->updateEmployee($employee, [
            'job_title' => 'Senior Painter',
            'department' => 'management',
        ]);

        expect($updated->job_title)->toBe('Senior Painter');
        expect($updated->department)->toBe('management');
    });

    it('updates user-level fields through service', function () {
        $service = new EmployeeService;
        $employee = EmployeeProfile::factory()->create();

        $updated = $service->updateEmployee($employee, [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        expect($updated->user->name)->toBe('Updated Name');
        expect($updated->user->email)->toBe('updated@example.com');
    });

    it('terminates employee through service', function () {
        $service = new EmployeeService;
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);

        $service->terminateEmployee($employee, 'End of contract');

        $employee->refresh();

        expect($employee->isTerminated())->toBeTrue();
        expect($employee->termination_date)->not->toBeNull();
        expect($employee->notes)->toContain('End of contract');
        expect($employee->user->is_active)->toBeFalse();
    });
});

// ──────────────────────────────────────────────────
// Livewire Component Tests: Index
// ──────────────────────────────────────────────────

describe('Employee index component', function () {
    it('renders for users with employees.view permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertStatus(200)
            ->assertSee('Employees');
    });

    it('blocks users without employees.view permission', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertForbidden();
    });

    it('shows employee list with names', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $empUser = User::factory()->internal()->create(['name' => 'Alice Painter']);
        $employee = EmployeeProfile::factory()->create(['user_id' => $empUser->id]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertSee('Alice Painter');
    });

    it('shows employee number in the list', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create(['employee_number' => 'EMP-042']);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertSee('EMP-042');
    });

    it('searches employees by name', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $alice = User::factory()->internal()->create(['name' => 'Alice Painter']);
        EmployeeProfile::factory()->create(['user_id' => $alice->id]);

        $bob = User::factory()->internal()->create(['name' => 'Bob Builder']);
        EmployeeProfile::factory()->create(['user_id' => $bob->id]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('search', 'Alice')
            ->assertSee('Alice Painter')
            ->assertDontSee('Bob Builder');
    });

    it('searches employees by email', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $alice = User::factory()->internal()->create([
            'name' => 'Alice Painter',
            'email' => 'alice@painters.com',
        ]);
        EmployeeProfile::factory()->create(['user_id' => $alice->id]);

        $bob = User::factory()->internal()->create([
            'name' => 'Bob Builder',
            'email' => 'bob@builders.com',
        ]);
        EmployeeProfile::factory()->create(['user_id' => $bob->id]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('search', 'alice@painters')
            ->assertSee('Alice Painter')
            ->assertDontSee('Bob Builder');
    });

    it('searches employees by employee number', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $alice = User::factory()->internal()->create(['name' => 'Alice Painter']);
        EmployeeProfile::factory()->create([
            'user_id' => $alice->id,
            'employee_number' => 'EMP-101',
        ]);

        $bob = User::factory()->internal()->create(['name' => 'Bob Builder']);
        EmployeeProfile::factory()->create([
            'user_id' => $bob->id,
            'employee_number' => 'EMP-202',
        ]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('search', 'EMP-101')
            ->assertSee('Alice Painter')
            ->assertDontSee('Bob Builder');
    });

    it('filters by active status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $activeUser = User::factory()->internal()->create(['name' => 'Active Employee']);
        EmployeeProfile::factory()->create([
            'user_id' => $activeUser->id,
            'employment_status' => 'active',
        ]);

        $terminatedUser = User::factory()->internal()->create(['name' => 'Terminated Employee']);
        EmployeeProfile::factory()->terminated()->create([
            'user_id' => $terminatedUser->id,
        ]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('statusFilter', 'active')
            ->assertSee('Active Employee')
            ->assertDontSee('Terminated Employee');
    });

    it('filters by terminated status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $activeUser = User::factory()->internal()->create(['name' => 'Active Employee']);
        EmployeeProfile::factory()->create([
            'user_id' => $activeUser->id,
            'employment_status' => 'active',
        ]);

        $terminatedUser = User::factory()->internal()->create(['name' => 'Terminated Employee']);
        EmployeeProfile::factory()->terminated()->create([
            'user_id' => $terminatedUser->id,
        ]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('statusFilter', 'terminated')
            ->assertDontSee('Active Employee')
            ->assertSee('Terminated Employee');
    });

    it('filters by department', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $painter = User::factory()->internal()->create(['name' => 'Painting Person']);
        EmployeeProfile::factory()->create([
            'user_id' => $painter->id,
            'department' => 'painting',
        ]);

        $manager = User::factory()->internal()->create(['name' => 'Sales Person']);
        EmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'department' => 'sales',
        ]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('departmentFilter', 'painting')
            ->assertSee('Painting Person')
            ->assertDontSee('Sales Person');
    });

    it('resets pagination when search changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when status filter changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('statusFilter', 'active')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when department filter changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->set('departmentFilter', 'painting')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no employees exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertSee('No employees found');
    });

    it('shows Add Employee button for users with employees.create permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertSee('Add Employee');
    });

    it('has correct default filter values', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertSet('search', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('departmentFilter', 'all')
            ->assertSet('perPage', 15);
    });

    it('displays commission pay label on employee index', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $empUser = User::factory()->internal()->create(['name' => 'Commission Person']);
        EmployeeProfile::factory()->commissionOnly()->create(['user_id' => $empUser->id]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertSee('Commission');
    });

    it('displays combined pay label for hourly plus commission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $empUser = User::factory()->internal()->create(['name' => 'Combo Person']);
        EmployeeProfile::factory()->hourlyPlusCommission()->create([
            'user_id' => $empUser->id,
            'pay_rate' => 18.00,
        ]);

        Livewire::actingAs($user)
            ->test('employees.index')
            ->assertSee('$18.00/hr + Commission');
    });
});

// ──────────────────────────────────────────────────
// Livewire Component Tests: Show
// ──────────────────────────────────────────────────

describe('Employee show component', function () {
    it('renders with valid employee profile', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $empUser = User::factory()->internal()->create(['name' => 'John Smith']);
        $employee = EmployeeProfile::factory()->create([
            'user_id' => $empUser->id,
            'employee_number' => 'EMP-010',
            'job_title' => 'Lead Painter',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertStatus(200)
            ->assertSee('John Smith')
            ->assertSee('EMP-010')
            ->assertSee('Lead Painter');
    });

    it('blocks users without employees.view permission', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertForbidden();
    });

    it('shows employee status badge', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSee('Active');
    });

    it('shows department and pay rate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create([
            'department' => 'painting',
            'pay_rate' => 30.00,
            'pay_type' => 'hourly',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSee('painting')
            ->assertSee('$30.00');
    });

    it('opens edit modal and populates fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $empUser = User::factory()->internal()->create(['name' => 'Editable Employee']);
        $employee = EmployeeProfile::factory()->create([
            'user_id' => $empUser->id,
            'job_title' => 'Painter',
            'department' => 'painting',
            'pay_rate' => 22.50,
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('openEditModal')
            ->assertSet('showEditModal', true)
            ->assertSet('editName', 'Editable Employee')
            ->assertSet('editJobTitle', 'Painter')
            ->assertSet('editDepartment', 'painting');
    });

    it('saves profile changes with employees.edit permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $empUser = User::factory()->internal()->create(['name' => 'Original Name']);
        $employee = EmployeeProfile::factory()->create([
            'user_id' => $empUser->id,
            'job_title' => 'Painter',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('openEditModal')
            ->set('editName', 'Updated Name')
            ->set('editJobTitle', 'Senior Painter')
            ->call('saveProfile')
            ->assertSet('showEditModal', false);

        $empUser->refresh();
        $employee->refresh();

        expect($empUser->name)->toBe('Updated Name');
        expect($employee->job_title)->toBe('Senior Painter');
    });

    it('blocks saveProfile without employees.edit permission', function () {
        $user = User::factory()->internal()->create();
        // Give view permission but not edit
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        if (\Spatie\Permission\Models\Permission::count() === 0) {
            (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
        }

        $user->givePermissionTo('employees.view');

        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('openEditModal')
            ->set('editName', 'Hacker Name')
            ->call('saveProfile')
            ->assertForbidden();
    });

    it('changes employee status via setStatus', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setStatus', 'on_leave');

        $employee->refresh();
        expect($employee->employment_status)->toBe('on_leave');
    });

    it('terminates employee via setStatus and deactivates user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create(['employment_status' => 'active']);
        $empUser = $employee->user;

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setStatus', 'terminated');

        $employee->refresh();
        $empUser->refresh();

        expect($employee->employment_status)->toBe('terminated');
        expect($employee->termination_date)->not->toBeNull();
        expect($empUser->is_active)->toBeFalse();
    });

    it('reactivates terminated employee via setStatus', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->terminated()->create();
        $empUser = $employee->user;
        $empUser->update(['is_active' => false]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setStatus', 'active');

        $employee->refresh();
        $empUser->refresh();

        expect($employee->employment_status)->toBe('active');
        expect($employee->termination_date)->toBeNull();
        expect($empUser->is_active)->toBeTrue();
    });

    it('blocks setStatus without employees.edit permission', function () {
        $user = User::factory()->internal()->create();
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        if (\Spatie\Permission\Models\Permission::count() === 0) {
            (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
        }

        $user->givePermissionTo('employees.view');

        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setStatus', 'terminated')
            ->assertForbidden();
    });

    it('shows sensitive data section for users with employees.view_sensitive permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->complete()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSee('Sensitive Information')
            ->assertSee('SSN');
    });

    it('hides sensitive data section without employees.view_sensitive permission', function () {
        $user = User::factory()->internal()->create();
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        if (\Spatie\Permission\Models\Permission::count() === 0) {
            (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
        }

        $user->givePermissionTo('employees.view');
        // Explicitly NOT giving employees.view_sensitive

        $employee = EmployeeProfile::factory()->complete()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertDontSee('Sensitive Information');
    });

    it('masks SSN showing only last 4 digits', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create([
            'ssn' => '123-45-6789',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSee('***-**-6789')
            ->assertDontSee('123-45-6789');
    });

    it('saves notes with employees.edit permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'notes')
            ->set('editNotes', 'Great worker, very reliable')
            ->call('saveNotes');

        $employee->refresh();
        expect($employee->notes)->toBe('Great worker, very reliable');
    });

    it('blocks saveNotes without employees.edit permission', function () {
        $user = User::factory()->internal()->create();
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        if (\Spatie\Permission\Models\Permission::count() === 0) {
            (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
        }

        $user->givePermissionTo('employees.view');

        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'notes')
            ->set('editNotes', 'Unauthorized notes')
            ->call('saveNotes')
            ->assertForbidden();
    });

    it('shows onboarding status as complete when onboarded', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->onboarded()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSee('Complete');
    });

    it('shows onboarding status as pending when not onboarded', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create(['onboarding_completed_at' => null]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSee('Pending');
    });

    it('shows lead safe cert expired badge when cert is expired', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create([
            'lead_safe_cert_number' => 'LS-123456',
            'lead_safe_cert_expiration' => now()->subDays(10),
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSee('Expired');
    });

    it('switches between tabs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->assertSet('activeTab', 'overview')
            ->call('setTab', 'documents')
            ->assertSet('activeTab', 'documents')
            ->assertSee('Documents');
    });

    it('validates edit profile fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('openEditModal')
            ->set('editName', '')
            ->call('saveProfile')
            ->assertHasErrors(['editName']);
    });
});

// ──────────────────────────────────────────────────
// Livewire Component Tests: Show Tabs (Documents, Time, Schedule, Payroll)
// ──────────────────────────────────────────────────

describe('Employee show - Documents tab', function () {
    it('shows contracts for the employee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
            'title' => 'Employment Agreement',
            'status' => 'signed',
            'sent_at' => now()->subDays(5),
            'signed_at' => now()->subDay(),
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'documents')
            ->assertSee('Employment Agreement')
            ->assertSee('Signed');
    });

    it('shows empty state when no contracts exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'documents')
            ->assertSee('No Documents');
    });

    it('shows assign contract button for users with edit permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'documents')
            ->assertSee('Assign Contract');
    });

    it('shows contract status badges with correct colors', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
            'title' => 'Draft Contract',
            'status' => 'draft',
        ]);

        EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $employee->id,
            'title' => 'Sent Contract',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'documents')
            ->assertSee('Draft Contract')
            ->assertSee('Sent Contract');
    });

    it('does not show contracts from other employees', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();
        $otherEmployee = EmployeeProfile::factory()->create();

        EmployeeContract::factory()->create([
            'employee_profile_id' => $otherEmployee->id,
            'title' => 'Other Employee Contract',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'documents')
            ->assertDontSee('Other Employee Contract');
    });
});

describe('Employee show - Time tab', function () {
    it('shows time entries for the employee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        TimeEntry::factory()->completed()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => now()->startOfDay()->addHours(8),
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'time')
            ->assertSee('8:00am')
            ->assertSee('8.00');
    });

    it('shows empty state when no time entries exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'time')
            ->assertSee('No Time Entries');
    });

    it('shows full timesheet link', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'time')
            ->assertSee('Full Timesheet');
    });

    it('does not show time entries from other employees', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();
        $otherEmployee = EmployeeProfile::factory()->create();

        TimeEntry::factory()->completed()->create([
            'employee_profile_id' => $otherEmployee->id,
            'total_hours' => 6.50,
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'time')
            ->assertDontSee('6.50');
    });
});

describe('Employee show - Schedule tab', function () {
    it('shows upcoming schedules for the employee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => now()->addDays(2),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'schedule')
            ->assertSee('8:00am')
            ->assertSee('4:00pm')
            ->assertSee('Scheduled');
    });

    it('shows empty state when no upcoming schedules exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'schedule')
            ->assertSee('No Upcoming Schedules');
    });

    it('does not show past schedules', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Schedule::factory()->create([
            'employee_profile_id' => $employee->id,
            'date' => now()->subDays(5),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'status' => 'completed',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'schedule')
            ->assertSee('No Upcoming Schedules');
    });

    it('shows full schedule link', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'schedule')
            ->assertSee('Full Schedule');
    });
});

describe('Employee show - Payroll tab', function () {
    it('shows pay stubs for the employee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();
        $period = PayPeriod::factory()->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-14',
        ]);

        PayStub::factory()->finalized()->create([
            'employee_profile_id' => $employee->id,
            'pay_period_id' => $period->id,
            'gross_pay' => 1500.00,
            'total_deductions' => 350.00,
            'net_pay' => 1150.00,
            'check_number' => '4567',
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'payroll')
            ->assertSee('$1,500.00')
            ->assertSee('$350.00')
            ->assertSee('$1,150.00')
            ->assertSee('4567')
            ->assertSee('Finalized');
    });

    it('shows empty state when no pay stubs exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'payroll')
            ->assertSee('No Pay Stubs');
    });

    it('shows YTD totals when stubs exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        PayStub::factory()->create([
            'employee_profile_id' => $employee->id,
            'gross_pay' => 1000.00,
            'total_deductions' => 200.00,
            'net_pay' => 800.00,
        ]);

        PayStub::factory()->create([
            'employee_profile_id' => $employee->id,
            'gross_pay' => 1200.00,
            'total_deductions' => 250.00,
            'net_pay' => 950.00,
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'payroll')
            ->assertSee('YTD Totals')
            ->assertSee('$2,200.00')
            ->assertSee('$1,750.00');
    });

    it('shows deductions link for users with payroll.manage permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'payroll')
            ->assertSee('Deductions');
    });

    it('does not show pay stubs from other employees', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();
        $otherEmployee = EmployeeProfile::factory()->create();

        PayStub::factory()->create([
            'employee_profile_id' => $otherEmployee->id,
            'gross_pay' => 9999.00,
        ]);

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'payroll')
            ->assertDontSee('$9,999.00');
    });
});

// ──────────────────────────────────────────────────
// Livewire Component Tests: Create Modal
// ──────────────────────────────────────────────────

describe('Employee create-modal component', function () {
    it('opens on event dispatch', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->assertSet('show', false)
            ->dispatch('open-create-employee-modal')
            ->assertSet('show', true);
    });

    it('sets hire date to today when opened', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->assertSet('hire_date', now()->format('Y-m-d'));
    });

    it('validates required fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', '')
            ->set('email', '')
            ->set('password', '')
            ->set('job_title', '')
            ->set('pay_rate', '')
            ->call('save')
            ->assertHasErrors(['name', 'email', 'password', 'job_title', 'pay_rate']);
    });

    it('validates email format', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('rejects duplicate email', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'New Employee')
            ->set('email', 'taken@example.com')
            ->set('password', 'StrongPassword1!')
            ->set('job_title', 'Painter')
            ->set('pay_rate', '20')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('creates user and employee profile on valid submission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'New Painter')
            ->set('email', 'newpainter@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Painter')
            ->set('department', 'painting')
            ->set('pay_rate', '25')
            ->set('pay_type', 'hourly')
            ->set('phone', '555-123-4567')
            ->call('save')
            ->assertSet('show', false)
            ->assertDispatched('employee-created');

        $newUser = User::where('email', 'newpainter@example.com')->first();

        expect($newUser)->not->toBeNull();
        expect($newUser->name)->toBe('New Painter');
        expect($newUser->type)->toBe('internal');
        expect($newUser->is_active)->toBeTrue();

        $profile = $newUser->employeeProfile;

        expect($profile)->not->toBeNull();
        expect($profile->job_title)->toBe('Painter');
        expect($profile->department)->toBe('painting');
        expect($profile->employee_number)->toMatch('/^EMP-\d{3}$/');
    });

    it('auto-generates employee number on creation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Auto Number')
            ->set('email', 'autonumber@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Painter')
            ->set('pay_rate', '20')
            ->call('save');

        $profile = User::where('email', 'autonumber@example.com')
            ->first()
            ->employeeProfile;

        expect($profile->employee_number)->not->toBeNull();
        expect($profile->employee_number)->toStartWith('EMP-');
    });

    it('blocks creation without employees.create permission', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Unauthorized')
            ->set('email', 'unauthorized@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Painter')
            ->set('pay_rate', '20')
            ->call('save')
            ->assertForbidden();
    });

    it('resets form fields when modal is closed', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Some Name')
            ->set('email', 'some@email.com')
            ->call('close')
            ->assertSet('show', false)
            ->assertSet('name', '')
            ->assertSet('email', '');
    });

    it('validates pay type must be a valid pay type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Test')
            ->set('email', 'test@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Painter')
            ->set('pay_rate', '25')
            ->set('pay_type', 'invalid')
            ->call('save')
            ->assertHasErrors(['pay_type']);
    });

    it('can create commission-only employee without pay rate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Commission Rep')
            ->set('email', 'commission@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Sales Rep')
            ->set('pay_type', 'commission')
            ->set('pay_rate', '')
            ->call('save')
            ->assertSet('show', false)
            ->assertDispatched('employee-created');

        $newUser = User::where('email', 'commission@example.com')->first();
        expect($newUser)->not->toBeNull();

        $profile = $newUser->employeeProfile;
        expect($profile->pay_type)->toBe('commission');
        expect($profile->pay_rate)->toBeNull();
    });

    it('can create hourly plus commission employee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Hybrid Rep')
            ->set('email', 'hybrid@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Sales Painter')
            ->set('pay_type', 'hourly_plus_commission')
            ->set('pay_rate', '20')
            ->call('save')
            ->assertSet('show', false)
            ->assertDispatched('employee-created');

        $profile = User::where('email', 'hybrid@example.com')->first()->employeeProfile;
        expect($profile->pay_type)->toBe('hourly_plus_commission');
        expect((float) $profile->pay_rate)->toBe(20.00);
    });

    it('can create salary plus commission employee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Salary Commission')
            ->set('email', 'salarycomm@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Account Manager')
            ->set('pay_type', 'salary_plus_commission')
            ->set('pay_rate', '55000')
            ->call('save')
            ->assertSet('show', false)
            ->assertDispatched('employee-created');

        $profile = User::where('email', 'salarycomm@example.com')->first()->employeeProfile;
        expect($profile->pay_type)->toBe('salary_plus_commission');
        expect((float) $profile->pay_rate)->toBe(55000.00);
    });

    it('validates pay_rate required for hourly types', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('employees.create-modal')
            ->dispatch('open-create-employee-modal')
            ->set('name', 'Test')
            ->set('email', 'test@example.com')
            ->set('password', 'SecurePassword1!')
            ->set('job_title', 'Painter')
            ->set('pay_type', 'hourly')
            ->set('pay_rate', '')
            ->call('save')
            ->assertHasErrors(['pay_rate']);
    });
});
