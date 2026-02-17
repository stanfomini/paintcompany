<?php

use App\Models\EmployeeContract;
use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;

describe('EmployeeContract auto-complete onboarding', function () {
    it('marks onboarding progress as completed when contract is signed', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $employee->id,
        ]);
        $progress = EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_contract_id' => $contract->id,
            'status' => 'pending',
        ]);

        $contract->markSigned([
            'signature_data' => 'data:image/png;base64,test',
            'signature_type' => 'drawn',
            'signer_name' => 'John Doe',
            'ip' => '127.0.0.1',
            'user_agent' => 'TestAgent',
        ]);

        $progress->refresh();
        expect($progress->status)->toBe('completed');
        expect($progress->completed_at)->not->toBeNull();
    });

    it('does not affect progress records not linked to the contract', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $employee->id,
        ]);
        $unlinkedProgress = EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_contract_id' => null,
            'status' => 'pending',
        ]);

        $contract->markSigned([
            'signature_data' => 'data:image/png;base64,test',
            'signature_type' => 'drawn',
            'signer_name' => 'John Doe',
            'ip' => '127.0.0.1',
            'user_agent' => 'TestAgent',
        ]);

        $unlinkedProgress->refresh();
        expect($unlinkedProgress->status)->toBe('pending');
    });

    it('does not re-complete already completed progress', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $employee->id,
        ]);
        $completedAt = now()->subDay();
        $progress = EmployeeOnboardingProgress::factory()->completed()->create([
            'employee_profile_id' => $employee->id,
            'employee_contract_id' => $contract->id,
            'completed_at' => $completedAt,
        ]);

        $contract->markSigned([
            'signature_data' => 'data:image/png;base64,test',
            'signature_type' => 'drawn',
            'signer_name' => 'John Doe',
            'ip' => '127.0.0.1',
            'user_agent' => 'TestAgent',
        ]);

        $progress->refresh();
        expect($progress->status)->toBe('completed');
        expect($progress->completed_at->toDateTimeString())->toBe($completedAt->toDateTimeString());
    });

    it('does not trigger on non-signing status changes', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'draft',
        ]);
        $progress = EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_contract_id' => $contract->id,
            'status' => 'pending',
        ]);

        $contract->update(['status' => 'sent', 'sent_at' => now()]);

        $progress->refresh();
        expect($progress->status)->toBe('pending');
    });

    it('has onboardingProgress relationship', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
        ]);
        $progress = EmployeeOnboardingProgress::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_contract_id' => $contract->id,
        ]);

        expect($contract->onboardingProgress->id)->toBe($progress->id);
    });
});
