<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\VideoCall;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VideoCallInvitation extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public VideoCall $videoCall,
        public string $initiatorName,
    ) {}

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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: "{$this->initiatorName} invited you to a video call.",
            deepLink: url("/video-call/{$this->videoCall->room_id}"),
        );
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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Video Call Started',
            body: "{$this->initiatorName} started a video call - join now",
            url: "/video-call/{$this->videoCall->room_id}",
            tag: "videocall-{$this->videoCall->id}",
        );
    }
}
