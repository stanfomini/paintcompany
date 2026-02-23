<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CompanySetting;
use App\Models\EmployeeContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractSentForSigning extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'documents';

    public function __construct(
        public EmployeeContract $contract,
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
        $companyName = CompanySetting::getInstance()->company_name ?? 'the company';

        return (new MailMessage)
            ->subject("You have a contract to sign: {$this->contract->title}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("**{$companyName}** has sent you a contract that requires your signature:")
            ->line("**{$this->contract->title}**")
            ->action('Review & Sign', route('contracts.sign', $this->contract->token))
            ->line('Please review the contract and sign it at your earliest convenience.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'token' => $this->contract->token,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Contract to Sign',
            body: "You have a contract to sign: {$this->contract->title}",
            url: "/contracts/{$this->contract->token}/sign",
            tag: "contract-{$this->contract->id}",
        );
    }
}
