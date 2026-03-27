<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CompanySetting;
use App\Models\User;
use App\Notifications\Concerns\HasSmsChannel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrewCompliancePackNotification extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public User $user,
    ) {}

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

    public function toSms(object $notifiable): SmsMessage
    {
        $name = $this->user->name ?? 'Team member';

        return new SmsMessage(
            body: "Compliance pack assigned to {$name}. Please review and complete required documents.",
            deepLink: url('/my/compliance'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'crew_compliance_pack',
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'message' => "Compliance documents for {$this->user->name} have been emailed",
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Compliance Pack Ready',
            body: "Compliance documents for {$this->user->name} have been emailed",
            url: '/crews',
            tag: "compliance-pack-{$this->user->id}",
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->user->load(['crewProfile', 'crewSignedContracts', 'crewW9Form']);

        $pdf = Pdf::loadView('pdf.crew-compliance-pack', [
            'user' => $this->user,
            'settings' => CompanySetting::getInstance(),
        ]);

        $filename = 'compliance-pack-'.str($this->user->name)->slug().'.pdf';

        return (new MailMessage)
            ->subject(__('Crew Compliance Documents - :name', ['name' => $this->user->name]))
            ->line(__('Please find attached the compliance documents for :name.', ['name' => $this->user->name]))
            ->line(__('This pack includes signed contracts, W-9 form details, insurance information, and workers compensation records.'))
            ->attachData($pdf->output(), $filename, ['mime' => 'application/pdf']);
    }
}
