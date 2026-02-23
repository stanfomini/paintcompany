<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimatePackageUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'estimates';

    /**
     * Create a new notification instance.
     *
     * @param  array<string>  $changes
     */
    public function __construct(
        public Estimate $estimate,
        public array $changes,
        public string $type = 'updated'
    ) {}

    /**
     * Get the notification's delivery channels.
     *
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
        $appName = config('app.name');
        $publicUrl = $this->estimate->getPublicUrl();

        $message = (new MailMessage)
            ->subject(__('Your Estimate Has Been Updated - :appName', ['appName' => $appName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name]))
            ->line(__('Your estimate **:number** has been updated.', ['number' => $this->estimate->estimate_number]));

        $message->line('**'.__('What Changed').':**');

        foreach ($this->changes as $change) {
            $message->line("- {$change}");
        }

        $message->line('');
        $message->line(__('Please review the updated proposal to see the changes.'));

        if ($publicUrl) {
            $message->action(__('View Updated Estimate'), $publicUrl);
        }

        $message->line(__('If you have any questions about these changes, please contact us.'));

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'estimate_id' => $this->estimate->id,
            'estimate_number' => $this->estimate->estimate_number,
            'changes' => $this->changes,
            'type' => $this->type,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Estimate Updated',
            body: "Estimate #{$this->estimate->estimate_number} has been updated",
            url: $this->estimate->getPublicUrl() ?? '/',
            tag: "estimate-{$this->estimate->id}",
        );
    }
}
