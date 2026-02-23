<?php

namespace App\Channels;

class WebPushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/',
        public ?string $icon = null,
        public ?string $tag = null,
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon ?? '/icons/icon-192.png',
            'tag' => $this->tag ?? 'default',
        ];
    }
}
