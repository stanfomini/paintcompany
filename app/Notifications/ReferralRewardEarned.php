<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Referral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralRewardEarned extends Notification implements ShouldQueue
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
        $amount = number_format($this->referral->reward_amount, 2);

        return (new MailMessage)
            ->subject(__('You earned a referral reward!'))
            ->greeting(__('Congratulations!'))
            ->line(__(":name's deposit was received. You've earned \$:amount!", ['name' => $customerName, 'amount' => $amount]))
            ->line(__('Your reward will be sent to you shortly.'))
            ->line(__('Thank you for referring us!'));
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
            'reward_amount' => $this->referral->reward_amount,
            'type' => 'referral_reward_earned',
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $amount = number_format($this->referral->reward_amount, 2);

        return new WebPushMessage(
            title: 'Referral Reward Earned!',
            body: "You earned \${$amount} from your referral",
            url: '/dashboard',
            tag: "referral-{$this->referral->id}",
        );
    }
}
