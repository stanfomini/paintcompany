<?php

namespace App\Channels;

use App\Services\TwilioService;
use Illuminate\Notifications\Notification;

class SmsChannel
{
    public function __construct(protected TwilioService $twilio) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $this->twilio->isEnabled()) {
            return;
        }

        $phone = $notifiable->routeNotificationFor('twilio');

        if (! $phone) {
            return;
        }

        /** @var SmsMessage $message */
        $message = $notification->toSms($notifiable);

        $body = $this->twilio->prependConsentIfNeeded(
            $notifiable->getKey(),
            $message->toBody()
        );

        $this->twilio->sendSms($phone, $body);
    }
}
