<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Deal;
use App\Models\User;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentPassedEstimate extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'estimates';

    public function __construct(
        public Deal $deal,
        public User $agent,
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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $agentName = $this->agent->name ?? 'An agent';

        return new SmsMessage(
            body: "{$agentName} passed on deal: {$this->deal->title}.",
            deepLink: url("/deals/{$this->deal->id}"),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $address = $this->deal->property?->address ?? $this->deal->title;

        $message = (new MailMessage)
            ->subject(__('Your Painting Estimate from :appName', ['appName' => $appName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Your real estate agent, :agentName, has arranged a painting estimate for your property.', ['agentName' => $this->agent->name]))
            ->line('**'.__('Property').':** '.$address);

        $estimate = $this->deal->estimates()->first();

        if ($estimate?->public_token) {
            $message->action(__('View Your Estimate'), route('estimates.public', $estimate->public_token));
        } else {
            $message->action(__('View Your Dashboard'), route('customer.dashboard'));
        }

        $message->line(__('Log in to your account to view the full details and track progress.'));

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
            'deal_id' => $this->deal->id,
            'agent_name' => $this->agent->name,
            'property_address' => $this->deal->property?->address,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $address = $this->deal->property?->address ?? $this->deal->title;

        return new WebPushMessage(
            title: 'Estimate from Your Agent',
            body: "{$this->agent->name} arranged an estimate for {$address}",
            url: '/customer/dashboard',
            tag: "estimate-deal-{$this->deal->id}",
        );
    }
}
