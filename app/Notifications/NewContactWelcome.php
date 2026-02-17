<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewContactWelcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Customer $customer,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('New Contact: :name', ['name' => $this->customer->full_name]))
            ->line(__(':name just signed up but hasn\'t provided a property address yet. Start a conversation to learn more about their project.', ['name' => $this->customer->full_name]));

        if ($this->customer->phone) {
            $message->line(__('Phone: :phone', ['phone' => $this->customer->phone]));
        }

        if ($this->customer->email) {
            $message->line(__('Email: :email', ['email' => $this->customer->email]));
        }

        return $message
            ->action(__('View Customer'), route('customers.show', $this->customer))
            ->line(__('Reach out to learn about their painting project.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'customer_id' => $this->customer->id,
            'message' => __(':name signed up - no property yet. Start a conversation.', ['name' => $this->customer->full_name]),
        ];
    }
}
