<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CallLog;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class VoicemailReceivedNotification extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'phone_calls';

    public function __construct(
        public CallLog $callLog
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (method_exists($notifiable, 'wantsEmail') && $notifiable->wantsEmail($this->pushCategory)) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $callerName = $this->callLog->customer?->full_name ?? $this->callLog->from_number;

        return new SmsMessage(
            body: "New voicemail from {$callerName}.",
            deepLink: url('/communications'),
        );
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
                    'text' => Str::limit($this->callLog->voicemail_transcription, 200),
                ]));
            })
            ->action(__('View in Communications Hub'), url('/communications'))
            ->line(__('Please follow up at your earliest convenience.'));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $callerName = $this->callLog->customer?->full_name ?? $this->callLog->from_number;
        $body = __('New voicemail from :caller', ['caller' => $callerName]);

        if ($this->callLog->voicemail_transcription) {
            $body .= ' - '.Str::limit($this->callLog->voicemail_transcription, 100);
        }

        return new WebPushMessage(
            title: __('New Voicemail'),
            body: $body,
            url: '/communications',
            tag: "voicemail-{$this->callLog->id}",
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'voicemail_received',
            'call_log_id' => $this->callLog->id,
            'caller_name' => $this->callLog->customer?->full_name ?? $this->callLog->from_number,
            'from_number' => $this->callLog->from_number,
            'transcription' => $this->callLog->voicemail_transcription
                ? Str::limit($this->callLog->voicemail_transcription, 100)
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
