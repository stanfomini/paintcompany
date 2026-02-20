<?php

namespace App\Notifications;

use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimatePackageUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  array<string>  $changes
     */
    public function __construct(
        public Estimate $estimate,
        public array $changes,
        public string $type = 'updated'
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
        $appName = config('app.name');
        $publicUrl = $this->estimate->getPublicUrl();

        $message = (new MailMessage)
            ->subject(__('Your Estimate Has Been Updated - :appName', ['appName' => $appName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name]))
            ->line(__('Your estimate **:number** has been updated.', ['number' => $this->estimate->estimate_number]));

        $message->line('**'.__('What Changed').':**');

        foreach ($this->changes as $change) {
            $message->line("- {$change}");
        }

        $message->line('');
        $message->line(__('Please review the updated proposal to see the changes.'));

        if ($publicUrl) {
            $message->action(__('View Updated Estimate'), $publicUrl);
        }

        $message->line(__('If you have any questions about these changes, please contact us.'));

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
            'changes' => $this->changes,
            'type' => $this->type,
        ];
    }
}
