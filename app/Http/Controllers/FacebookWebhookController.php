<?php

namespace App\Http\Controllers;

use App\Services\FacebookMessengerService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class FacebookWebhookController extends Controller
{
    public function __construct(protected FacebookMessengerService $messenger) {}

    /**
     * Handle Facebook webhook verification (GET).
     * Facebook sends hub.mode, hub.verify_token, hub.challenge.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', '');
        $token = $request->query('hub_verify_token', '');
        $challenge = $request->query('hub_challenge', '');

        $result = $this->messenger->verifyWebhook($mode, $token, $challenge);

        if ($result !== null) {
            return response($result, 200);
        }

        Log::warning('FacebookWebhook: Verification failed', [
            'mode' => $mode,
            'ip' => $request->ip(),
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming Facebook webhook events (POST).
     * Returns 200 immediately, then dispatches async processing.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256', '');

        if (! $this->messenger->validateSignature($payload, $signature)) {
            Log::warning('FacebookWebhook: Invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        $data = $request->json()->all();

        // Process each entry (Facebook may batch multiple events)
        $entries = $data['entry'] ?? [];

        foreach ($entries as $entry) {
            try {
                $this->messenger->processIncomingMessage($entry);
            } catch (\Throwable $e) {
                Log::error('FacebookWebhook: Error processing entry', [
                    'error' => $e->getMessage(),
                    'entry_id' => $entry['id'] ?? null,
                ]);
            }
        }

        return response('EVENT_RECEIVED', 200);
    }
}
