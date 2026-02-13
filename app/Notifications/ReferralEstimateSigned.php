<?php

namespace App\Notifications;

use App\Models\Referral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralEstimateSigned extends Notification implements ShouldQueue
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
}
