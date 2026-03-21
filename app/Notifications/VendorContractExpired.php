<?php

namespace App\Notifications;

use App\Models\VendorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractExpired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public VendorContract $contract) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Contract Expired: '.$this->contract->title)
            ->greeting('Hello!')
            ->line('A vendor contract has expired.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->action('View Contract', url('/'))
            ->line('You may renew this contract if needed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'vendor_name' => $this->contract->vendor?->name,
        ];
    }
}
