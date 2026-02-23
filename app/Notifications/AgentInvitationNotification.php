<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'general';

    public function __construct(public Invitation $invitation) {}

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
        $invitationService = app(InvitationService::class);
        $acceptUrl = $invitationService->generateAcceptUrl($this->invitation);

        $appName = config('app.name', 'Painting Made Easy Denver');
        $appUrl = config('app.url', 'https://apexnegotiations.com');
        $inviterName = $this->invitation->inviter->name;

        return (new MailMessage)
            ->from('hello@paintingmadeeasydenver.com', 'Painting Made Easy Denver')
            ->subject('Free Virtual Painting Estimates for Every Home You List')
            ->markdown('mail.agent-invitation', [
                'acceptUrl' => $acceptUrl,
                'appName' => $appName,
                'appUrl' => $appUrl,
                'inviterName' => $inviterName,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'invited_user_type' => $this->invitation->invited_user_type,
            'inviter_id' => $this->invitation->inviter_id,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Agent Invitation',
            body: "You've been invited to join as a real estate agent partner",
            url: '/',
            tag: "invitation-{$this->invitation->id}",
        );
    }
}
