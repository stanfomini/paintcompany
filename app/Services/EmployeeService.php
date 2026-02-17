<?php

namespace App\Services;

use App\Models\ContractTemplate;
use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;
use App\Models\OnboardingChecklist;
use App\Models\User;
use App\Notifications\EmployeeWelcome;
use Illuminate\Support\Facades\DB;

class EmployeeService
{
    /**
     * Create a new employee (User + EmployeeProfile).
     *
     * @param  array{name: string, email: string, password?: string, job_title?: string, department?: string, pay_rate?: string, pay_type?: string, hire_date?: string, phone?: string}  $data
     */
    public function createEmployee(array $data): EmployeeProfile
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password'] ?? str()->random(32)),
                'type' => 'internal',
                'is_active' => true,
            ]);

            $profile = $user->employeeProfile()->create([
                'employee_number' => EmployeeProfile::generateEmployeeNumber(),
                'hire_date' => $data['hire_date'] ?? now()->toDateString(),
                'employment_status' => 'active',
                'job_title' => $data['job_title'] ?? null,
                'department' => $data['department'] ?? null,
                'pay_rate' => $data['pay_rate'] ?? null,
                'pay_type' => $data['pay_type'] ?? 'hourly',
                'phone' => $data['phone'] ?? null,
            ]);

            return $profile;
        });
    }

    /**
     * Update an existing employee profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateEmployee(EmployeeProfile $employee, array $data): EmployeeProfile
    {
        // Separate user-level fields from profile fields
        $userFields = ['name', 'email'];
        $userData = collect($data)->only($userFields)->filter()->all();
        $profileData = collect($data)->except($userFields)->all();

        if (! empty($userData)) {
            $employee->user->update($userData);
        }

        if (! empty($profileData)) {
            $employee->update($profileData);
        }

        return $employee->refresh();
    }

    /**
     * Terminate an employee.
     */
    public function terminateEmployee(EmployeeProfile $employee, ?string $reason = null): void
    {
        $employee->terminate($reason);
    }

    /**
     * Hire an applicant as an employee.
     *
     * @param  array{job_title?: string, department?: string, pay_rate?: string, pay_type?: string, password?: string}  $data
     */
    public function hireApplicant(\App\Models\Applicant $applicant, array $data = []): EmployeeProfile
    {
        $profile = $this->createEmployee([
            'name' => $applicant->getFullName(),
            'email' => $applicant->email,
            'phone' => $applicant->phone ?? null,
            'job_title' => $data['job_title'] ?? $applicant->jobListing?->title ?? null,
            'department' => $data['department'] ?? null,
            'pay_rate' => $data['pay_rate'] ?? ($applicant->offer_amount ? (string) $applicant->offer_amount : null),
            'pay_type' => $data['pay_type'] ?? $applicant->offer_type ?? 'hourly',
            'password' => $data['password'] ?? null,
        ]);

        $applicant->update([
            'stage' => 'hired',
            'hired_at' => now(),
            'converted_user_id' => $profile->user_id,
        ]);

        if ($applicant->job_listing_id) {
            $applicant->jobListing?->increment('positions_filled');
        }

        $this->initializeOnboarding($profile);

        $profile->user->notify(new EmployeeWelcome($profile));

        return $profile;
    }

    /**
     * Create onboarding progress records from active checklists matching the employee's role.
     */
    public function initializeOnboarding(EmployeeProfile $employee): void
    {
        $checklists = OnboardingChecklist::query()
            ->active()
            ->forRole($employee->job_title)
            ->with('items')
            ->get();

        foreach ($checklists as $checklist) {
            foreach ($checklist->items as $item) {
                EmployeeOnboardingProgress::firstOrCreate([
                    'employee_profile_id' => $employee->id,
                    'onboarding_checklist_item_id' => $item->id,
                ], [
                    'status' => 'pending',
                ]);
            }
        }

        $this->createContractsForOnboarding($employee);
    }

    /**
     * Auto-create and send contracts for checklist items of type 'contract' that reference a template.
     */
    private function createContractsForOnboarding(EmployeeProfile $employee): void
    {
        $contractService = app(ContractService::class);

        $progressRecords = EmployeeOnboardingProgress::query()
            ->where('employee_profile_id', $employee->id)
            ->whereNull('employee_contract_id')
            ->whereHas('checklistItem', fn ($q) => $q->where('type', 'contract'))
            ->with('checklistItem')
            ->get();

        foreach ($progressRecords as $progress) {
            $templateId = $progress->checklistItem->metadata['contract_template_id'] ?? null;

            if (! $templateId) {
                continue;
            }

            $template = ContractTemplate::find($templateId);

            if (! $template) {
                continue;
            }

            // Skip if employee already has a non-voided contract from this template
            $exists = $employee->contracts()
                ->where('contract_template_id', $template->id)
                ->whereNot('status', 'voided')
                ->exists();

            if ($exists) {
                continue;
            }

            $contract = $contractService->createContractFromTemplate($template, $employee);
            $contractService->sendContract($contract);

            $progress->update(['employee_contract_id' => $contract->id]);
        }
    }

    /**
     * Assign all active contract templates to the employee and send them for signing.
     */
    public function assignDefaultContracts(EmployeeProfile $employee): void
    {
        $contractService = app(ContractService::class);

        $templates = ContractTemplate::query()
            ->active()
            ->get();

        foreach ($templates as $template) {
            // Skip if this employee already has a contract from this template (prevent duplicates)
            $exists = $employee->contracts()
                ->where('contract_template_id', $template->id)
                ->whereNot('status', 'voided')
                ->exists();

            if ($exists) {
                continue;
            }

            $contract = $contractService->createContractFromTemplate($template, $employee);
            $contractService->sendContract($contract);
        }
    }
}
