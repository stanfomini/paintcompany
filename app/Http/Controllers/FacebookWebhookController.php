<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessFacebookMessageJob;
use App\Models\MessengerSetting;
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
            MessengerSetting::getInstance()->update([
                'is_connected' => true,
                'connected_at' => now(),
            ]);

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

        // Dispatch async processing and return 200 immediately
        ProcessFacebookMessageJob::dispatch($data);

        return response('EVENT_RECEIVED', 200);
    }
}
