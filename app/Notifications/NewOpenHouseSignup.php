<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Customer;
use App\Models\Property;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewOpenHouseSignup extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'general';

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Customer $customer,
        public Property $listing
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
        $name = $this->customer->full_name ?? 'Someone';

        return new SmsMessage(
            body: "New open house signup from {$name}.",
            deepLink: url("/deals/{$this->listing->id}"),
        );
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $customerName = "{$this->customer->first_name} {$this->customer->last_name}";
        $phone = $this->customer->phone ?: __('Not provided');

        return (new MailMessage)
            ->subject(__('New Open House Sign-up: :address', ['address' => $this->listing->address]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('A new customer has signed up at your open house.'))
            ->line('**'.__('Customer Details').':**')
            ->line(__('Name').': '.$customerName)
            ->line(__('Email').': '.$this->customer->email)
            ->line(__('Phone').': '.$phone)
            ->line('**'.__('Listing Address').':** '.$this->listing->full_address)
            ->action(__('View Customer'), url('/agent/customers/'.$this->customer->id))
            ->line(__('Thank you for using :appName!', ['appName' => config('app.name')]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'customer_id' => $this->customer->id,
            'listing_id' => $this->listing->id,
            'customer_name' => "{$this->customer->first_name} {$this->customer->last_name}",
            'listing_address' => $this->listing->address,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = "{$this->customer->first_name} {$this->customer->last_name}";

        return new WebPushMessage(
            title: 'Open House Sign-up',
            body: "{$name} signed up at {$this->listing->address}",
            url: "/agent/customers/{$this->customer->id}",
            tag: "openhouse-{$this->customer->id}",
        );
    }
}
