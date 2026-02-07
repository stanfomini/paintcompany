<?php

namespace App\Observers;

use App\Models\CompanySetting;
use App\Models\Payment;
use App\Services\AllocationEvaluationService;
use App\Services\JournalEntryService;

class PaymentObserver
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected AllocationEvaluationService $allocationService,
    ) {}

    public function created(Payment $payment): void
    {
        if ($payment->status !== Payment::STATUS_COMPLETED) {
            return;
        }

        try {
            $this->journalEntryService->createFromPayment($payment);

            // In cash mode, also recognize revenue at time of cash receipt
            // (In accrual/modified_cash, InvoiceObserver handles revenue when invoice is sent)
            $mode = CompanySetting::getInstance()->accounting_mode ?? 'cash';

            if ($mode === 'cash') {
                $this->journalEntryService->createRevenueRecognitionFromPayment($payment);
            }

            $payment->invoice->recalculateAmounts();
        } catch (\Throwable $e) {
            report($e);
        }

        // Recalculate allocations when payment method changes context
        try {
            $job = $payment->invoice?->job;
            if ($job) {
                $this->allocationService->recalculateForJob($job);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
