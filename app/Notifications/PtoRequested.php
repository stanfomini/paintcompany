<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\PtoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PtoRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public PtoRequest $ptoRequest,
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

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->ptoRequest->load('employeeProfile.user');

        $employeeName = $this->ptoRequest->employeeProfile->user->name ?? 'An employee';
        $type = ucfirst(str_replace('_', ' ', $this->ptoRequest->type ?? 'time off'));
        $startDate = $this->ptoRequest->start_date->format('M j, Y');
        $endDate = $this->ptoRequest->end_date->format('M j, Y');
        $hours = $this->ptoRequest->hours;

        return (new MailMessage)
            ->subject("{$employeeName} requested PTO")
            ->greeting("Hello {$notifiable->name}!")
            ->line("{$employeeName} has submitted a PTO request:")
            ->line("**Type:** {$type}")
            ->line("**Dates:** {$startDate} - {$endDate}")
            ->line("**Hours:** {$hours}")
            ->when($this->ptoRequest->notes, function ($mail) {
                return $mail->line("**Notes:** {$this->ptoRequest->notes}");
            })
            ->action('Review PTO Requests', route('scheduling.pto'))
            ->line('Please review and approve or deny this request.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'pto_request_id' => $this->ptoRequest->id,
            'employee_name' => $this->ptoRequest->employeeProfile->user->name ?? null,
            'type' => $this->ptoRequest->type,
            'start_date' => $this->ptoRequest->start_date->toISOString(),
            'end_date' => $this->ptoRequest->end_date->toISOString(),
            'hours' => $this->ptoRequest->hours,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $employeeName = $this->ptoRequest->employeeProfile->user->name ?? 'An employee';

        return new WebPushMessage(
            title: 'PTO Requested',
            body: "{$employeeName} requested time off",
            url: '/scheduling/pto',
            tag: "pto-{$this->ptoRequest->id}",
        );
    }
}
