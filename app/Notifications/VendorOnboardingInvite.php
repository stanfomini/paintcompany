<?php

namespace App\Notifications;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorOnboardingInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Vendor $vendor,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $url = $this->vendor->getOnboardingUrl();

        return (new MailMessage)
            ->subject(__('Vendor Information Request from :appName', ['appName' => $appName]))
            ->greeting(__('Hello :name!', ['name' => $this->vendor->contact_name ?: $this->vendor->name]))
            ->line(__('We need to collect some information and documents from you to complete our vendor onboarding process.'))
            ->line(__('Please use the link below to fill out your business information and upload any required documents (W-9, Certificate of Insurance, etc.).'))
            ->action(__('Complete Vendor Onboarding'), $url)
            ->line(__('This link will expire in 30 days.'))
            ->line(__('If you have any questions, please reply to this email.'))
            ->salutation(__('Thank you, :appName', ['appName' => $appName]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
        ];
    }
}
