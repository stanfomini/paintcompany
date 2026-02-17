<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\ContractSentForSigning;

class ContractService
{
    public function __construct(
        private PdfFlatteningService $pdfFlatteningService = new PdfFlatteningService,
    ) {}

    /**
     * Replace all {tag} placeholders with actual data from the employee profile and company settings.
     */
    public function resolveMergeTags(string $content, EmployeeProfile $employee): string
    {
        $settings = CompanySetting::getInstance();
        $user = $employee->user;

        $companyAddress = implode(', ', array_filter([
            $settings->address_line_1,
            $settings->address_line_2,
            $settings->city,
            $settings->state ? ($settings->state.' '.$settings->zip_code) : $settings->zip_code,
        ]));

        $replacements = [
            '{employee_name}' => $user->name ?? '',
            '{employee_number}' => $employee->employee_number ?? '',
            '{job_title}' => $employee->job_title ?? '',
            '{department}' => $employee->department ?? '',
            '{pay_rate}' => $employee->pay_rate ? '$'.number_format((float) $employee->pay_rate, 2) : '',
            '{pay_type}' => $employee->pay_type ?? '',
            '{hire_date}' => $employee->hire_date?->format('m/d/Y') ?? '',
            '{company_name}' => $settings->company_name ?? '',
            '{company_address}' => $companyAddress,
            '{date}' => now()->format('m/d/Y'),
            '{employee_address}' => $employee->getFullAddress() ?? '',
            '{employee_phone}' => $employee->phone ?? '',
            '{employee_email}' => $user->email ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Create a new contract from a template for an employee.
     */
    public function createContractFromTemplate(
        ContractTemplate $template,
        EmployeeProfile $employee,
        ?string $customContent = null,
    ): EmployeeContract {
        $content = $customContent ?? $template->content ?? '';
        $resolvedContent = $this->resolveMergeTags($content, $employee);

        return EmployeeContract::create([
            'employee_profile_id' => $employee->id,
            'contract_template_id' => $template->id,
            'title' => $template->name,
            'content' => $resolvedContent,
            'status' => 'draft',
        ]);
    }

    /**
     * Mark a contract as sent.
     */
    public function sendContract(EmployeeContract $contract): void
    {
        $contract->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $contract->load('employeeProfile.user');
        if ($contract->employeeProfile?->user) {
            $contract->employeeProfile->user->notify(new ContractSentForSigning($contract));
        }
    }

    /**
     * Sign a contract with signature data.
     *
     * @param  array{signature_data: string, signature_type: string, signer_name: string, ip: string, user_agent: string}  $signatureData
     */
    public function signContract(EmployeeContract $contract, array $signatureData): void
    {
        $contract->markSigned($signatureData);
    }

    /**
     * Countersign a contract (company representative).
     */
    public function countersignContract(EmployeeContract $contract, User $signer, string $signatureData): void
    {
        $contract->update([
            'countersigned_by' => $signer->id,
            'countersigned_at' => now(),
            'countersign_data' => $signatureData,
        ]);
    }

    /**
     * Save employer field values, countersign, and send the contract.
     */
    public function saveEmployerFieldsAndSend(
        EmployeeContract $contract,
        array $fieldValues,
        User $signer,
        string $signatureData,
    ): void {
        $existing = $contract->field_values ?? [];
        $merged = array_merge($existing, $fieldValues);

        $contract->update([
            'field_values' => $merged,
            'countersigned_by' => $signer->id,
            'countersigned_at' => now(),
            'countersign_data' => $signatureData,
        ]);

        $this->sendContract($contract);
    }

    /**
     * Countersign a rich text contract and send it.
     */
    public function countersignAndSend(
        EmployeeContract $contract,
        User $signer,
        string $signatureData,
    ): void {
        $contract->update([
            'countersigned_by' => $signer->id,
            'countersigned_at' => now(),
            'countersign_data' => $signatureData,
        ]);

        $this->sendContract($contract);
    }

    /**
     * Void a contract.
     */
    public function voidContract(EmployeeContract $contract): void
    {
        $contract->update([
            'status' => 'voided',
        ]);
    }

    /**
     * Create a new contract from a PDF template, pre-filling merge-tagged field values.
     */
    public function createPdfContractFromTemplate(
        ContractTemplate $template,
        EmployeeProfile $employee,
    ): EmployeeContract {
        $fieldValues = $this->resolvePdfMergeTags($template, $employee);

        return EmployeeContract::create([
            'employee_profile_id' => $employee->id,
            'contract_template_id' => $template->id,
            'title' => $template->name,
            'content' => '',
            'pdf_path' => $template->pdf_path,
            'field_values' => $fieldValues,
            'status' => 'draft',
        ]);
    }

    /**
     * Resolve merge tags for PDF template fields into field_values keyed by field ID.
     *
     * @return array<string, string>
     */
    public function resolvePdfMergeTags(ContractTemplate $template, EmployeeProfile $employee): array
    {
        $fields = $template->fields ?? [];
        $fieldList = $fields['fields'] ?? [];

        if (empty($fieldList)) {
            return [];
        }

        $settings = CompanySetting::getInstance();
        $user = $employee->user;

        $companyAddress = implode(', ', array_filter([
            $settings->address_line_1,
            $settings->address_line_2,
            $settings->city,
            $settings->state ? ($settings->state.' '.$settings->zip_code) : $settings->zip_code,
        ]));

        $tagMap = [
            'employee_name' => $user->name ?? '',
            'employee_number' => $employee->employee_number ?? '',
            'job_title' => $employee->job_title ?? '',
            'department' => $employee->department ?? '',
            'pay_rate' => $employee->pay_rate ? '$'.number_format((float) $employee->pay_rate, 2) : '',
            'pay_type' => $employee->pay_type ?? '',
            'hire_date' => $employee->hire_date?->format('m/d/Y') ?? '',
            'company_name' => $settings->company_name ?? '',
            'company_address' => $companyAddress,
            'date' => now()->format('m/d/Y'),
            'employee_address' => $employee->getFullAddress() ?? '',
            'employee_phone' => $employee->phone ?? '',
            'employee_email' => $user->email ?? '',
        ];

        $fieldValues = [];
        foreach ($fieldList as $field) {
            $fieldId = $field['id'] ?? '';
            $mergeTag = $field['merge_tag'] ?? '';

            if ($fieldId && $mergeTag && isset($tagMap[$mergeTag])) {
                $fieldValues[$fieldId] = $tagMap[$mergeTag];
            }
        }

        return $fieldValues;
    }

    /**
     * Sign a PDF contract: store field values, mark signed, flatten the PDF.
     *
     * @param  array{signature_data: string, signature_type: string, signer_name: string, ip: string, user_agent: string}  $signatureData
     * @param  array<string, string>  $fieldValues  All field values including user-entered ones
     */
    public function signPdfContract(EmployeeContract $contract, array $signatureData, array $fieldValues = []): void
    {
        // Merge any pre-existing field values with newly submitted ones
        $existingValues = $contract->field_values ?? [];
        $mergedValues = array_merge($existingValues, $fieldValues);

        $contract->update([
            'field_values' => $mergedValues,
        ]);

        $contract->markSigned($signatureData);

        // Flatten the PDF with all field values overlaid
        $template = $contract->template;

        if ($template && $template->content_type === 'pdf' && $template->pdf_path) {
            $fields = $template->fields['fields'] ?? [];
            $pages = $template->fields['pages'] ?? [];

            $flattenedPath = $this->pdfFlatteningService->flatten(
                $template->pdf_path,
                $fields,
                $mergedValues,
                $pages,
            );

            $contract->update([
                'pdf_path' => $flattenedPath,
            ]);
        }
    }
}
