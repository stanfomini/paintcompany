<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payment;
use App\Notifications\InvoiceSent;
use App\Notifications\PaymentReceived;

class InvoiceService
{
    /**
     * Create a deposit invoice when an estimate is accepted.
     */
    public function createDepositInvoice(Estimate $estimate, Job $job): Invoice
    {
        $settings = CompanySetting::getInstance();
        $customer = $estimate->deal?->customer;

        if (! $customer) {
            throw new \RuntimeException('Cannot create invoice: no customer found for estimate.');
        }

        $depositPercent = (float) ($settings->deposit_percent ?? 50);
        $subtotal = round((float) $estimate->total * ($depositPercent / 100), 2);
        $taxRate = (float) ($estimate->tax_rate ?? 0);
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $taxAmount;

        return Invoice::create([
            'company_setting_id' => $settings->id,
            'estimate_id' => $estimate->id,
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'type' => Invoice::TYPE_DEPOSIT,
            'status' => Invoice::STATUS_DRAFT,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'due_date' => now()->addDays(7),
            'notes' => "Deposit for Estimate #{$estimate->estimate_number}",
        ]);
    }

    /**
     * Create a final invoice when a job is completed.
     * Final invoice = total estimate price - deposits already paid.
     */
    public function createFinalInvoice(Job $job): Invoice
    {
        $settings = CompanySetting::getInstance();
        $estimate = $job->estimate;
        $customer = $job->deal?->customer;

        if (! $customer) {
            throw new \RuntimeException('Cannot create invoice: no customer found for job.');
        }

        // Calculate deposits already paid
        $depositsPaid = Invoice::where('job_id', $job->id)
            ->where('type', Invoice::TYPE_DEPOSIT)
            ->where('status', Invoice::STATUS_PAID)
            ->sum('total');

        $estimateTotal = $estimate ? (float) $estimate->total : 0;
        $subtotal = max(0, $estimateTotal - $depositsPaid);
        $taxRate = $estimate ? (float) ($estimate->tax_rate ?? 0) : 0;
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $taxAmount;

        return Invoice::create([
            'company_setting_id' => $settings->id,
            'estimate_id' => $estimate?->id,
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'type' => Invoice::TYPE_FINAL,
            'status' => Invoice::STATUS_DRAFT,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'due_date' => now()->addDays(30),
            'notes' => 'Final invoice for completed work',
        ]);
    }

    /**
     * Send an invoice to the customer.
     */
    public function sendInvoice(Invoice $invoice): void
    {
        $invoice->generatePublicToken(30);
        $invoice->update([
            'status' => Invoice::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $customer = $invoice->customer;
        if ($customer) {
            $customer->notify(new InvoiceSent($invoice));
        }
    }

    /**
     * Resend an already-sent invoice to the customer.
     * Refreshes the public token and re-sends the notification email.
     */
    public function resendInvoice(Invoice $invoice): void
    {
        $invoice->generatePublicToken(30);
        $invoice->update(['sent_at' => now()]);

        $customer = $invoice->customer;
        if ($customer) {
            $customer->notify(new InvoiceSent($invoice));
        }
    }

    /**
     * Generate a unique invoice number.
     */
    public function generateInvoiceNumber(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        $lastNumber = Invoice::where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        if ($lastNumber) {
            $lastSequence = (int) substr($lastNumber, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        return $prefix.str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Mark an invoice as paid.
     */
    public function markAsPaid(Invoice $invoice): void
    {
        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'amount_paid' => $invoice->total,
            'balance_due' => 0,
            'paid_at' => now(),
        ]);
    }

    /**
     * Mark an invoice as void.
     */
    public function markAsVoid(Invoice $invoice): void
    {
        $invoice->update([
            'status' => Invoice::STATUS_VOID,
        ]);
    }

    /**
     * Record a manual payment against an invoice.
     */
    public function recordManualPayment(Invoice $invoice, array $data): Payment
    {
        $settings = CompanySetting::getInstance();

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'company_setting_id' => $settings->id,
            'amount' => (float) $data['amount'],
            'method' => $data['method'] ?? Payment::METHOD_OTHER,
            'status' => Payment::STATUS_COMPLETED,
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'received_at' => $data['received_at'] ?? now(),
            'recorded_by' => $data['recorded_by'] ?? auth()->id(),
        ]);

        $invoice->recalculateAmounts();

        // Notify customer of payment
        $customer = $invoice->customer;
        if ($customer) {
            $customer->notify(new PaymentReceived($payment));
        }

        return $payment;
    }

    /**
     * Create a custom invoice (not tied to estimate/job).
     */
    public function createCustomInvoice(Customer $customer, array $data): Invoice
    {
        $settings = CompanySetting::getInstance();

        $subtotal = (float) ($data['subtotal'] ?? 0);
        $taxRate = (float) ($data['tax_rate'] ?? $settings->tax_rate ?? 0);
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $taxAmount;

        return Invoice::create([
            'company_setting_id' => $settings->id,
            'customer_id' => $customer->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'type' => Invoice::TYPE_CUSTOM,
            'status' => Invoice::STATUS_DRAFT,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'due_date' => $data['due_date'] ?? now()->addDays(30),
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
        ]);
    }
}
