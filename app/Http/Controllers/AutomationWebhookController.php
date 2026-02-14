<?php

namespace App\Http\Controllers;

use App\Events\Automation\WebhookReceived;
use App\Models\AutomationWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $webhook = AutomationWebhook::where('token', $token)->first();

        if (! $webhook) {
            return response()->json(['error' => 'Invalid webhook token'], 404);
        }

        if (! $webhook->is_active) {
            return response()->json(['error' => 'Webhook is disabled'], 403);
        }

        if (! $webhook->validateAuth($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        $webhook->update([
            'last_payload' => $payload,
            'last_triggered_at' => now(),
            'trigger_count' => $webhook->trigger_count + 1,
        ]);

        event(new WebhookReceived($webhook, $payload));

        return response()->json([
            'status' => 'received',
            'webhook_id' => $webhook->id,
        ]);
    }
}
