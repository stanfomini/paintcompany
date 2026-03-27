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
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorContractInternalSigningRequest extends Notification implements ShouldQueue
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
        $name = $notifiable->name ?? 'there';

        return (new MailMessage)
            ->subject('Contract Signing Required: '.$this->contract->title)
            ->greeting("Hello {$name}!")
            ->line('A vendor contract requires your signature.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->action('Review & Sign', route('vendor-contracts.sign-internal', [
                'vendor' => $this->contract->vendor_id,
                'contract' => $this->contract->id,
            ]))
            ->line('Please review and sign the contract at your earliest convenience.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_internal_signing_request',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'vendor_name' => $this->contract->vendor?->name,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: __('Contract Signing Required'),
            body: "{$this->contract->title} needs your signature",
            url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
            tag: "vendor-contract-sign-{$this->contract->id}",
        );
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: "Contract needs your signature: {$this->contract->title}.",
            deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        );
    }
}
