<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OverheadEntry;
use App\Models\Payment;

class JournalEntryService
{
    protected AccountingService $accountingService;

    public function __construct(?AccountingService $accountingService = null)
    {
        $this->accountingService = $accountingService ?? new AccountingService;
    }

    /**
     * Create a journal entry for a payment received.
     * DR Cash / CR Accounts Receivable
     */
    public function createFromPayment(Payment $payment): ?JournalEntry
    {
        if ($this->journalEntryExists('payment', $payment->id)) {
            return null;
        }

        $cashAccount = $this->findAccountByCode('1000');
        $arAccount = $this->findAccountByCode('1100');

        if (! $cashAccount || ! $arAccount) {
            return null;
        }

        $invoice = $payment->invoice;
        $description = "Payment received for {$invoice->invoice_number} via {$payment->method}";

        if ($payment->reference_number) {
            $description .= " (Ref: {$payment->reference_number})";
        }

        $receivedAt = $payment->received_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        return $this->accountingService->createJournalEntry([
            'description' => $description,
            'transaction_date' => $receivedAt,
            'recognition_date' => $receivedAt,
            'reference_type' => 'payment',
            'reference_id' => $payment->id,
            'status' => 'posted',
            'created_by' => $payment->recorded_by ?? auth()->id(),
            'lines' => [
                [
                    'account_id' => $cashAccount->id,
                    'debit_amount' => (float) $payment->amount,
                    'credit_amount' => 0,
                    'description' => 'Cash received',
                ],
                [
                    'account_id' => $arAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (float) $payment->amount,
                    'description' => "AR reduction for {$invoice->invoice_number}",
                ],
            ],
        ]);
    }

    /**
     * Create a journal entry for an invoice sent (accrual: recognize revenue).
     * DR Accounts Receivable / CR Revenue
     */
    public function createFromInvoice(Invoice $invoice): ?JournalEntry
    {
        if ($this->journalEntryExists('invoice', $invoice->id)) {
            return null;
        }

        $arAccount = $this->findAccountByCode('1100');
        $revenueAccount = $this->findAccountByCode('4000');

        if (! $arAccount || ! $revenueAccount) {
            return null;
        }

        $sentAt = $invoice->sent_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        return $this->accountingService->createJournalEntry([
            'description' => "Revenue recognized for {$invoice->invoice_number}",
            'transaction_date' => null,
            'recognition_date' => $sentAt,
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'status' => 'posted',
            'created_by' => auth()->id(),
            'lines' => [
                [
                    'account_id' => $arAccount->id,
                    'debit_amount' => (float) $invoice->total,
                    'credit_amount' => 0,
                    'description' => "AR for {$invoice->invoice_number}",
                ],
                [
                    'account_id' => $revenueAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (float) $invoice->total,
                    'description' => 'Painting services revenue',
                ],
            ],
        ]);
    }

    /**
     * Create a journal entry from an overhead expense entry.
     * DR Expense Account / CR Cash
     */
    public function createFromOverheadEntry(OverheadEntry $entry): ?JournalEntry
    {
        if ($this->journalEntryExists('overhead', $entry->id)) {
            return null;
        }

        $cashAccount = $this->findAccountByCode('1000');
        $expenseAccount = $this->mapOverheadToExpenseAccount($entry);

        if (! $cashAccount || ! $expenseAccount) {
            return null;
        }

        $category = $entry->budgetCategory;
        $entryDate = now()->setYear($entry->year)->setMonth($entry->month)->startOfMonth()->format('Y-m-d');

        return $this->accountingService->createJournalEntry([
            'description' => "Overhead expense: {$category->name} ({$this->monthName($entry->month)} {$entry->year})",
            'transaction_date' => $entryDate,
            'recognition_date' => $entryDate,
            'reference_type' => 'overhead',
            'reference_id' => $entry->id,
            'status' => 'posted',
            'created_by' => auth()->id(),
            'lines' => [
                [
                    'account_id' => $expenseAccount->id,
                    'debit_amount' => (float) $entry->amount,
                    'credit_amount' => 0,
                    'description' => $category->name,
                ],
                [
                    'account_id' => $cashAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (float) $entry->amount,
                    'description' => "Cash payment for {$category->name}",
                ],
            ],
        ]);
    }

