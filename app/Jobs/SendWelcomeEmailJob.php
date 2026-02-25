<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\User;
use App\Services\WelcomeEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWelcomeEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 60, 120];

    public function __construct(
        public Customer $customer,
        public User $assignee,
    ) {
        $this->onConnection('redis');
    }

    public function handle(WelcomeEmailService $service): void
    {
        $service->sendWelcomeEmail($this->customer, $this->assignee);
    }
}
