<?php

use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\User;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// Commission Management Page Tests
// ──────────────────────────────────────────────────

describe('Commission management page', function () {
    it('loads for authorized users with payroll.manage permission', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->assertSuccessful()
            ->assertSee('Commissions');
    });

    it('blocks unauthorized users without payroll.manage permission', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test('payroll.commissions')
            ->assertForbidden();
    });

    it('blocks users with only payroll.view permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('payroll.view');

        Livewire::actingAs($user)
            ->test('payroll.commissions')
            ->assertForbidden();
    });

    it('displays commission entries in the table', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        CommissionEntry::factory()->create([
            'employee_profile_id' => $employee->id,
            'source_label' => 'Lead: John Doe',
            'amount' => 150.00,
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->assertSee('Lead: John Doe')
            ->assertSee('$150.00');
    });

    it('filters entries by status', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        CommissionEntry::factory()->create([
            'source_label' => 'Pending Entry',
            'status' => 'pending',
        ]);

        CommissionEntry::factory()->approved()->create([
            'source_label' => 'Approved Entry',
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->assertSee('Pending Entry')
            ->assertSee('Approved Entry')
            ->set('statusFilter', 'pending')
            ->assertSee('Pending Entry')
            ->assertDontSee('Approved Entry');
    });

    it('filters entries by date range', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        CommissionEntry::factory()->create([
            'source_label' => 'January Entry',
            'earned_date' => '2026-01-15',
        ]);

        CommissionEntry::factory()->create([
            'source_label' => 'February Entry',
            'earned_date' => '2026-02-15',
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->set('dateFrom', '2026-02-01')
            ->set('dateTo', '2026-02-28')
            ->assertSee('February Entry')
            ->assertDontSee('January Entry');
    });
});

// ──────────────────────────────────────────────────
// Commission Approve/Reverse Actions
// ──────────────────────────────────────────────────

describe('Commission approve and reverse', function () {
    it('approves a pending commission entry', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $entry = CommissionEntry::factory()->create([
            'status' => 'pending',
            'source_label' => 'Test Approval',
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->call('approveEntry', $entry->id);

        $entry->refresh();
        expect($entry->status)->toBe('approved');
        expect($entry->approved_by)->toBe($admin->id);
        expect($entry->approved_at)->not->toBeNull();
    });

    it('does not approve an already approved entry', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $entry = CommissionEntry::factory()->approved()->create();

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->call('approveEntry', $entry->id);

        // Should remain approved (not re-approved)
        $entry->refresh();
        expect($entry->status)->toBe('approved');
    });

    it('reverses an approved commission entry with reason', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $entry = CommissionEntry::factory()->approved()->create([
            'source_label' => 'Test Reverse',
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->call('openReverseModal', $entry->id)
            ->assertSet('reversingEntryId', $entry->id)
            ->set('reverseReason', 'Duplicate entry')
            ->call('reverseEntry');

        $entry->refresh();
        expect($entry->status)->toBe('reversed');
        expect($entry->notes)->toContain('Duplicate entry');
    });

    it('bulk approves selected pending entries', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $entries = CommissionEntry::factory()->count(3)->create([
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->set('selected', $entries->pluck('id')->toArray())
            ->call('bulkApprove');

        foreach ($entries as $entry) {
            $entry->refresh();
            expect($entry->status)->toBe('approved');
        }
    });

    it('ignores non-pending entries in bulk approve', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $pending = CommissionEntry::factory()->create(['status' => 'pending']);
        $approved = CommissionEntry::factory()->approved()->create();

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->set('selected', [$pending->id, $approved->id])
            ->call('bulkApprove');

        $pending->refresh();
        expect($pending->status)->toBe('approved');
    });
});

// ──────────────────────────────────────────────────
// Manual Commission Entry
// ──────────────────────────────────────────────────

describe('Manual commission entry', function () {
    it('creates a manual commission entry', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->call('openManualModal')
            ->assertSet('showManualModal', true)
            ->set('manualEmployeeId', $employee->id)
            ->set('manualAmount', '250.00')
            ->set('manualType', 'flat')
            ->set('manualNotes', 'Bonus commission')
            ->call('createManualEntry');

        $entry = CommissionEntry::where('employee_profile_id', $employee->id)->first();

        expect($entry)->not->toBeNull();
        expect((float) $entry->amount)->toBe(250.00);
        expect($entry->commission_type)->toBe('flat');
        expect($entry->source_label)->toBe('Manual Entry');
        expect($entry->notes)->toBe('Bonus commission');
        expect($entry->status)->toBe('pending');
    });

    it('validates required fields for manual entry', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->call('openManualModal')
            ->set('manualEmployeeId', '')
            ->set('manualAmount', '')
            ->call('createManualEntry')
            ->assertHasErrors(['manualEmployeeId', 'manualAmount']);
    });

    it('validates minimum amount for manual entry', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($admin)
            ->test('payroll.commissions')
            ->call('openManualModal')
            ->set('manualEmployeeId', $employee->id)
            ->set('manualAmount', '0')
            ->call('createManualEntry')
            ->assertHasErrors(['manualAmount']);
    });
});

