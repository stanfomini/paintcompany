<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorOnboardingCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public Vendor $vendor,
        public int $documentsUploaded = 0,
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

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Vendor Onboarding Completed: :name', ['name' => $this->vendor->name]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__(':vendor has completed their vendor onboarding form.', ['vendor' => $this->vendor->name]))
            ->line(__(':count document(s) were uploaded and are pending review.', ['count' => $this->documentsUploaded]))
            ->action(__('Review Vendor'), route('vendors.edit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
            'documents_uploaded' => $this->documentsUploaded,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Vendor Onboarding Complete',
            body: "{$this->vendor->name} completed their vendor onboarding",
            url: '/vendors',
            tag: "vendor-{$this->vendor->id}",
        );
    }
}
