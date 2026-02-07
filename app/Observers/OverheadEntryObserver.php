<?php

namespace App\Observers;

use App\Models\JournalEntry;
use App\Models\OverheadEntry;
use App\Services\AccountingService;
use App\Services\JournalEntryService;

class OverheadEntryObserver
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected AccountingService $accountingService,
    ) {}

    public function created(OverheadEntry $entry): void
    {
        try {
            $this->journalEntryService->createFromOverheadEntry($entry);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function updated(OverheadEntry $entry): void
    {
        try {
            $existing = JournalEntry::where('reference_type', 'overhead')
                ->where('reference_id', $entry->id)
                ->where('status', '!=', JournalEntry::STATUS_VOID)
                ->first();

            if ($existing) {
                $this->accountingService->voidEntry($existing);
            }

            $this->journalEntryService->createFromOverheadEntry($entry);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
