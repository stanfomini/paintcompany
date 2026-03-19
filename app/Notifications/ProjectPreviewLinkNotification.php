<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\ProjectPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectPreviewLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectPreview $preview,
        public Deal $deal,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        // This is transactional — not subject to preference gating
        if ($notifiable->email) {
            $channels[] = 'mail';
        }

        if ($notifiable->phone) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settings = CompanySetting::getInstance();
        $companyName = $settings->company_name ?? config('app.name');
        $customerName = $notifiable->first_name ?? 'there';

        $subject = $settings->preview_link_email_subject
            ?? 'Your Project Portal — {company}';
        $subject = str_replace('{company}', $companyName, $subject);

        $bodyIntro = $settings->preview_link_email_body
            ?? "We've set up a dedicated portal for your project where you can view photos, color visualizations, and track progress.";
        $bodyIntro = str_replace(['{name}', '{company}'], [$customerName, $companyName], $bodyIntro);

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$customerName}!")
            ->line($bodyIntro)
            ->action('View Your Project Portal', $this->preview->getPortalUrl())
            ->line('This is your personal link — you can revisit it anytime.');
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $settings = CompanySetting::getInstance();
        $companyName = $settings->company_name ?? config('app.name');
        $customerName = $notifiable->first_name ?? 'there';

        $message = $settings->preview_link_sms_message
            ?? 'Hi {name}! Your project portal is ready. View photos, visualizations, and details about your project here:';
        $message = str_replace(['{name}', '{company}', '{link}'], [$customerName, $companyName, $this->preview->getPortalUrl()], $message);

        // If the template already contains the link via {link}, don't add deepLink
        $deepLink = str_contains($message, $this->preview->getPortalUrl()) ? null : $this->preview->getPortalUrl();

        return new SmsMessage(
            body: $message,
            deepLink: $deepLink,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'preview_id' => $this->preview->id,
            'deal_id' => $this->deal->id,
            'portal_url' => $this->preview->getPortalUrl(),
        ];
    }
}
