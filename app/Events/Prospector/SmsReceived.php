<?php

namespace App\Events\Prospector;

use App\Models\Prospector\SmsMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SmsReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SmsMessage $smsMessage,
        public int $companyId,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('prospector.company.'.$this->companyId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->smsMessage->id,
            'contact_id' => $this->smsMessage->contact_id,
            'from_number' => $this->smsMessage->from_number,
            'to_number' => $this->smsMessage->to_number,
            'body' => $this->smsMessage->body,
            'media_urls' => $this->smsMessage->media_urls,
            'created_at' => $this->smsMessage->created_at->toISOString(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sms.received';
    }
}
