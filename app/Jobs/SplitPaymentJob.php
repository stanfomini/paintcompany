<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentSplitService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SplitPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public Payment $payment) {}

    /**
     * Execute the job.
     */
    public function handle(PaymentSplitService $service): void
    {
        $service->splitPayment($this->payment);
    }
}
