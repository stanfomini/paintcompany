<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobInvoiceCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Job $job,
        public int $invoiceId,
        public float $amount,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('job.'.$this->job->id),
            new PrivateChannel('customer.job.'.$this->job->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'invoice_id' => $this->invoiceId,
            'amount' => $this->amount,
        ];
    }

    public function broadcastAs(): string
    {
        return 'invoice.created';
    }
}
