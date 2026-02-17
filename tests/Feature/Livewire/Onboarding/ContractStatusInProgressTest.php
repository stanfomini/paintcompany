<?php

use App\Models\EmployeeContract;
use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use App\Models\User;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// Admin View: employee-progress
// ──────────────────────────────────────────────────

describe('Admin employee-progress contract status', function () {
    beforeEach(function () {
        $this->admin = User::factory()->internal()->create();
        $this->admin->givePermissionTo('onboarding.manage');
        $this->actingAs($this->admin);

        $this->employee = EmployeeProfile::factory()->create();
        $this->checklist = OnboardingChecklist::factory()->create();
    });

    it('shows signed badge when contract is signed', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        $contract = EmployeeContract::factory()->signed()->create([
            'employee_profile_id' => $this->employee->id,
        ]);
        EmployeeOnboardingProgress::factory()->completed()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => $contract->id,
        ]);

        Livewire::test('onboarding.employee-progress', ['employeeProfile' => $this->employee])
            ->assertSee('Signed on');
    });

    it('shows awaiting signature badge when contract is sent', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $this->employee->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => $contract->id,
        ]);

        Livewire::test('onboarding.employee-progress', ['employeeProfile' => $this->employee])
            ->assertSee('Awaiting Signature');
    });

    it('shows draft badge when contract is draft', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'status' => 'draft',
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => $contract->id,
        ]);

        Livewire::test('onboarding.employee-progress', ['employeeProfile' => $this->employee])
            ->assertSee('Draft');
    });

    it('shows not assigned badge when no contract linked', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => null,
        ]);

        Livewire::test('onboarding.employee-progress', ['employeeProfile' => $this->employee])
            ->assertSee('Not Assigned');
    });

    it('blocks toggle for contract items', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $this->employee->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => $contract->id,
            'status' => 'pending',
        ]);

        Livewire::test('onboarding.employee-progress', ['employeeProfile' => $this->employee])
            ->call('toggleStatus', $contractItem->id);

        $progress = EmployeeOnboardingProgress::where('onboarding_checklist_item_id', $contractItem->id)->first();
        expect($progress->status)->toBe('pending');
    });

    it('blocks completeOnboarding when required contracts are unsigned', function () {
        $taskItem = OnboardingChecklistItem::factory()->task()->create([
            'onboarding_checklist_id' => $this->checklist->id,
            'is_required' => true,
        ]);
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
            'is_required' => true,
        ]);
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $this->employee->id,
        ]);

        // Complete the task item
        EmployeeOnboardingProgress::factory()->completed()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $taskItem->id,
        ]);
        // Contract item is pending (not signed)
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => $contract->id,
            'status' => 'pending',
        ]);

        Livewire::test('onboarding.employee-progress', ['employeeProfile' => $this->employee])
            ->call('completeOnboarding');

        $this->employee->refresh();
        expect($this->employee->onboarding_completed_at)->toBeNull();
    });
});

// ──────────────────────────────────────────────────
// Employee View: my-progress
// ──────────────────────────────────────────────────

describe('Employee my-progress contract status', function () {
    beforeEach(function () {
        $this->employee = EmployeeProfile::factory()->create();
        $this->user = $this->employee->user;
        $this->actingAs($this->user);

        $this->checklist = OnboardingChecklist::factory()->create();
    });

    it('shows signed badge for signed contracts', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        $contract = EmployeeContract::factory()->signed()->create([
            'employee_profile_id' => $this->employee->id,
        ]);
        EmployeeOnboardingProgress::factory()->completed()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => $contract->id,
        ]);

        Livewire::test('onboarding.my-progress')
            ->assertSee('Signed on');
    });

    it('shows sign contract button for sent contracts', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $this->employee->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => $contract->id,
        ]);

        Livewire::test('onboarding.my-progress')
            ->assertSee('Sign Contract')
            ->assertSee('Awaiting Signature');
    });

    it('blocks toggle for contract items', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'status' => 'pending',
        ]);

        Livewire::test('onboarding.my-progress')
            ->call('toggleItem', $contractItem->id);

        $progress = EmployeeOnboardingProgress::where('onboarding_checklist_item_id', $contractItem->id)->first();
        expect($progress->status)->toBe('pending');
    });

    it('shows not assigned badge when no contract linked', function () {
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $this->checklist->id,
        ]);
        EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'onboarding_checklist_item_id' => $contractItem->id,
            'employee_contract_id' => null,
        ]);

        Livewire::test('onboarding.my-progress')
            ->assertSee('Not Assigned');
    });
});
