<?php

namespace App\Notifications;

use App\Models\CallLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VoicemailReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CallLog $callLog
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if ($notifiable->wantsPush('phone_calls')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $callerName = $this->callLog->customer?->full_name ?? $this->callLog->from_number;

        return (new MailMessage)
            ->subject(__('New Voicemail from :caller', ['caller' => $callerName]))
            ->greeting(__('You have a new voicemail'))
            ->line(__(':caller left a voicemail.', ['caller' => $callerName]))
            ->when($this->callLog->voicemail_transcription, function ($message) {
                $message->line(__('Transcription: ":text"', [
                    'text' => \Illuminate\Support\Str::limit($this->callLog->voicemail_transcription, 200),
                ]));
            })
            ->action(__('View in Communications Hub'), url('/communications'))
            ->line(__('Please follow up at your earliest convenience.'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'voicemail_received',
            'call_log_id' => $this->callLog->id,
            'caller_name' => $this->callLog->customer?->full_name ?? $this->callLog->from_number,
            'from_number' => $this->callLog->from_number,
            'transcription' => $this->callLog->voicemail_transcription
                ? \Illuminate\Support\Str::limit($this->callLog->voicemail_transcription, 100)
                : null,
            'message' => __('New voicemail from :caller', [
                'caller' => $this->callLog->customer?->full_name ?? $this->callLog->from_number,
            ]),
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
