<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrewOnboardingCallRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'jobs';

    public function __construct(
        public User $crew,
        public ?Carbon $requestedAt = null,
        public ?string $notes = null
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

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('New Crew Member Wants to Schedule Onboarding Call: :name', ['name' => $this->crew->name]))
            ->greeting(__('Onboarding Call Request'))
            ->line(__(':name would like to schedule an onboarding call.', ['name' => $this->crew->name]))
            ->line(__('Phone').': '.($this->crew->crewProfile?->phone ?? __('Not provided')))
            ->line(__('Email').': '.$this->crew->email);

        if ($this->requestedAt) {
            $mail->line(__('Requested Date/Time').': '.$this->requestedAt->format('M j, Y g:i A'));
        }

        if ($this->notes) {
            $mail->line(__('Notes').': '.$this->notes);
        }

        $mail->action(__('View Crew Member'), url('/crews/'.$this->crew->id));

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'crew_id' => $this->crew->id,
            'crew_name' => $this->crew->name,
            'requested_at' => $this->requestedAt?->toISOString(),
            'message' => __(':name requested an onboarding call', ['name' => $this->crew->name]),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Onboarding Call Requested',
            body: "{$this->crew->name} wants to schedule an onboarding call",
            url: "/crews/{$this->crew->id}",
            tag: "crew-onboarding-{$this->crew->id}",
        );
    }
}
