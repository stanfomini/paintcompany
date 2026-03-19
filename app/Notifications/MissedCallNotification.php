<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CallLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MissedCallNotification extends Notification implements ShouldQueue
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

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'missed_call',
            'call_log_id' => $this->callLog->id,
            'caller_name' => $this->getCallerName(),
            'from_number' => $this->callLog->from_number,
            'message' => "Missed call from {$this->getCallerName()}",
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $callerName = $this->getCallerName();

        return new WebPushMessage(
            title: "Missed call from {$callerName}",
            body: 'Tap to view and return the call',
            url: '/phone',
            tag: "missed-call-{$this->callLog->id}",
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
