<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Estimate;
use App\Notifications\Concerns\HasSmsChannel;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProposalViewed extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable, RestrictsToUserTypes;

    /** @var array<string> Only internal users should receive this */
    protected array $allowedUserTypes = ['internal'];

    public string $pushCategory = 'estimates';

    public function __construct(public Estimate $estimate) {}

    /**
     * Get the notification's delivery channels.
     *
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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';

        return new SmsMessage(
            body: "{$customerName} viewed estimate #{$this->estimate->estimate_number}.",
            deepLink: route('estimates.show', $this->estimate),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->estimate->loadMissing('deal.customer');
        $customerName = $this->estimate->deal?->customer?->full_name ?? __('A customer');
        $adminUrl = route('estimates.show', $this->estimate);

        return (new MailMessage)
            ->subject(__(':name viewed your proposal', ['name' => $customerName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__(':customerName just viewed your estimate proposal.', ['customerName' => $customerName]))
            ->line('**'.__('Estimate').':** '.$this->estimate->estimate_number)
            ->line("**{$this->estimate->title}**")
            ->line('**'.__('Viewed at').':** '.now()->format('M j, Y g:i A'))
            ->action(__('View Estimate'), $adminUrl)
            ->line(__('Consider following up while the proposal is fresh in their mind.'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->estimate->loadMissing('deal.customer');

        return [
            'type' => 'proposal_viewed',
            'estimate_id' => $this->estimate->id,
            'estimate_number' => $this->estimate->estimate_number,
            'customer_name' => $this->estimate->deal?->customer?->full_name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $customerName = $this->estimate->deal?->customer?->full_name ?? 'A customer';

        return new WebPushMessage(
            title: 'Proposal Viewed',
            body: "{$customerName} viewed estimate #{$this->estimate->estimate_number}",
            url: "/estimates/{$this->estimate->id}",
            tag: "estimate-{$this->estimate->id}",
        );
    }
}
