<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Estimate;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimateAccepted extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'estimates';

    public function __construct(public Estimate $estimate) {}

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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->estimate->load(['deal.customer', 'signature', 'options' => fn ($q) => $q->where('is_selected', true)]);

        $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';
        $adminUrl = route('estimates.show', $this->estimate);

        $message = (new MailMessage)
            ->subject(__('Estimate Accepted: :number', ['number' => $this->estimate->estimate_number]))
            ->greeting(__('Great news!'))
            ->line(__('**:customerName** has accepted estimate **:number**.', ['customerName' => $customerName, 'number' => $this->estimate->estimate_number]))
            ->line("**{$this->estimate->title}**")
            ->line('**'.__('Total').':** $'.number_format($this->estimate->total, 2));

        if ($this->estimate->signature) {
            $message->line('---')
                ->line('**'.__('Signature Details').':**')
                ->line(__('Signed by').': '.$this->estimate->signature->signer_name)
                ->line(__('Email').': '.$this->estimate->signature->signer_email)
                ->line(__('Date').': '.$this->estimate->signature->signed_at->format('M j, Y g:i A'));
        }

        $selectedOptions = $this->estimate->options;
        if ($selectedOptions->count() > 0) {
            $message->line('---')
                ->line('**'.__('Selected Options').':**');

            foreach ($selectedOptions as $option) {
                $price = number_format($option->calculated_price ?? $option->fixed_price ?? 0, 2);
                $message->line("- {$option->name}: \${$price}");
            }
        }

        $message->action(__('View Estimate'), $adminUrl)
            ->line(__('Log in to view the full details and begin project scheduling.'));

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
        ];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';

        return new SmsMessage(
            body: "{$customerName} accepted estimate #{$this->estimate->estimate_number}!",
            deepLink: route('estimates.show', $this->estimate),
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';

        return new WebPushMessage(
            title: 'Estimate Accepted',
            body: "{$customerName} accepted estimate #{$this->estimate->estimate_number}",
            url: "/estimates/{$this->estimate->id}",
            tag: "estimate-{$this->estimate->id}",
        );
    }
}
