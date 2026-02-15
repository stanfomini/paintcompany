<?php

namespace App\Notifications;

use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProposalViewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Estimate $estimate) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
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
}
