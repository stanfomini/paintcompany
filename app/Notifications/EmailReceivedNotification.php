<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\EmailMessage;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailReceivedNotification extends Notification
{
    use HasSmsChannel;

    public string $pushCategory = 'email_messages';

    public function __construct(public EmailMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (method_exists($notifiable, 'wantsEmail')
            ? $notifiable->wantsEmail($this->pushCategory)
            : true) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush')
            && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $sender = $this->message->from_name ?? $this->message->from_address;

        return [
            'type' => 'email_received',
            'email_message_id' => $this->message->id,
            'thread_id' => $this->message->email_thread_id,
            'from_name' => $this->message->from_name,
            'from_address' => $this->message->from_address,
            'subject' => $this->message->subject,
            'message' => "New email from {$sender}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sender = $this->message->from_name ?? $this->message->from_address;

        return (new MailMessage)
            ->subject(__('New Email from :sender', ['sender' => $sender]))
            ->line(__('You received a new email.'))
            ->line('**'.__('From').':** '.$sender)
            ->line('**'.__('Subject').':** '.$this->message->subject)
            ->action(__('View Email'), url("/email?threadId={$this->message->email_thread_id}"));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $sender = $this->message->from_name ?? $this->message->from_address;

        return new SmsMessage(
            body: "New email from {$sender}: {$this->message->subject}",
            deepLink: url("/email?threadId={$this->message->email_thread_id}"),
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: $this->message->from_name ?? $this->message->from_address,
            body: $this->message->subject,
            url: "/email?threadId={$this->message->email_thread_id}",
            tag: "email-thread-{$this->message->email_thread_id}",
        );
    }
}
