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

class VendorContractExpired extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Contract Expired: '.$this->contract->title)
            ->greeting('Hello!')
            ->line('A vendor contract has expired.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->action('View Contract', route('vendor-contracts.sign-internal', [
                'vendor' => $this->contract->vendor_id,
                'contract' => $this->contract->id,
            ]))
            ->line('You may renew this contract if needed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_expired',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: __('Contract Expired'),
            body: "{$this->contract->title} - {$this->contract->vendor?->name}",
            url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
            tag: "vendor-contract-{$this->contract->id}",
        );
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: "Contract expired: {$this->contract->title} ({$this->contract->vendor?->name}).",
            deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        );
    }
}
