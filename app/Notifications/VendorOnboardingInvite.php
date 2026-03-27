<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Vendor;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorOnboardingInvite extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public Vendor $vendor,
    ) {}

    /**
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

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: "You've been invited to complete vendor onboarding.",
            deepLink: url('/vendor-onboarding'),
        );
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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Vendor Onboarding',
            body: 'Please complete your vendor information and upload documents',
            url: $this->vendor->getOnboardingUrl(),
            tag: "vendor-onboarding-{$this->vendor->id}",
        );
    }
}
