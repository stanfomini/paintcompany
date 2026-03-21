<?php

namespace App\Notifications;

use App\Models\VendorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractSentToVendor extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public VendorContract $contract,
        public string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Contract for Your Review & Signature: '.$this->contract->title)
            ->greeting('Hello!')
            ->line('A contract has been prepared for your review and signature.')
            ->line('**Contract:** '.$this->contract->title)
            ->action('Review & Sign Contract', $this->url)
            ->line('Please review and sign the contract at your earliest convenience.');
    }
}
