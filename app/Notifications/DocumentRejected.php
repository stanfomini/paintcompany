<?php

namespace App\Notifications;

use App\Models\CompanySetting;
use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmployeeDocument $document,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
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
}
