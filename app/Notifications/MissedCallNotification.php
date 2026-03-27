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

class MissedCallNotification extends Notification implements ShouldQueue
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
            body: "Missed call from {$callerName}. Please call back.",
            deepLink: url('/communications'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $callerName = $this->callLog->customer?->full_name ?? $this->callLog->from_number;

        return (new MailMessage)
            ->subject(__('Missed Call from :caller', ['caller' => $callerName]))
            ->line(__('You missed a call from :caller.', ['caller' => $callerName]))
            ->action(__('View in Communications Hub'), url('/communications'))
            ->line(__('Please call back when you can.'));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $callerName = $this->callLog->customer?->full_name ?? $this->callLog->from_number;

        return new WebPushMessage(
            title: __('Missed Call'),
            body: __('Missed call from :caller', ['caller' => $callerName]),
            url: '/communications',
            tag: "missed-call-{$this->callLog->id}",
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'missed_call',
            'call_log_id' => $this->callLog->id,
            'caller_name' => $this->callLog->customer?->full_name ?? $this->callLog->from_number,
            'from_number' => $this->callLog->from_number,
            'message' => __('Missed call from :caller', [
                'caller' => $this->callLog->customer?->full_name ?? $this->callLog->from_number,
            ]),
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
