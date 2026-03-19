<?php

namespace App\Jobs;

use App\Events\Automation\InboundMessengerReceived;
use App\Models\Customer;
use App\Models\MessengerConversation;
use App\Services\FacebookMessengerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessFacebookMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array  $webhookPayload  The full webhook payload from Facebook.
     */
    public function __construct(public array $webhookPayload)
    {
        $this->onConnection('redis');
    }

    public function handle(FacebookMessengerService $messenger): void
    {
        $entries = $this->webhookPayload['entry'] ?? [];

        foreach ($entries as $entry) {
            try {
                $messenger->processIncomingMessage($entry);
            } catch (\Throwable $e) {
                Log::error('ProcessFacebookMessageJob: Failed to process entry', [
                    'entry_id' => $entry['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            // Dispatch automation event for known customers
            $this->dispatchMessengerEvent($entry);
        }
    }

    private function dispatchMessengerEvent(array $entry): void
    {
        try {
            $messaging = $entry['messaging'] ?? [];

            foreach ($messaging as $event) {
                $senderPsid = $event['sender']['id'] ?? null;
                $messageText = $event['message']['text'] ?? '';

                if (! $senderPsid || empty($messageText)) {
                    continue;
                }

                $conversation = MessengerConversation::where('facebook_psid', $senderPsid)->first();

                if ($conversation && $conversation->customer_id) {
                    $customer = Customer::find($conversation->customer_id);

                    if ($customer) {
                        InboundMessengerReceived::dispatch($customer, $senderPsid, $messageText);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch InboundMessengerReceived event', ['error' => $e->getMessage()]);
        }
    }
}
