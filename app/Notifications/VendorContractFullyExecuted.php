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

class VendorContractFullyExecuted extends Notification implements ShouldQueue
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
            ->subject('Contract Fully Executed: '.$this->contract->title)
            ->greeting('Hello!')
            ->line('A vendor contract has been fully signed by all parties.')
            ->line('**Contract:** '.$this->contract->title)
            ->line('**Vendor:** '.$this->contract->vendor?->name)
            ->action('View Contract', route('vendor-contracts.sign-internal', [
                'vendor' => $this->contract->vendor_id,
                'contract' => $this->contract->id,
            ]))
            ->line('A copy of the executed contract is attached.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_contract_fully_executed',
            'vendor_contract_id' => $this->contract->id,
            'title' => $this->contract->title,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: __('Contract Fully Executed'),
            body: "{$this->contract->title} signed by all parties",
            url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
            tag: "vendor-contract-{$this->contract->id}",
        );
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: "Contract fully signed: {$this->contract->title}.",
            deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        );
    }
}
