<?php

namespace App\Notifications;

use App\Models\Referral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralLeadCreated extends Notification implements ShouldQueue
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
}
