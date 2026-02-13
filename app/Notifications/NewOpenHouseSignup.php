<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewOpenHouseSignup extends Notification implements ShouldQueue
{
    use Queueable;

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
        return ['mail'];
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
}
