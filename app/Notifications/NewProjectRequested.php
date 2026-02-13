<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewProjectRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Deal $deal,
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
}
