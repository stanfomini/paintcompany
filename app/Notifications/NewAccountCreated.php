<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewAccountCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'general';

    public function __construct(public User $newUser) {}

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
        $typeBadge = ucfirst($this->newUser->type);

        return (new MailMessage)
            ->subject(__('New :type account created: :name', ['type' => $typeBadge, 'name' => $this->newUser->name]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('A new **:type** account has been created.', ['type' => $typeBadge]))
            ->line('**'.__('Name').':** '.$this->newUser->name)
            ->line('**'.__('Email').':** '.$this->newUser->email)
            ->line('**'.__('Account Type').':** '.$typeBadge)
            ->line('**'.__('Created at').':** '.now()->format('M j, Y g:i A'))
            ->action(__('View Dashboard'), route('dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_account_created',
            'user_id' => $this->newUser->id,
            'user_name' => $this->newUser->name,
            'user_email' => $this->newUser->email,
            'account_type' => $this->newUser->type,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $typeBadge = ucfirst($this->newUser->type);

        return new WebPushMessage(
            title: 'New Account Created',
            body: "{$this->newUser->name} ({$typeBadge}) just signed up",
            url: '/dashboard',
            tag: "account-{$this->newUser->id}",
        );
    }
}
