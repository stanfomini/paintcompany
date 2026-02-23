<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\PtoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PtoStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public PtoRequest $ptoRequest,
        public string $status,
        public ?string $reason = null,
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
        $type = ucfirst(str_replace('_', ' ', $this->ptoRequest->type ?? 'time off'));
        $startDate = $this->ptoRequest->start_date->format('M j, Y');
        $endDate = $this->ptoRequest->end_date->format('M j, Y');
        $statusLabel = $this->status === 'approved' ? 'approved' : 'denied';

        $message = (new MailMessage)
            ->subject("Your PTO request has been {$statusLabel}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your **{$type}** request for **{$startDate} - {$endDate}** has been **{$statusLabel}**.");

        if ($this->status === 'denied' && $this->reason) {
            $message->line("**Reason:** {$this->reason}");
        }

        $message->action('View My PTO', route('my.pto'));

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'pto_request_id' => $this->ptoRequest->id,
            'status' => $this->status,
            'type' => $this->ptoRequest->type,
            'start_date' => $this->ptoRequest->start_date->toISOString(),
            'end_date' => $this->ptoRequest->end_date->toISOString(),
            'reason' => $this->reason,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $statusLabel = $this->status === 'approved' ? 'approved' : 'denied';

        return new WebPushMessage(
            title: 'PTO Request '.ucfirst($statusLabel),
            body: "Your time off request has been {$statusLabel}",
            url: '/my/pto',
            tag: "pto-{$this->ptoRequest->id}",
        );
    }
}
