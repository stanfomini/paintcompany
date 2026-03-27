<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CompanySetting;
use App\Models\EmployeeDocument;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentRejected extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

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
            body: "Document '{$this->document->name}' was rejected. Please resubmit.",
            deepLink: url('/documents'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = CompanySetting::getInstance()->company_name ?? 'the company';

        return (new MailMessage)
            ->subject("Document Rejected: {$this->document->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your document **{$this->document->name}** ({$this->document->getTypeLabel()}) has been rejected by **{$companyName}**.")
            ->line("**Reason:** {$this->document->review_notes}")
            ->line('Please upload a corrected version at your earliest convenience.')
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
            'review_notes' => $this->document->review_notes,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Document Rejected',
            body: "{$this->document->name} was rejected - please upload a corrected version",
            url: '/my/documents',
            tag: "document-{$this->document->id}",
        );
    }
}
