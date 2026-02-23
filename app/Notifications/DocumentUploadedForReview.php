<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentUploadedForReview extends Notification implements ShouldQueue
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
        $employeeName = $this->document->employeeProfile->user->name ?? 'An employee';

        return (new MailMessage)
            ->subject("Document Uploaded for Review: {$this->document->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("**{$employeeName}** has uploaded a document that requires your review:")
            ->line("**{$this->document->name}** ({$this->document->getTypeLabel()})")
            ->action('Review Document', route('employees.show', $this->document->employee_profile_id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'document_name' => $this->document->name,
            'employee_profile_id' => $this->document->employee_profile_id,
            'employee_name' => $this->document->employeeProfile->user->name ?? null,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $employeeName = $this->document->employeeProfile->user->name ?? 'An employee';

        return new WebPushMessage(
            title: 'Document Needs Review',
            body: "{$employeeName} uploaded {$this->document->name} for review",
            url: "/employees/{$this->document->employee_profile_id}",
            tag: "document-{$this->document->id}",
        );
    }
}
