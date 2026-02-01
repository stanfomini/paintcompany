<?php

namespace App\Notifications;

use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimateRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Estimate $estimate,
        public ?string $reason = null
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

    public function toMail(object $notifiable): MailMessage
    {
        $this->estimate->load(['deal.customer']);

        $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';
        $adminUrl = route('estimates.show', $this->estimate);

        $message = (new MailMessage)
            ->subject("Estimate Rejected: {$this->estimate->estimate_number}")
            ->greeting('Estimate Update')
            ->line("**{$customerName}** has rejected estimate **{$this->estimate->estimate_number}**.")
            ->line("**{$this->estimate->title}**")
            ->line('**Total:** $'.number_format($this->estimate->total, 2));

        if ($this->reason) {
            $message->line('---')
                ->line('**Reason provided:**')
                ->line($this->reason);
        }

        $message->action('View Estimate', $adminUrl)
            ->line('Consider following up with the customer to understand their concerns.');

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
            'estimate_id' => $this->estimate->id,
            'estimate_number' => $this->estimate->estimate_number,
            'total' => $this->estimate->total,
            'customer_name' => $this->estimate->deal?->customer?->full_name,
            'reason' => $this->reason,
        ];
    }
}
