<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\PayStub;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayStubAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'payments';

    public function __construct(
        public PayStub $payStub,
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
        $this->payStub->load('payPeriod');

        $startDate = $this->payStub->payPeriod->start_date->format('M j, Y');
        $endDate = $this->payStub->payPeriod->end_date->format('M j, Y');
        $netPay = number_format((float) $this->payStub->net_pay, 2);

        return (new MailMessage)
            ->subject('Your pay stub is ready')
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your pay stub for **{$startDate} - {$endDate}** is now available.")
            ->line("**Net Pay:** \${$netPay}")
            ->action('View My Pay Stubs', route('my.pay-stubs'))
            ->line('Log in to view the full details.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'pay_stub_id' => $this->payStub->id,
            'pay_period_id' => $this->payStub->pay_period_id,
            'net_pay' => $this->payStub->net_pay,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $netPay = number_format((float) $this->payStub->net_pay, 2);

        return new WebPushMessage(
            title: 'Pay Stub Available',
            body: "Your pay stub is ready - net pay \${$netPay}",
            url: '/my/pay-stubs',
            tag: "paystub-{$this->payStub->id}",
        );
    }
}
