<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkflowEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $emailBody,
        public ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: $this->emailSubject,
        );

        if ($this->fromName) {
            $envelope->from(config('mail.from.address'), $this->fromName);
        }

        return $envelope;
    }

    public function content(): Content
    {
        // If body contains HTML tags, send as-is (from rich editor).
        // Otherwise fall back to plain text with line breaks.
        $html = strip_tags($this->emailBody) !== $this->emailBody
            ? $this->emailBody
            : nl2br(e($this->emailBody));

        return new Content(
            htmlString: $html,
        );
    }
}
