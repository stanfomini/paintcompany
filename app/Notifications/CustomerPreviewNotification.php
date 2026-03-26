<?php

namespace App\Notifications;

use App\Models\CompanySetting;
use App\Models\ProjectPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPreviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectPreview $preview,
        public string $customerName = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settings = CompanySetting::getInstance();
        $companyName = $settings->company_name ?? config('app.name');
        $subject = $settings->preview_link_email_subject ?: __('Your Project Preview from :company', ['company' => $companyName]);

        return (new MailMessage)
            ->subject($subject)
            ->greeting(__('Hi :name!', ['name' => $this->customerName ?: 'there']))
            ->line(__('Your project preview is ready. View your estimate details, photos, and project updates all in one place.'))
            ->action(__('View Your Project'), $this->preview->getPortalUrl())
            ->line(__('Thank you for choosing :company!', ['company' => $companyName]));
    }
}
