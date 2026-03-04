<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use App\Services\EmailCrmLinkingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AutoLinkEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

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
