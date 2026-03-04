<?php

namespace App\Channels;

class SmsMessage
{
    public function __construct(
        public string $body,
        public ?string $deepLink = null,
    ) {}

    /**
     * Build the final SMS body, appending the deep link if present.
     */
    public function toBody(): string
    {
        if ($this->deepLink) {
            return $this->body."\n".$this->deepLink;
        }

        return $this->body;
    }
}
