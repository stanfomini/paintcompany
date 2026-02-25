<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Customer;
use App\Models\Deal;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessengerLead extends Notification implements ShouldQueue
{
    use Queueable, RestrictsToUserTypes;

    /** @var array<string> Only internal users should receive this */
    protected array $allowedUserTypes = ['internal'];

    public string $pushCategory = 'deals';

    public function __construct(
        public Customer $customer,
        public Deal $deal,
        public bool $isIncomplete = false
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $this->isAllowedRecipient($notifiable)) {
            return [];
        }

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
        $subject = $this->isIncomplete
            ? 'Incomplete Messenger Lead - Needs Follow-up'
            : 'New Lead from Facebook Messenger';

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('New Facebook Messenger Lead!')
            ->line("**Customer:** {$this->customer->full_name}")
            ->line("**Deal:** {$this->deal->title}");

        if ($this->isIncomplete) {
            $message->line('This lead is incomplete and needs follow-up.');
        }

        $message->action('View Deal', url("/deals/{$this->deal->id}"))
            ->line('This lead was created from a Facebook Messenger conversation.');

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_messenger_lead',
            'customer_id' => $this->customer->id,
            'deal_id' => $this->deal->id,
            'customer_name' => $this->customer->full_name,
            'deal_title' => $this->deal->title,
            'is_incomplete' => $this->isIncomplete,
            'message' => $this->isIncomplete
                ? "Incomplete Messenger lead: {$this->customer->full_name} - needs follow-up"
                : "New Messenger lead: {$this->customer->full_name}",
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $title = $this->isIncomplete
            ? 'Incomplete Messenger Lead'
            : 'New Messenger Lead';

        return new WebPushMessage(
            title: $title,
            body: "{$this->customer->full_name} - {$this->deal->title}",
            url: "/deals/{$this->deal->id}",
            tag: "messenger-lead-{$this->deal->id}",
        );
    }
}
