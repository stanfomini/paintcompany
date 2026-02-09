<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Invoice;
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
    public function createFromPayment(Payment $payment): ?\App\Models\JournalEntry
    {
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
    public function createFromInvoice(Invoice $invoice): ?\App\Models\JournalEntry
    {
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
    public function createFromOverheadEntry(OverheadEntry $entry): ?\App\Models\JournalEntry
    {
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
    public function createRevenueRecognitionFromPayment(Payment $payment): ?\App\Models\JournalEntry
    {
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
            'reference_type' => 'payment',
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
     * Create a manual journal entry (delegates to AccountingService).
     */
    public function createManualEntry(array $data): \App\Models\JournalEntry
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
}
