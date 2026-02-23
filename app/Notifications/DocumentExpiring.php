<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CompanySetting;
use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'documents';

    public function __construct(
        public EmployeeDocument $document,
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
        $companyName = CompanySetting::getInstance()->company_name ?? 'the company';
        $expiresIn = $this->document->expires_at->diffForHumans();

        return (new MailMessage)
            ->subject("Document Expiring Soon: {$this->document->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your document **{$this->document->name}** ({$this->document->getTypeLabel()}) is expiring **{$expiresIn}** on **{$this->document->expires_at->format('M j, Y')}**.")
            ->line('Please upload an updated version before it expires.')
            ->action('View My Documents', route('my.documents'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'document_name' => $this->document->name,
            'expires_at' => $this->document->expires_at->toDateString(),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Document Expiring',
            body: "{$this->document->name} expires on {$this->document->expires_at->format('M j, Y')}",
            url: '/my/documents',
            tag: "document-{$this->document->id}",
        );
    }
}
