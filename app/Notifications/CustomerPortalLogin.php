<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPortalLogin extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $customer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
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
}
