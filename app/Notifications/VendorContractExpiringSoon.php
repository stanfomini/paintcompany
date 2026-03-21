<?php

namespace App\Notifications;

use App\Models\VendorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractExpiringSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public VendorContract $contract)
    {
        $this->contract->loadMissing('vendor');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresAt = $this->contract->expires_at?->format('M j, Y') ?? 'N/A';

        return (new MailMessage)
            ->subject('Contract Expiring Soon: '.$this->contract->title)
            ->greeting('Hello!')
            ->line('A vendor contract is expiring soon.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->line('**Expires:** '.$expiresAt)
            ->action('View Contract', route('vendor-contracts.sign-internal', [
                'vendor' => $this->contract->vendor_id,
                'contract' => $this->contract->id,
            ]))
            ->line('Consider renewing the contract before it expires.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_expiring_soon',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'expires_at' => $this->contract->expires_at?->toISOString(),
        ];
    }
}
