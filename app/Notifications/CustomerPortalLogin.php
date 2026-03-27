<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\Concerns\HasSmsChannel;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPortalLogin extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable, RestrictsToUserTypes;

    /** @var array<string> Only internal users should receive this */
    protected array $allowedUserTypes = ['internal'];

    public string $pushCategory = 'jobs';

    public function __construct(public User $customer) {}

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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $customerName = $this->customer->full_name ?? 'A customer';

        return new SmsMessage(
            body: "{$customerName} just logged into the customer portal.",
            deepLink: route('customers.show', $this->customer),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $customerRecord = Customer::where('user_id', $this->customer->id)->first();
        $customerName = $customerRecord?->full_name ?? $this->customer->name;

        return (new MailMessage)
            ->subject(__(':name logged into their portal', ['name' => $customerName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__(':customerName just logged into their customer portal.', ['customerName' => $customerName]))
            ->line('**'.__('Email').':** '.$this->customer->email)
            ->line('**'.__('Login time').':** '.now()->format('M j, Y g:i A'))
            ->action(__('View Customer'), $customerRecord ? route('customers.show', $customerRecord) : route('dashboard'))
            ->line(__('This is a good time to follow up if you have any updates for them.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $customerRecord = Customer::where('user_id', $this->customer->id)->first();

        return [
            'type' => 'customer_portal_login',
            'customer_user_id' => $this->customer->id,
            'customer_name' => $customerRecord?->full_name ?? $this->customer->name,
            'customer_id' => $customerRecord?->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $customerRecord = Customer::where('user_id', $this->customer->id)->first();
        $customerName = $customerRecord?->full_name ?? $this->customer->name;

        return new WebPushMessage(
            title: 'Customer Login',
            body: "{$customerName} logged into their portal",
            url: $customerRecord ? "/customers/{$customerRecord->id}" : '/dashboard',
            tag: "customer-login-{$this->customer->id}",
        );
    }
}
