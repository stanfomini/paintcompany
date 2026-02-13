<?php

namespace App\Http\Controllers;

use App\Models\WebhookEndpoint;
use App\Services\WebhookLeadProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookLeadController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $endpoint = WebhookEndpoint::where('token', $token)->first();

        if (! $endpoint) {
            return response()->json(['error' => 'Invalid webhook token'], 404);
        }

        if (! $endpoint->is_active && $endpoint->fieldMappings()->count() > 0) {
            return response()->json(['error' => 'Webhook is disabled'], 403);
        }

        if (! $endpoint->validateAuth($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        // Listening mode: no field mappings yet, store as sample
        if ($endpoint->fieldMappings()->count() === 0) {
            $endpoint->update([
                'sample_payload' => $payload,
                'last_payload_at' => now(),
            ]);

            return response()->json(['status' => 'received', 'message' => 'Sample payload stored']);
        }

        $service = app(WebhookLeadProcessingService::class);
        $log = $service->process($endpoint, $payload, $request->ip());

        return response()->json([
            'status' => $log->status === 'processed' ? 'success' : $log->status,
            'lead_id' => $log->deal_id,
        ]);
    }
}
