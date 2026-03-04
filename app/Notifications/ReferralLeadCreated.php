<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Referral;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralLeadCreated extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'general';

    public function __construct(public Referral $referral) {}

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
        $this->referral->load('customer');

        $customerName = $this->referral->customer?->full_name ?? __('Someone');

        return (new MailMessage)
            ->subject(__('New Referral: :name requested an estimate', ['name' => $customerName]))
            ->greeting(__('Great news!'))
            ->line(__(':name used your referral link and requested a painting estimate.', ['name' => $customerName]))
            ->line(__("We'll keep you updated as their project progresses."))
            ->line(__('Thank you for spreading the word!'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'referral_id' => $this->referral->id,
            'customer_name' => $this->referral->customer?->full_name,
            'type' => 'referral_lead_created',
        ];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $customerName = $this->referral->customer?->full_name ?? 'Someone';

        return new SmsMessage(
            body: "{$customerName} used your referral link and requested an estimate!",
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $customerName = $this->referral->customer?->full_name ?? 'Someone';

        return new WebPushMessage(
            title: 'New Referral Lead',
            body: "{$customerName} used your referral link",
            url: '/dashboard',
            tag: "referral-{$this->referral->id}",
        );
    }
}
