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

        $customerName = $this->referral->customer?->full_name ?? 'Your referral';

        return (new MailMessage)
            ->subject("Referral Update: {$customerName}'s estimate was accepted")
            ->greeting('Great news!')
            ->line("The estimate for {$customerName} has been signed.")
            ->line('Once the deposit is received, you\'ll earn your referral reward.')
            ->line('Thank you for your referral!');
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
