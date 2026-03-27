<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Deal;
use App\Models\Estimate;
use App\Notifications\Concerns\HasSmsChannel;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimateReadyForAgent extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable, RestrictsToUserTypes;

    /** @var array<string> Only agent users should receive this */
    protected array $allowedUserTypes = ['agent'];

    public string $pushCategory = 'estimates';

    public function __construct(
        public Estimate $estimate,
        public Deal $deal,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $this->isAllowedRecipient($notifiable)) {
            return [];
        }

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

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: "Estimate #{$this->estimate->estimate_number} is ready for your review.",
            deepLink: route('estimates.show', $this->estimate),
        );
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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $address = $this->deal->property?->full_address ?? $this->deal->title;

        return new WebPushMessage(
            title: 'Estimate Ready',
            body: "Estimate for {$address} is ready for review",
            url: '/agent/dashboard',
            tag: "estimate-{$this->estimate->id}",
        );
    }
}