// ──────────────────────────────────────────────────
// Commission Route Tests
// ──────────────────────────────────────────────────

describe('Commission route', function () {
    it('returns 200 for authorized users', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($admin)->get(route('payroll.commissions'));

        $response->assertSuccessful();
    });

    it('redirects guests to login', function () {
        $response = $this->get(route('payroll.commissions'));

        $response->assertRedirect();
    });

    it('returns 403 for unauthorized users', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)->get(route('payroll.commissions'));

        $response->assertForbidden();
    });
});

// ──────────────────────────────────────────────────
// Employee Commission Rules Tab Tests
// ──────────────────────────────────────────────────

describe('Employee commission rules tab', function () {
    it('loads commission rules when switching to commission tab', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $employee = EmployeeProfile::factory()->create([
            'commission_rules' => [
                ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true],
                ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'commission')
            ->assertSet('activeTab', 'commission')
            ->assertCount('commissionRules', 2);
    });

    it('adds a new commission rule', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($admin)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'commission')
            ->assertCount('commissionRules', 0)
            ->call('addCommissionRule')
            ->assertCount('commissionRules', 1);
    });

    it('removes a commission rule', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $employee = EmployeeProfile::factory()->create([
            'commission_rules' => [
                ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'commission')
            ->assertCount('commissionRules', 1)
            ->call('removeCommissionRule', 0)
            ->assertCount('commissionRules', 0);
    });

    it('toggles a commission rule active/inactive', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $employee = EmployeeProfile::factory()->create([
            'commission_rules' => [
                ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'commission')
            ->assertSet('commissionRules.0.active', true)
            ->call('toggleCommissionRule', 0)
            ->assertSet('commissionRules.0.active', false);
    });

    it('saves commission rules to the employee profile', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();
        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($admin)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('setTab', 'commission')
            ->call('addCommissionRule')
            ->set('commissionRules.0.source', 'estimate')
            ->set('commissionRules.0.type', 'revenue_percentage')
            ->set('commissionRules.0.rate', '0.05')
            ->set('commissionRules.0.amount', '')
            ->call('saveCommissionRules');

        $employee->refresh();
        expect($employee->commission_rules)->toHaveCount(1);
        expect($employee->commission_rules[0]['source'])->toBe('estimate');
        expect($employee->commission_rules[0]['type'])->toBe('revenue_percentage');
        expect($employee->commission_rules[0]['rate'])->toBe(0.05);
    });

    it('denies commission rule management for unauthorized users', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('employees.view');

        $employee = EmployeeProfile::factory()->create();

        Livewire::actingAs($user)
            ->test('employees.show', ['employeeProfile' => $employee])
            ->call('addCommissionRule')
            ->assertForbidden();
    });
});
