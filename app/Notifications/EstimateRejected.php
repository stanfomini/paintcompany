<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimateRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'estimates';

    public function __construct(
        public Estimate $estimate,
        public ?string $reason = null
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (method_exists($notifiable, 'wantsEmail')) {
            if ($notifiable->wantsEmail($this->pushCategory)) {
                $channels[] = 'mail';
            }
        } else {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->estimate->load(['deal.customer']);

        $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';
        $adminUrl = route('estimates.show', $this->estimate);

        $message = (new MailMessage)
            ->subject(__('Estimate Rejected: :number', ['number' => $this->estimate->estimate_number]))
            ->greeting(__('Estimate Update'))
            ->line(__('**:customerName** has rejected estimate **:number**.', ['customerName' => $customerName, 'number' => $this->estimate->estimate_number]))
            ->line("**{$this->estimate->title}**")
            ->line('**'.__('Total').':** $'.number_format($this->estimate->total, 2));

        if ($this->reason) {
            $message->line('---')
                ->line('**'.__('Reason provided').':**')
                ->line($this->reason);
        }

        $message->action(__('View Estimate'), $adminUrl)
            ->line(__('Consider following up with the customer to understand their concerns.'));

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'estimate_id' => $this->estimate->id,
            'estimate_number' => $this->estimate->estimate_number,
            'total' => $this->estimate->total,
            'customer_name' => $this->estimate->deal?->customer?->full_name,
            'reason' => $this->reason,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';

        return new WebPushMessage(
            title: 'Estimate Rejected',
            body: "{$customerName} rejected estimate #{$this->estimate->estimate_number}",
            url: "/estimates/{$this->estimate->id}",
            tag: "estimate-{$this->estimate->id}",
        );
    }
}
