<?php

namespace App\Notifications;

use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentUploadedForReview extends Notification implements ShouldQueue
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
}
