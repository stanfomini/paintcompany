<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Referral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralEstimateSigned extends Notification implements ShouldQueue
{
    use Queueable;

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

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->referral->load('customer');

        $customerName = $this->referral->customer?->full_name ?? __('Your referral');

        return (new MailMessage)
            ->subject(__("Referral Update: :name's estimate was accepted", ['name' => $customerName]))
            ->greeting(__('Great news!'))
            ->line(__('The estimate for :name has been signed.', ['name' => $customerName]))
            ->line(__("Once the deposit is received, you'll earn your referral reward."))
            ->line(__('Thank you for your referral!'));
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
            'type' => 'referral_estimate_signed',
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $customerName = $this->referral->customer?->full_name ?? 'Your referral';

        return new WebPushMessage(
            title: 'Referral Estimate Signed',
            body: "{$customerName}'s estimate was accepted!",
            url: '/dashboard',
            tag: "referral-{$this->referral->id}",
        );
    }
}
