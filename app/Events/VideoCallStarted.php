<?php

namespace App\Events;

use App\Models\VideoCall;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoCallStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public VideoCall $videoCall,
        public string $initiatorName,
    ) {}

    public function broadcastAs(): string
    {
        return 'video.call.started';
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('job.'.$this->videoCall->job_id),
            new PrivateChannel('customer.job.'.$this->videoCall->job_id),
            new PrivateChannel('crew.job.'.$this->videoCall->job_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'video_call_id' => $this->videoCall->id,
            'room_id' => $this->videoCall->room_id,
            'initiator_name' => $this->initiatorName,
            'job_id' => $this->videoCall->job_id,
        ];
    }
}
