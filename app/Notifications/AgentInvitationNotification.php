<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
}
