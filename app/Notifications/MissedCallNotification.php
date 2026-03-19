<?php

namespace App\Notifications;

use App\Models\CallLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissedCallNotification extends Notification implements ShouldQueue
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
            ->subject(__('Missed Call from :caller', ['caller' => $callerName]))
            ->line(__('You missed a call from :caller.', ['caller' => $callerName]))
            ->action(__('View in Communications Hub'), url('/communications'))
            ->line(__('Please call back when you can.'));
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
