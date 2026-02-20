<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class JobPayableService
{
    public function __construct(
        private ?AccountingService $accountingService = null,
    ) {
        $this->accountingService ??= new AccountingService;
    }

    /**
     * Create crew payables for a job based on the cost card.
     *
     * For painting crews: deposit (materials + sundries + material_tax + 5% labor)
     *                     and final (95% labor + cleanup + labor_tax + warranty_reserve).
     * For addon service crews: single payable for addon_cost.
     *
     * @return array<JobPayable>
     */
    public function createCrewPayables(Job $job, User $crew, ?ServiceCategory $category = null): array
    {
        $costCard = $job->costCards()->latest()->first();

        if (! $costCard) {
            return [];
        }

        $cogsAccount = $this->findAccountByCode('5010');
        $apAccount = $this->findAccountByCode('2000');

        $isPainting = $category?->isPainting() ?? true;

        $payables = [];

        if ($isPainting) {
            // Deposit: materials + sundries + material_tax + 5% of labor_cost
            $depositAmount = round(
                (float) $costCard->material_cost
                + (float) $costCard->sundries_cost
                + (float) $costCard->material_tax
                + ((float) $costCard->labor_cost * 0.05),
                2
            );

            if ($depositAmount > 0) {
                $payables[] = JobPayable::create([
                    'job_id' => $job->id,
                    'job_cost_card_id' => $costCard->id,
                    'payee_type' => JobPayable::PAYEE_TYPE_CREW,
                    'payee_user_id' => $crew->id,
                    'payee_name' => $crew->name,
                    'service_category_id' => $category?->id,
                    'category' => JobPayable::CATEGORY_MATERIALS,
                    'description' => 'Crew deposit: materials + sundries + tax + 5% labor',
                    'amount' => $depositAmount,
                    'status' => JobPayable::STATUS_PENDING,
                    'debit_account_id' => $cogsAccount?->id,
                    'credit_account_id' => $apAccount?->id,
                    'created_by' => Auth::id() ?? $crew->id,
                ]);
            }

            // Final: 95% of labor_cost + cleanup_cost + labor_tax + warranty_reserve
            $finalAmount = round(
                ((float) $costCard->labor_cost * 0.95)
                + (float) $costCard->cleanup_cost
                + (float) $costCard->labor_tax
                + (float) $costCard->warranty_reserve,
                2
            );

            if ($finalAmount > 0) {
                $payables[] = JobPayable::create([
                    'job_id' => $job->id,
                    'job_cost_card_id' => $costCard->id,
                    'payee_type' => JobPayable::PAYEE_TYPE_CREW,
                    'payee_user_id' => $crew->id,
                    'payee_name' => $crew->name,
                    'service_category_id' => $category?->id,
                    'category' => JobPayable::CATEGORY_LABOR,
                    'description' => 'Crew final: 95% labor + cleanup + labor tax + warranty reserve',
                    'amount' => $finalAmount,
                    'status' => JobPayable::STATUS_PENDING,
                    'debit_account_id' => $cogsAccount?->id,
                    'credit_account_id' => $apAccount?->id,
                    'created_by' => Auth::id() ?? $crew->id,
                ]);
            }
        } else {
            // Addon service (e.g., cleaning): single payable for addon_cost
            $addonAmount = (float) $costCard->addon_cost;

            if ($addonAmount > 0) {
                $payables[] = JobPayable::create([
                    'job_id' => $job->id,
                    'job_cost_card_id' => $costCard->id,
                    'payee_type' => JobPayable::PAYEE_TYPE_CREW,
                    'payee_user_id' => $crew->id,
                    'payee_name' => $crew->name,
                    'service_category_id' => $category?->id,
                    'category' => JobPayable::CATEGORY_ADDON,
                    'description' => "Addon service: {$category->name}",
                    'amount' => $addonAmount,
                    'status' => JobPayable::STATUS_PENDING,
                    'debit_account_id' => $cogsAccount?->id,
                    'credit_account_id' => $apAccount?->id,
                    'created_by' => Auth::id() ?? $crew->id,
                ]);
            }
        }

        return $payables;
    }

    /**
     * Create a vendor payable for a job.
     *
     * @param  array{category?: string, description?: string, amount: float, due_date?: string, notes?: string, debit_account_id?: int|null, credit_account_id?: int|null}  $data
     */
    public function createVendorPayable(Job $job, Vendor $vendor, array $data): JobPayable
    {
        $debitAccountId = $data['debit_account_id'] ?? $this->findAccountByCode('5030')?->id;
        $creditAccountId = $data['credit_account_id'] ?? $this->findAccountByCode('2000')?->id;

        return JobPayable::create([
            'job_id' => $job->id,
            'payee_type' => JobPayable::PAYEE_TYPE_VENDOR,
            'vendor_id' => $vendor->id,
            'payee_name' => $vendor->name,
            'category' => $data['category'] ?? JobPayable::CATEGORY_MATERIALS,
            'description' => $data['description'] ?? "Vendor payable: {$vendor->name}",
            'amount' => $data['amount'],
            'status' => JobPayable::STATUS_PENDING,
            'due_date' => $data['due_date'] ?? null,
            'debit_account_id' => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'created_by' => Auth::id() ?? 1,
            'notes' => $data['notes'] ?? null,
            'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
        ]);
    }

    /**
     * Create an employee payable for a job.
     *
     * @param  array{category?: string, description?: string, amount: float, due_date?: string, notes?: string, debit_account_id?: int|null, credit_account_id?: int|null}  $data
     */
    public function createEmployeePayable(Job $job, User $employee, array $data): JobPayable
    {
        $debitAccountId = $data['debit_account_id'] ?? $this->findAccountByCode('6010')?->id;
        $creditAccountId = $data['credit_account_id'] ?? $this->findAccountByCode('2000')?->id;

        return JobPayable::create([
            'job_id' => $job->id,
            'payee_type' => JobPayable::PAYEE_TYPE_EMPLOYEE,
            'payee_user_id' => $employee->id,
            'payee_name' => $employee->name,
            'category' => $data['category'] ?? JobPayable::CATEGORY_COMMISSION,
            'description' => $data['description'] ?? "Employee payable: {$employee->name}",
            'amount' => $data['amount'],
            'status' => JobPayable::STATUS_PENDING,
            'due_date' => $data['due_date'] ?? null,
            'debit_account_id' => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'created_by' => Auth::id() ?? 1,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Create a manual crew/contractor payable for a job.
     *
     * @param  array{category?: string, description?: string, amount: float, due_date?: string, notes?: string, debit_account_id?: int|null, credit_account_id?: int|null}  $data
     */
    public function createCrewManualPayable(Job $job, User $crew, array $data): JobPayable
    {
        $debitAccountId = $data['debit_account_id'] ?? $this->findAccountByCode('5010')?->id;
        $creditAccountId = $data['credit_account_id'] ?? $this->findAccountByCode('2000')?->id;

        return JobPayable::create([
            'job_id' => $job->id,
            'payee_type' => JobPayable::PAYEE_TYPE_CREW,
            'payee_user_id' => $crew->id,
            'payee_name' => $crew->name,
            'category' => $data['category'] ?? JobPayable::CATEGORY_LABOR,
            'description' => $data['description'] ?? "Crew payable: {$crew->name}",
            'amount' => $data['amount'],
            'status' => JobPayable::STATUS_PENDING,
            'due_date' => $data['due_date'] ?? null,
            'debit_account_id' => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'created_by' => Auth::id() ?? 1,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Mark a payable as paid and create a journal entry.
     *
     * @param  array{paid_via?: string, reference_number?: string, paid_at?: string, paid_from_account_id?: int|null}  $paymentData
     */
    public function markPaid(JobPayable $payable, array $paymentData = []): JobPayable
    {
        $paidFromAccountId = $paymentData['paid_from_account_id'] ?? null;

        if ($paidFromAccountId) {
            $payable->update(['paid_from_account_id' => $paidFromAccountId]);
        }

        $payable->markPaid($paymentData);

        // Create journal entry: DR debit_account / CR paid_from_account (or credit_account)
        $creditAccountId = $paidFromAccountId ?? $payable->credit_account_id;
        if ($payable->debit_account_id && $creditAccountId) {
            $paidAt = $payable->paid_at?->format('Y-m-d') ?? now()->format('Y-m-d');

            $entry = $this->accountingService->createJournalEntry([
                'description' => "Payable paid: {$payable->description} ({$payable->payee_name})",
                'transaction_date' => $paidAt,
                'recognition_date' => $paidAt,
                'reference_type' => 'payroll',
                'reference_id' => $payable->id,
                'status' => 'posted',
                'created_by' => Auth::id() ?? $payable->created_by,
                'lines' => [
                    [
                        'account_id' => $payable->debit_account_id,
                        'debit_amount' => (float) $payable->amount,
                        'credit_amount' => 0,
                        'description' => "COGS: {$payable->payee_name}",
                    ],
                    [
                        'account_id' => $creditAccountId,
                        'debit_amount' => 0,
                        'credit_amount' => (float) $payable->amount,
                        'description' => "Payment: {$payable->payee_name}",
                    ],
                ],
            ]);

            $payable->update(['journal_entry_id' => $entry->id]);
        }

        return $payable->refresh();
    }

    /**
     * Approve a payable.
     */
    public function approve(JobPayable $payable, User $approver): JobPayable
    {
        $payable->approve($approver);

        return $payable->refresh();
    }

    /**
     * Get a summary of payables for a job.
     *
     * @return array{total_pending: float, total_approved: float, total_paid: float, by_payee_type: array, by_category: array}
     */
    public function getJobPayablesSummary(Job $job): array
    {
        $payables = JobPayable::forJob($job->id)->get();

        $byPayeeType = $payables->groupBy('payee_type')->map(fn ($group) => round($group->sum('amount'), 2))->toArray();

        $byCategory = $payables->groupBy('category')->map(fn ($group) => round($group->sum('amount'), 2))->toArray();

        return [
            'total_pending' => round($payables->where('status', JobPayable::STATUS_PENDING)->sum('amount'), 2),
            'total_approved' => round($payables->where('status', JobPayable::STATUS_APPROVED)->sum('amount'), 2),
            'total_paid' => round($payables->where('status', JobPayable::STATUS_PAID)->sum('amount'), 2),
            'by_payee_type' => $byPayeeType,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Get all payables for a user (as payee).
     */
    public function getUserPayables(User $user): Collection
    {
        return JobPayable::forUser($user->id)
            ->with(['job', 'serviceCategory'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Find an account by its code within the current company setting.
     */
    protected function findAccountByCode(string $code): ?Account
    {
        $settings = CompanySetting::getInstance();

        return Account::where('company_setting_id', $settings->id)
            ->where('code', $code)
            ->first();
    }
}
