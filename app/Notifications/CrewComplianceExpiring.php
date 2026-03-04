<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\User;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrewComplianceExpiring extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'jobs';

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

    public function toSms(object $notifiable): SmsMessage
    {
        $count = count($this->expiringItems);

        return new SmsMessage(
            body: "{$this->crew->name} has {$count} compliance document(s) expiring soon.",
            deepLink: url("/crews/{$this->crew->id}"),
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $count = count($this->expiringItems);

        return new WebPushMessage(
            title: 'Crew Compliance Alert',
            body: "{$this->crew->name} has {$count} document(s) expiring",
            url: "/crews/{$this->crew->id}",
            tag: "crew-compliance-{$this->crew->id}",
        );
    }
}
