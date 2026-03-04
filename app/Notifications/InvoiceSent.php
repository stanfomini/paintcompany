<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Invoice;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceSent extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'payments';

    public function __construct(public Invoice $invoice) {}

    /**
     * Get the notification's delivery channels.
     *
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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $paymentUrl = $this->invoice->getPublicUrl();

        $message = (new MailMessage)
            ->subject(__('Invoice :number from :appName', ['number' => $this->invoice->invoice_number, 'appName' => $appName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name]))
            ->line(__('You have a new invoice from :appName.', ['appName' => $appName]))
            ->line('**'.__('Invoice').':** '.$this->invoice->invoice_number)
            ->line('**'.__('Type').':** '.ucfirst(str_replace('_', ' ', $this->invoice->type)))
            ->line('**'.__('Total').':** $'.number_format((float) $this->invoice->total, 2))
            ->line('**'.__('Balance Due').':** $'.number_format((float) $this->invoice->balance_due, 2));

        if ($this->invoice->due_date) {
            $message->line('**'.__('Due Date').':** '.$this->invoice->due_date->format('M j, Y'));
        }

        if ($paymentUrl) {
            $message->action(__('View & Pay Invoice'), $paymentUrl);
        }

        $message->line(__('Click the button above to view your invoice and make a payment.'))
            ->line(__("If you have any questions, please don't hesitate to reach out."));

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'total' => $this->invoice->total,
        ];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $total = number_format((float) $this->invoice->total, 2);

        return new SmsMessage(
            body: "Invoice #{$this->invoice->invoice_number} for \${$total} is ready.",
            deepLink: $this->invoice->getPublicUrl(),
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $total = number_format((float) $this->invoice->total, 2);

        return new WebPushMessage(
            title: 'New Invoice',
            body: "Invoice #{$this->invoice->invoice_number} for \${$total}",
            url: $this->invoice->getPublicUrl() ?? '/',
            tag: "invoice-{$this->invoice->id}",
        );
    }
}
