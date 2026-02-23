<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimateProposalSent extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'estimates';

    public function __construct(public Estimate $estimate) {}

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
            ->subject(__('Your Estimate from :appName', ['appName' => $appName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name]))
            ->line(__("We've prepared an estimate for your project."))
            ->line('**'.__('Estimate').':** '.$this->estimate->estimate_number)
            ->line("**{$this->estimate->title}**")
            ->line('**'.__('Total').':** $'.number_format($this->estimate->total, 2));

        if ($this->estimate->valid_until) {
            $message->line('**'.__('Valid Until').':** '.$this->estimate->valid_until->format('M j, Y'));
        }

        if ($publicUrl) {
            $message->action(__('View Your Estimate'), $publicUrl);
        }

        $message->line(__('Click the button above to view the full estimate, select options, and accept the proposal.'))
            ->line(__("If you have any questions, please don't hesitate to reach out."));

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
            'total' => $this->estimate->total,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'New Estimate Proposal',
            body: "Estimate #{$this->estimate->estimate_number} has been sent to you",
            url: $this->estimate->getPublicUrl() ?? '/',
            tag: "estimate-{$this->estimate->id}",
        );
    }
}
