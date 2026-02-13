<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimateReadyForAgent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Estimate $estimate,
        public Deal $deal,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->deal->load('property');

        $address = $this->deal->property?->full_address ?? $this->deal->title;

        return (new MailMessage)
            ->subject(__('Estimate Ready: :address', ['address' => $address]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('The estimate for **:address** is ready.', ['address' => $address]))
            ->line(__('View it on your dashboard or share with your client.'))
            ->line('**'.__('Estimate').':** '.$this->estimate->estimate_number)
            ->line('**'.__('Total').':** $'.number_format($this->estimate->total, 2))
            ->action(__('View Dashboard'), route('agent.dashboard'))
            ->line(__('Thank you for your referral!'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->deal->load('property');

        return [
            'estimate_id' => $this->estimate->id,
            'deal_id' => $this->deal->id,
            'property_address' => $this->deal->property?->full_address ?? $this->deal->title,
            'estimate_total' => $this->estimate->total,
        ];
    }
}
