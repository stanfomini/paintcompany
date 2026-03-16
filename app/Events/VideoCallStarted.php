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
        $channels = [];

        // Job-based calls
        if ($this->videoCall->job_id) {
            $channels[] = new PrivateChannel('job.'.$this->videoCall->job_id);
            $channels[] = new PrivateChannel('customer.job.'.$this->videoCall->job_id);
            $channels[] = new PrivateChannel('crew.job.'.$this->videoCall->job_id);
        }

        // Deal-based calls (polymorphic)
        if ($this->videoCall->callable_type === \App\Models\Deal::class && $this->videoCall->callable_id) {
            $channels[] = new PrivateChannel('deal.'.$this->videoCall->callable_id);
        }

        return $channels;
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
