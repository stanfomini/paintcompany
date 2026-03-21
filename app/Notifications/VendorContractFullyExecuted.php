<?php

namespace App\Notifications;

use App\Models\VendorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractFullyExecuted extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Contract Fully Executed: '.$this->contract->title)
            ->greeting('Hello!')
            ->line('A vendor contract has been fully signed by all parties.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->action('View Contract', url('/vendor-contracts/'.$this->contract->id))
            ->line('A copy of the executed contract is attached.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_fully_executed',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
        ];
    }
}
