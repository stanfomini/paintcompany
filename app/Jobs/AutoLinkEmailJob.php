<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use App\Services\EmailCrmLinkingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AutoLinkEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmailMessage $message
    ) {
        $this->onConnection('redis');
    }

    public function handle(EmailCrmLinkingService $linkingService): void
    {
        $linkingService->autoLink($this->message);
    }
}
