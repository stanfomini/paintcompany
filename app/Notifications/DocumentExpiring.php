<?php

namespace App\Notifications;

use App\Models\CompanySetting;
use App\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentExpiring extends Notification implements ShouldQueue
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
}
