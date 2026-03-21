<?php

namespace App\Console\Commands;

use App\Models\VendorContract;
use App\Notifications\VendorContractExpired;
use App\Notifications\VendorContractExpiringSoon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckVendorContractExpiration extends Command
{
    protected $signature = 'vendor-contracts:check-expiration';

    protected $description = 'Send 30-day expiry warnings and auto-expire vendor contracts past their expiration date';

    public function handle(): int
    {
        $this->sendExpiryWarnings();
        $this->autoExpireContracts();

        return self::SUCCESS;
    }

    /**
     * Send 30-day warning notifications for contracts expiring soon.
     */
    protected function sendExpiryWarnings(): void
    {
        $contracts = VendorContract::query()
            ->where('status', VendorContract::STATUS_FULLY_EXECUTED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(30))
            ->whereNull('expiry_warning_sent_at')
            ->with(['signers.user', 'vendor', 'vendorSignature', 'creator'])
            ->get();

        if ($contracts->isEmpty()) {
            $this->info('No vendor contracts expiring soon.');

            return;
        }

        foreach ($contracts as $contract) {
            $notification = new VendorContractExpiringSoon($contract);

            $this->notifyRecipients($contract, $notification);

            $contract->update(['expiry_warning_sent_at' => now()]);
        }

        $this->info("Sent expiry warnings for {$contracts->count()} vendor contract(s).");
    }

    /**
     * Auto-expire contracts that have passed their expiration date.
     */
    protected function autoExpireContracts(): void
    {
        $contracts = VendorContract::query()
            ->where('status', VendorContract::STATUS_FULLY_EXECUTED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['signers.user', 'vendor', 'vendorSignature', 'creator'])
            ->get();

        if ($contracts->isEmpty()) {
            $this->info('No vendor contracts to auto-expire.');

            return;
        }

        foreach ($contracts as $contract) {
            $contract->update([
                'status' => VendorContract::STATUS_EXPIRED,
                'is_valid' => false,
            ]);

            $notification = new VendorContractExpired($contract);

            $this->notifyRecipients($contract, $notification);
        }

        $this->info("Auto-expired {$contracts->count()} vendor contract(s).");
    }

    /**
     * Notify internal signers, creator, and vendor about a contract event.
     */
    protected function notifyRecipients(VendorContract $contract, object $notification): void
    {
        $notifiedUserIds = [];

        foreach ($contract->signers as $signer) {
            if ($signer->user) {
                $signer->user->notify(clone $notification);
                $notifiedUserIds[] = $signer->user_id;
            }
        }

        if ($contract->creator && ! in_array($contract->created_by, $notifiedUserIds)) {
            $contract->creator->notify(clone $notification);
        }

        $vendorEmail = $contract->vendor?->email;

        if ($vendorEmail) {
            Notification::route('mail', $vendorEmail)->notify(clone $notification);
        }
    }
}