    /**
     * Create a revenue recognition entry for a payment (cash basis).
     * DR Accounts Receivable / CR Revenue
     */
    public function createRevenueRecognitionFromPayment(Payment $payment): ?JournalEntry
    {
        if ($this->journalEntryExists('revenue_recognition', $payment->id)) {
            return null;
        }

        $arAccount = $this->findAccountByCode('1100');
        $revenueAccount = $this->findAccountByCode('4000');

        if (! $arAccount || ! $revenueAccount) {
            return null;
        }

        $invoice = $payment->invoice;
        $receivedAt = $payment->received_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        return $this->accountingService->createJournalEntry([
            'description' => "Revenue recognized (cash basis) for {$invoice->invoice_number}",
            'transaction_date' => $receivedAt,
            'recognition_date' => $receivedAt,
            'reference_type' => 'revenue_recognition',
            'reference_id' => $payment->id,
            'status' => 'posted',
            'created_by' => $payment->recorded_by ?? auth()->id(),
            'lines' => [
                [
                    'account_id' => $arAccount->id,
                    'debit_amount' => (float) $payment->amount,
                    'credit_amount' => 0,
                    'description' => "AR for {$invoice->invoice_number}",
                ],
                [
                    'account_id' => $revenueAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (float) $payment->amount,
                    'description' => 'Painting services revenue',
                ],
            ],
        ]);
    }

    /**
     * Create a journal entry for a finalized pay stub.
     * DR 6010 (Salary Expense) = gross_pay
     * CR 2410 (Federal Withholding) = federal_tax
     * CR 2420 (State Withholding) = state_tax
     * CR 2430 (FICA Payable) = social_security + medicare
     * CR 1000 (Cash) = net_pay
     */
    public function createFromPayStub(\App\Models\PayStub $stub): ?JournalEntry
    {
        if ($this->journalEntryExists('pay_stub', $stub->id)) {
            return null;
        }

        $salaryAccount = $this->findAccountByCode('6010');
        $federalTaxAccount = $this->findAccountByCode('2410');
        $stateTaxAccount = $this->findAccountByCode('2420');
        $ficaAccount = $this->findAccountByCode('2430');
        $cashAccount = $this->findAccountByCode('1000');

        if (! $salaryAccount || ! $cashAccount) {
            return null;
        }

        $grossPay = (float) $stub->gross_pay;
        $federalTax = (float) ($stub->federal_tax ?? 0);
        $stateTax = (float) ($stub->state_tax ?? 0);
        $fica = (float) ($stub->social_security ?? 0) + (float) ($stub->medicare ?? 0);
        $netPay = (float) $stub->net_pay;

        $employeeName = $stub->employeeProfile?->user?->name ?? 'Employee';
        $checkInfo = $stub->check_number ? " (Check #{$stub->check_number})" : '';
        $entryDate = $stub->payPeriod?->end_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        $lines = [
            [
                'account_id' => $salaryAccount->id,
                'debit_amount' => $grossPay,
                'credit_amount' => 0,
                'description' => "Wages: {$employeeName}",
            ],
        ];

        if ($federalTax > 0 && $federalTaxAccount) {
            $lines[] = [
                'account_id' => $federalTaxAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $federalTax,
                'description' => 'Federal withholding',
            ];
        }

        if ($stateTax > 0 && $stateTaxAccount) {
            $lines[] = [
                'account_id' => $stateTaxAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $stateTax,
                'description' => 'State withholding',
            ];
        }

        if ($fica > 0 && $ficaAccount) {
            $lines[] = [
                'account_id' => $ficaAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $fica,
                'description' => 'FICA (Social Security + Medicare)',
            ];
        }

        $lines[] = [
            'account_id' => $cashAccount->id,
            'debit_amount' => 0,
            'credit_amount' => $netPay,
            'description' => "Net pay: {$employeeName}{$checkInfo}",
        ];

        return $this->accountingService->createJournalEntry([
            'description' => "Payroll: {$employeeName}{$checkInfo}",
            'transaction_date' => $entryDate,
            'recognition_date' => $entryDate,
            'reference_type' => 'pay_stub',
            'reference_id' => $stub->id,
            'status' => 'posted',
            'created_by' => auth()->id(),
            'lines' => $lines,
        ]);
    }

