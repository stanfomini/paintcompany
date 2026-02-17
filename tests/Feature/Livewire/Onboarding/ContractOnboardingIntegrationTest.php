<?php

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('Contract-onboarding integration', function () {
    beforeEach(function () {
        Notification::fake();
    });

    it('auto-creates and sends contracts when onboarding is initialized with template-linked items', function () {
        $template = ContractTemplate::factory()->create(['name' => 'NDA']);
        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        $employee = EmployeeProfile::factory()->create();
        app(EmployeeService::class)->initializeOnboarding($employee);

        $contract = EmployeeContract::where('employee_profile_id', $employee->id)->first();
        expect($contract)->not->toBeNull();
        expect($contract->contract_template_id)->toBe($template->id);
        expect($contract->status)->toBe('sent');

        $progress = EmployeeOnboardingProgress::where('employee_profile_id', $employee->id)
            ->whereNotNull('employee_contract_id')
            ->first();
        expect($progress->employee_contract_id)->toBe($contract->id);
    });

    it('skips contract creation when metadata has no template id', function () {
        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'metadata' => null,
        ]);

        $employee = EmployeeProfile::factory()->create();
        app(EmployeeService::class)->initializeOnboarding($employee);

        expect(EmployeeContract::where('employee_profile_id', $employee->id)->count())->toBe(0);
    });

    it('auto-completes onboarding progress when contract is signed', function () {
        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        $employee = EmployeeProfile::factory()->create();
        app(EmployeeService::class)->initializeOnboarding($employee);

        $contract = EmployeeContract::where('employee_profile_id', $employee->id)->first();
        expect($contract)->not->toBeNull();

        // Sign the contract
        $contract->markSigned([
            'signature_data' => 'data:image/png;base64,test',
            'signature_type' => 'drawn',
            'signer_name' => 'John Doe',
            'ip' => '127.0.0.1',
            'user_agent' => 'TestAgent',
        ]);

        $progress = EmployeeOnboardingProgress::where('employee_contract_id', $contract->id)->first();
        expect($progress->status)->toBe('completed');
        expect($progress->completed_at)->not->toBeNull();
    });

    it('handles idempotent initializeOnboarding without creating duplicate contracts', function () {
        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        $employee = EmployeeProfile::factory()->create();
        $service = app(EmployeeService::class);

        // Call twice
        $service->initializeOnboarding($employee);
        $service->initializeOnboarding($employee);

        expect(EmployeeContract::where('employee_profile_id', $employee->id)->count())->toBe(1);
        expect(EmployeeOnboardingProgress::where('employee_profile_id', $employee->id)->count())->toBe(1);
    });

    it('blocks admin from completing onboarding when required contracts are unsigned', function () {
        $admin = User::factory()->internal()->create();
        $admin->givePermissionTo('onboarding.manage');

        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'is_required' => true,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        $employee = EmployeeProfile::factory()->create();
        app(EmployeeService::class)->initializeOnboarding($employee);

        Livewire::actingAs($admin)
            ->test('onboarding.employee-progress', ['employeeProfile' => $employee])
            ->call('completeOnboarding');

        $employee->refresh();
        expect($employee->onboarding_completed_at)->toBeNull();
    });

    it('prevents manual checkbox toggle for contract items in admin view', function () {
        $admin = User::factory()->internal()->create();
        $admin->givePermissionTo('onboarding.manage');

        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        $employee = EmployeeProfile::factory()->create();
        app(EmployeeService::class)->initializeOnboarding($employee);

        Livewire::actingAs($admin)
            ->test('onboarding.employee-progress', ['employeeProfile' => $employee])
            ->call('toggleStatus', $contractItem->id);

        $progress = EmployeeOnboardingProgress::where('onboarding_checklist_item_id', $contractItem->id)->first();
        expect($progress->status)->toBe('pending');
    });

    it('shows correct contract status in admin progress UI', function () {
        $admin = User::factory()->internal()->create();
        $admin->givePermissionTo('onboarding.manage');

        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        $employee = EmployeeProfile::factory()->create();
        app(EmployeeService::class)->initializeOnboarding($employee);

        // Contract was auto-sent, so should show "Awaiting Signature"
        Livewire::actingAs($admin)
            ->test('onboarding.employee-progress', ['employeeProfile' => $employee])
            ->assertSee('Awaiting Signature');
    });

    it('full flow: hire -> init -> sign -> progress completed', function () {
        $template = ContractTemplate::factory()->create(['name' => 'Employment Agreement']);
        $checklist = OnboardingChecklist::factory()->create();
        $taskItem = OnboardingChecklistItem::factory()->task()->create([
            'onboarding_checklist_id' => $checklist->id,
            'is_required' => true,
        ]);
        $contractItem = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'is_required' => true,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        $employee = EmployeeProfile::factory()->create();
        app(EmployeeService::class)->initializeOnboarding($employee);

        // Two progress records: one task, one contract
        expect(EmployeeOnboardingProgress::where('employee_profile_id', $employee->id)->count())->toBe(2);

        $contract = EmployeeContract::where('employee_profile_id', $employee->id)->first();
        expect($contract)->not->toBeNull();
        expect($contract->status)->toBe('sent');

        // Sign the contract
        $contract->markSigned([
            'signature_data' => 'data:image/png;base64,test',
            'signature_type' => 'typed',
            'signer_name' => $employee->user->name,
            'ip' => '127.0.0.1',
            'user_agent' => 'TestBrowser/1.0',
        ]);

        // Contract progress should now be completed
        $contractProgress = EmployeeOnboardingProgress::where('employee_contract_id', $contract->id)->first();
        expect($contractProgress->status)->toBe('completed');

        // Task progress still pending
        $taskProgress = EmployeeOnboardingProgress::where('onboarding_checklist_item_id', $taskItem->id)
            ->where('employee_profile_id', $employee->id)
            ->first();
        expect($taskProgress->status)->toBe('pending');
    });
});
