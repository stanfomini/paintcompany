<?php

namespace App\Notifications;

use App\Models\VendorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractInternalSigningRequest extends Notification implements ShouldQueue
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
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable->name ?? 'there';

        return (new MailMessage)
            ->subject('Contract Signing Required: '.$this->contract->title)
            ->greeting("Hello {$name}!")
            ->line('A vendor contract requires your signature.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->action('Review & Sign', url('/vendor-contracts/'.$this->contract->id))
            ->line('Please review and sign the contract at your earliest convenience.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_internal_signing_request',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'vendor_name' => $this->contract->vendor?->name,
        ];
    }
}
