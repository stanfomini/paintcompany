<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name', 'Apex Negotiations');
        $invoice = $this->payment->invoice;

        return (new MailMessage)
            ->subject("Payment Received - Invoice {$invoice->invoice_number}")
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("We've received your payment. Thank you!")
            ->line("**Invoice:** {$invoice->invoice_number}")
            ->line('**Payment Amount:** $'.number_format((float) $this->payment->amount, 2))
            ->line('**Payment Method:** '.ucfirst($this->payment->method))
            ->line('**Remaining Balance:** $'.number_format((float) $invoice->balance_due, 2))
            ->line("Thank you for your business with {$appName}!");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'payment_id' => $this->payment->id,
            'invoice_id' => $this->payment->invoice_id,
            'amount' => $this->payment->amount,
        ];
    }
}
