<?php

namespace App\Observers;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Services\JournalEntryService;

class InvoiceObserver
{
    public function __construct(protected JournalEntryService $journalEntryService) {}

    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status') || $invoice->status !== Invoice::STATUS_SENT) {
            return;
        }

        $mode = CompanySetting::getInstance()->accounting_mode ?? 'cash';

        if (! in_array($mode, ['accrual', 'modified_cash'])) {
            return;
        }

        try {
            $this->journalEntryService->createFromInvoice($invoice);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