    /**
     * Create a journal entry for a payment split transfer.
     * DR destination expense account / CR Cash
     */
    public function createFromPaymentSplit(Payment $payment, \App\Models\PaymentSplit $split): ?JournalEntry
    {
        if ($this->journalEntryExists('payment_split', $split->id)) {
            return null;
        }

        $cashAccount = $this->findAccountByCode('1000');

        if (! $cashAccount) {
            return null;
        }

        // Map split destination to an appropriate expense account
        $expenseAccount = $this->mapSplitToAccount($split);

        if (! $expenseAccount) {
            return null;
        }

        $splitDate = $split->executed_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        return $this->accountingService->createJournalEntry([
            'description' => "Payment split: {$split->destination_name} ({$payment->invoice?->invoice_number})",
            'transaction_date' => $splitDate,
            'recognition_date' => $splitDate,
            'reference_type' => 'payment_split',
            'reference_id' => $split->id,
            'status' => 'posted',
            'created_by' => auth()->id(),
            'lines' => [
                [
                    'account_id' => $expenseAccount->id,
                    'debit_amount' => (float) $split->amount,
                    'credit_amount' => 0,
                    'description' => "Transfer to {$split->destination_name}",
                ],
                [
                    'account_id' => $cashAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (float) $split->amount,
                    'description' => "Cash transfer for {$split->destination_name}",
                ],
            ],
        ]);
    }

    /**
     * Create a manual journal entry (delegates to AccountingService).
     */
    public function createManualEntry(array $data): JournalEntry
    {
        $data['reference_type'] = $data['reference_type'] ?? 'manual';

        return $this->accountingService->createJournalEntry($data);
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

    /**
     * Map an overhead entry's budget category to the appropriate expense account.
     * Uses the budget-account mapping when available, falls back to section defaults.
     */
    protected function mapOverheadToExpenseAccount(OverheadEntry $entry): ?Account
    {
        $category = $entry->budgetCategory;

        if (! $category) {
            return $this->findAccountByCode('6990');
        }

        // Use the budget category's mapped account(s)
        $account = $category->accounts()->first();
        if ($account) {
            return $account;
        }

        // Fallback to section-level defaults
        $sectionFallback = [
            'vehicle_expense' => '6400',
            'business_expense' => '6000',
            'variable_expense' => '5000',
        ];

        $code = $sectionFallback[$category->section] ?? '6990';

        return $this->findAccountByCode($code);
    }

    protected function monthName(int $month): string
    {
        return \DateTime::createFromFormat('!m', (string) $month)->format('F');
    }

    /**
     * Check if a journal entry already exists for a given reference.
     */
    protected function journalEntryExists(string $referenceType, int $referenceId): bool
    {
        return JournalEntry::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->exists();
    }

    /**
     * Map a payment split destination to the appropriate GL account.
     * Falls back to the operating expense account.
     */
    protected function mapSplitToAccount(\App\Models\PaymentSplit $split): ?Account
    {
        // Map by destination name pattern
        $nameMap = [
            'cogs' => '5000',
            'tax' => '2300',
            'marketing' => '6300',
            'sales' => '6310',
            'operations' => '6000',
            'profit' => '3300',
        ];

        $lowerName = strtolower($split->destination_name);
        foreach ($nameMap as $keyword => $code) {
            if (str_contains($lowerName, $keyword)) {
                return $this->findAccountByCode($code);
            }
        }

        // Default to operating expense
        return $this->findAccountByCode('6000');
    }
}
