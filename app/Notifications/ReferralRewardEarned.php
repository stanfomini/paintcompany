<?php

namespace App\Notifications;

use App\Models\Referral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralRewardEarned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Referral $referral) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->referral->load('customer');

        $customerName = $this->referral->customer?->full_name ?? 'Your referral';
        $amount = number_format($this->referral->reward_amount, 2);

        return (new MailMessage)
            ->subject('You earned a referral reward!')
            ->greeting('Congratulations!')
            ->line("{$customerName}'s deposit was received. You've earned \${$amount}!")
            ->line('Your reward will be sent to you shortly.')
            ->line('Thank you for referring us!');
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
}
