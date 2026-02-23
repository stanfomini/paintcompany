<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewProjectRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public Deal $deal,
        public Customer $customer,
    ) {}

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
        return (new MailMessage)
            ->subject(__('New Project Request from :name', ['name' => $this->customer->full_name]))
            ->line(__(':name has requested a new project.', ['name' => $this->customer->full_name]))
            ->line(__('Description').': '.$this->deal->description)
            ->action(__('View Deal'), route('deals.show', $this->deal))
            ->line(__('Please follow up with the customer.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'deal_id' => $this->deal->id,
            'customer_id' => $this->customer->id,
            'message' => __(':name requested a new project.', ['name' => $this->customer->full_name]),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'New Project Request',
            body: "{$this->customer->full_name} requested a new project",
            url: "/deals/{$this->deal->id}",
            tag: "project-{$this->deal->id}",
        );
    }
}
