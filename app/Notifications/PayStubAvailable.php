<?php

namespace App\Notifications;

use App\Models\PayStub;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayStubAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PayStub $payStub,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
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
}
