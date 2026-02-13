<?php

namespace App\Notifications;

use App\Models\EstimateAmendment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstimateAmended extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EstimateAmendment $amendment
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
        $appName = config('app.name', 'Apex Negotiations');
        $estimate = $this->amendment->estimate;
        $publicUrl = $estimate->getPublicUrl();
        $changes = $this->amendment->changes ?? [];

        $message = (new MailMessage)
            ->subject(__('Your Estimate Has Been Updated - :appName', ['appName' => $appName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name]))
            ->line(__('Your estimate **:number** has been updated (Amendment #:amendment).', ['number' => $estimate->estimate_number, 'amendment' => $this->amendment->amendment_number]));

        $message->line('**'.__('What Changed').':**');

        $displayChanges = array_slice($changes, 0, 5);
        foreach ($displayChanges as $change) {
            $line = $change['details'] ?? $change['label'] ?? '';
            if (isset($change['field'], $change['old_value'], $change['new_value'])) {
                $line = "{$change['label']}: {$change['field']} changed from {$change['old_value']} to {$change['new_value']}";
            }
            $message->line("- {$line}");
        }

        if (count($changes) > 5) {
            $remaining = count($changes) - 5;
            $message->line(__('...and :count more change(s).', ['count' => $remaining]));
        }

        // Show total change
        $totalChange = $this->amendment->getTotalChangeFormatted();
        $message->line('');
        $message->line('**'.__('Price Change').':** '.$totalChange);
        $message->line('**'.__('New Total').':** $'.number_format((float) $this->amendment->new_total, 2));

        $message->line('');
        $message->line('**'.__('You must review and acknowledge these changes before signing.').'**');

        if ($publicUrl) {
            $message->action(__('Review Changes'), $publicUrl);
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
            'estimate_id' => $this->amendment->estimate_id,
            'amendment_id' => $this->amendment->id,
            'amendment_number' => $this->amendment->amendment_number,
            'change_count' => count($this->amendment->changes ?? []),
        ];
    }
}
