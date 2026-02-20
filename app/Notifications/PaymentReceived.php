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
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $invoice = $this->payment->invoice;

        return (new MailMessage)
            ->subject(__('Payment Received - Invoice :number', ['number' => $invoice->invoice_number]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name]))
            ->line(__("We've received your payment. Thank you!"))
            ->line('**'.__('Invoice').':** '.$invoice->invoice_number)
            ->line('**'.__('Payment Amount').':** $'.number_format((float) $this->payment->amount, 2))
            ->line('**'.__('Payment Method').':** '.ucfirst($this->payment->method))
            ->line('**'.__('Remaining Balance').':** $'.number_format((float) $invoice->balance_due, 2))
            ->line(__('Thank you for your business with :appName!', ['appName' => $appName]));
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
            'invoice_number' => $this->payment->invoice?->invoice_number,
            'amount' => $this->payment->amount,
        ];
    }
}
