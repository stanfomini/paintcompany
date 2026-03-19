<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CallLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VoicemailReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'voicemails';

    public function __construct(public CallLog $callLog) {}

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
        $callerName = $this->getCallerName();
        $transcription = $this->callLog->voicemail_transcription;

        $mail = (new MailMessage)
            ->subject("Voicemail from {$callerName}")
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("You have a new voicemail from **{$callerName}**.");

        if ($this->callLog->recording_duration_seconds) {
            $duration = $this->callLog->getFormattedDuration();
            $mail->line("Duration: {$duration}");
        }

        if ($transcription) {
            $mail->line('> '.$transcription);
        }

        $mail->action('View Voicemail', url('/phone'))
            ->line('You received this email because you have a new voicemail.');

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'voicemail_received',
            'call_log_id' => $this->callLog->id,
            'caller_name' => $this->getCallerName(),
            'from_number' => $this->callLog->from_number,
            'transcription' => $this->callLog->voicemail_transcription,
            'recording_duration' => $this->callLog->recording_duration_seconds,
            'message' => "Voicemail from {$this->getCallerName()}",
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $callerName = $this->getCallerName();
        $body = $this->callLog->voicemail_transcription
            ? \Illuminate\Support\Str::limit($this->callLog->voicemail_transcription, 100)
            : 'New voicemail received';

        return new WebPushMessage(
            title: "Voicemail from {$callerName}",
            body: $body,
            url: '/phone',
            tag: "voicemail-{$this->callLog->id}",
        );
    }

    private function getCallerName(): string
    {
        if ($this->callLog->customer) {
            return trim($this->callLog->customer->first_name.' '.$this->callLog->customer->last_name);
        }

        return $this->callLog->from_number ?? 'Unknown';
    }
}
