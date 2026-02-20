<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Payment;
use App\Models\PaymentSplit;
use Illuminate\Support\Facades\Log;

class PaymentSplitService
{
    public function __construct(
        protected StripePaymentService $stripePaymentService,
        protected IncreaseService $increaseService,
    ) {}

    /**
     * Split a payment according to the company's split configuration.
     */
    public function splitPayment(Payment $payment): void
    {
        $settings = CompanySetting::getInstance();

        if (! $settings->isSplittingEnabled()) {
            return;
        }

        $config = $settings->getPaymentSplitConfig();
        $splitAmounts = $this->calculateSplitAmounts((float) $payment->amount, $config);

        $payment->update(['split_status' => 'pending']);

        // Create PaymentSplit records
        $splits = [];
        foreach ($splitAmounts as $split) {
            $splits[] = $payment->splits()->create([
                'destination_name' => $split['name'],
                'destination_type' => $split['stripe_account_id'] ? PaymentSplit::TYPE_STRIPE_CONNECT : PaymentSplit::TYPE_INCREASE_ACH,
                'destination_account_id' => $split['stripe_account_id'] ?? $split['increase_account_id'],
                'amount' => $split['amount'],
                'percentage' => $split['percentage'],
                'status' => PaymentSplit::STATUS_PENDING,
            ]);
        }

        $allSucceeded = true;

        // Execute Stripe splits
        $stripeSplits = collect($splits)->filter(fn (PaymentSplit $s) => $s->destination_type === PaymentSplit::TYPE_STRIPE_CONNECT);
        if ($stripeSplits->isNotEmpty()) {
            try {
                $this->executeStripeSplits($payment, $stripeSplits->all());
            } catch (\Throwable $e) {
                $allSucceeded = false;
                Log::error('Stripe split execution failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        // Execute Increase splits
        $increaseSplits = collect($splits)->filter(fn (PaymentSplit $s) => $s->destination_type === PaymentSplit::TYPE_INCREASE_ACH);
        if ($increaseSplits->isNotEmpty()) {
            try {
                $this->executeIncreaseSplits($payment, $increaseSplits->all());
            } catch (\Throwable $e) {
                $allSucceeded = false;
                Log::error('Increase split execution failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        $payment->update([
            'split_status' => $allSucceeded ? 'completed' : 'failed',
            'split_executed_at' => now(),
        ]);

        // Create journal entries for completed splits
        if ($allSucceeded) {
            $this->createSplitJournalEntries($payment, $splits);
        }
    }

    /**
     * Create journal entries for completed payment splits.
     */
    protected function createSplitJournalEntries(Payment $payment, array $splits): void
    {
        $journalService = app(JournalEntryService::class);

        foreach ($splits as $split) {
            if ($split->status !== PaymentSplit::STATUS_COMPLETED) {
                continue;
            }

            try {
                $journalService->createFromPaymentSplit($payment, $split);
            } catch (\Throwable $e) {
                Log::warning('Failed to create JE for payment split', [
                    'split_id' => $split->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Calculate split amounts, ensuring the last split absorbs rounding remainder.
     *
     * @param  array<int, array{name: string, percentage: float, stripe_account_id: string|null, increase_account_id: string|null}>  $config
     * @return array<int, array{name: string, percentage: float, amount: float, stripe_account_id: string|null, increase_account_id: string|null}>
     */
    public function calculateSplitAmounts(float $total, array $config): array
    {
        $results = [];
        $allocated = 0;

        foreach ($config as $index => $entry) {
            $isLast = $index === count($config) - 1;

            if ($isLast) {
                // Last split gets the remainder to avoid penny errors
                $amount = round($total - $allocated, 2);
            } else {
                $amount = round($total * ($entry['percentage'] / 100), 2);
                $allocated += $amount;
            }

            $results[] = [
                'name' => $entry['name'],
                'percentage' => $entry['percentage'],
                'amount' => $amount,
                'stripe_account_id' => $entry['stripe_account_id'] ?? null,
                'increase_account_id' => $entry['increase_account_id'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Execute Stripe Connect transfers for splits.
     *
     * @param  array<int, PaymentSplit>  $splits
     */
    public function executeStripeSplits(Payment $payment, array $splits): void
    {
        foreach ($splits as $split) {
            try {
                $transfer = $this->stripePaymentService->createTransfer(
                    amountCents: (int) round((float) $split->amount * 100),
                    destinationAccountId: $split->destination_account_id,
                    transferGroup: "payment_{$payment->id}",
                );

                $split->markCompleted($transfer->id);
            } catch (\Throwable $e) {
                $split->markFailed($e->getMessage());

                throw $e;
            }
        }
    }

    /**
     * Execute Increase account transfers for splits.
     *
     * @param  array<int, PaymentSplit>  $splits
     */
    public function executeIncreaseSplits(Payment $payment, array $splits): void
    {
        $settings = CompanySetting::getInstance();

        foreach ($splits as $split) {
            try {
                $result = $this->increaseService->createAccountTransfer(
                    fromAccountId: $settings->increase_primary_account_id ?? '',
                    toAccountId: $split->destination_account_id,
                    amountCents: (int) round((float) $split->amount * 100),
                    description: "Payment split: {$split->destination_name}",
                );

                $split->markCompleted($result['id']);
            } catch (\Throwable $e) {
                $split->markFailed($e->getMessage());

                throw $e;
            }
        }
    }

    /**
     * Retry failed splits for a payment.
     */
    public function retryFailedSplits(Payment $payment): void
    {
        $failedSplits = $payment->splits()->where('status', PaymentSplit::STATUS_FAILED)->get();

        if ($failedSplits->isEmpty()) {
            return;
        }

        // Reset to pending
        $failedSplits->each(fn (PaymentSplit $s) => $s->update([
            'status' => PaymentSplit::STATUS_PENDING,
            'error_message' => null,
        ]));

        $allSucceeded = true;

        $stripeSplits = $failedSplits->filter(fn (PaymentSplit $s) => $s->destination_type === PaymentSplit::TYPE_STRIPE_CONNECT);
        if ($stripeSplits->isNotEmpty()) {
            try {
                $this->executeStripeSplits($payment, $stripeSplits->all());
            } catch (\Throwable $e) {
                $allSucceeded = false;
            }
        }

        $increaseSplits = $failedSplits->filter(fn (PaymentSplit $s) => $s->destination_type === PaymentSplit::TYPE_INCREASE_ACH);
        if ($increaseSplits->isNotEmpty()) {
            try {
                $this->executeIncreaseSplits($payment, $increaseSplits->all());
            } catch (\Throwable $e) {
                $allSucceeded = false;
            }
        }

        $payment->update([
            'split_status' => $allSucceeded ? 'completed' : 'failed',
            'split_executed_at' => now(),
        ]);
    }
}
