<?php

namespace App\Mail;

use App\Models\VideoEmail;
use App\Models\VideoRecording;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideoSharedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public VideoRecording $recording,
        public VideoEmail $videoEmail,
        public string $customMessage = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->videoEmail->subject,
        );
    }

    public function content(): Content
    {
        $shareUrl = $this->recording->getShareableUrl();
        $trackingUrl = $shareUrl.'?ref=email&eid='.$this->videoEmail->id;

        return new Content(
            markdown: 'mail.video-shared',
            with: [
                'videoTitle' => $this->recording->title,
                'message' => $this->customMessage,
                'watchUrl' => $trackingUrl,
                'senderName' => $this->videoEmail->sender->name ?? 'Someone',
                'pixelUrl' => route('api.video-emails.pixel', $this->videoEmail),
            ],
        );
    }
}
