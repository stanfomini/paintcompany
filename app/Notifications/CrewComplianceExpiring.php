<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrewComplianceExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, array{type: string, expires: string}>  $expiringItems
     */
    public function __construct(
        public User $crew,
        public array $expiringItems
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
        $crewName = $this->crew->name;
        $companyName = $this->crew->crewProfile?->company_name;
        $label = $companyName ? "{$crewName} ({$companyName})" : $crewName;

        $mail = (new MailMessage)
            ->subject(__('Crew Compliance Alert: :name', ['name' => $crewName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('The following compliance documents for **:label** need attention:', ['label' => $label]));

        foreach ($this->expiringItems as $item) {
            $status = $item['type'] === 'expired' ? __('EXPIRED') : __('expiring soon');
            $mail->line("- **{$item['label']}**: {$status} ({$item['expires']})");
        }

        return $mail
            ->action(__('View Crew'), route('crews.show', $this->crew))
            ->line(__('Please reach out to the crew to get their documents updated.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'crew_id' => $this->crew->id,
            'crew_name' => $this->crew->name,
            'expiring_items' => $this->expiringItems,
        ];
    }
}
