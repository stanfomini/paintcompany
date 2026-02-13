<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Invitation $invitation) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $invitationService = app(InvitationService::class);
        $acceptUrl = $invitationService->generateAcceptUrl($this->invitation);

        $typeLabels = [
            'internal' => __('team member'),
            'agent' => __('agent'),
            'customer' => __('customer'),
        ];

        $typeLabel = $typeLabels[$this->invitation->invited_user_type] ?? __('user');
        $appName = config('app.name', 'Apex Negotiations');
        $inviterName = $this->invitation->inviter->name;

        return (new MailMessage)
            ->subject(__("You've been invited to join :appName", ['appName' => $appName]))
            ->greeting(__('Hello!'))
            ->line(__(':inviterName has invited you to join :appName as a :typeLabel.', ['inviterName' => $inviterName, 'appName' => $appName, 'typeLabel' => $typeLabel]))
            ->line(__('Click the button below to create your account and get started.'))
            ->action(__('Accept Invitation'), $acceptUrl)
            ->line(__('This invitation will expire in 7 days.'))
            ->line(__('If you did not expect this invitation, you can safely ignore this email.'));
    }

    /**
     * Get the array representation of the notification.
     *
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
