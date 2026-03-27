<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\VendorContract;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractExpiringSoon extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'documents';

    public function __construct(public VendorContract $contract)
    {
        $this->contract->loadMissing('vendor');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        $channels = ['database', 'broadcast'];

        if (method_exists($notifiable, 'wantsEmail')
            ? $notifiable->wantsEmail($this->pushCategory)
            : true) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush')
            && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresAt = $this->contract->expires_at?->format('M j, Y') ?? 'N/A';

        return (new MailMessage)
            ->subject('Contract Expiring Soon: '.$this->contract->title)
            ->greeting('Hello!')
            ->line('A vendor contract is expiring soon.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->line('**Expires:** '.$expiresAt)
            ->action('View Contract', route('vendor-contracts.sign-internal', [
                'vendor' => $this->contract->vendor_id,
                'contract' => $this->contract->id,
            ]))
            ->line('Consider renewing the contract before it expires.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_expiring_soon',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'expires_at' => $this->contract->expires_at?->toISOString(),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $expiresAt = $this->contract->expires_at?->format('M j, Y') ?? 'soon';

        return new WebPushMessage(
            title: __('Contract Expiring Soon'),
            body: "{$this->contract->title} expires {$expiresAt}",
            url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
            tag: "vendor-contract-{$this->contract->id}",
        );
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $expiresAt = $this->contract->expires_at?->format('M j') ?? 'soon';

        return new SmsMessage(
            body: "Contract expiring {$expiresAt}: {$this->contract->title}.",
            deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        );
    }
}
