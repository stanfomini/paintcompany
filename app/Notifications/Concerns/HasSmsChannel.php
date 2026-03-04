<?php

namespace App\Notifications\Concerns;

use App\Channels\SmsChannel;

trait HasSmsChannel
{
    /**
     * Check if SMS should be included in the via() channels.
     */
    protected function shouldSendSms(object $notifiable): bool
    {
        return method_exists($notifiable, 'wantsSms')
            && property_exists($this, 'pushCategory')
            && $notifiable->wantsSms($this->pushCategory);
    }

    /**
     * Get the SMS channel class if the notifiable wants SMS.
     */
    protected function smsChannel(object $notifiable): ?string
    {
        return $this->shouldSendSms($notifiable) ? SmsChannel::class : null;
    }
}
