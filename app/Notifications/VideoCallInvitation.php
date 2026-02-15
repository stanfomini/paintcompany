<?php

namespace App\Notifications;

use App\Models\VideoCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VideoCallInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public VideoCall $videoCall,
        public string $initiatorName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $job = $this->videoCall->job;

        return (new MailMessage)
            ->subject(__(':name started a video call - :title', ['name' => $this->initiatorName, 'title' => $job->title]))
            ->greeting(__('Video Call Started'))
            ->line(__(':name started a video call for job :number.', ['name' => $this->initiatorName, 'number' => $job->job_number]))
            ->action(__('Join Video Call'), route('video-call.test', $this->videoCall->room_id))
            ->line(__('This call is in progress. Click the button above to join.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'video_call_id' => $this->videoCall->id,
            'job_id' => $this->videoCall->job_id,
            'initiator_name' => $this->initiatorName,
            'room_id' => $this->videoCall->room_id,
            'message' => __(':name started a video call', ['name' => $this->initiatorName]),
        ];
    }
}
