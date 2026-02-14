<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBuildRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AiBuildWebhookController extends Controller
{
    public function trigger(Request $request): JsonResponse
    {
        if ($request->header('X-AI-Build-Secret') !== config('ai-build.api_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! config('ai-build.enabled')) {
            return response()->json(['error' => 'AI Build is not enabled'], 503);
        }

        $validated = $request->validate([
            'estimate_id' => 'required|integer',
        ]);

        $estimateId = $validated['estimate_id'];

        // Check for active builds
        $activeRequest = AiBuildRequest::where('estimate_id', $estimateId)
            ->whereIn('status', ['pending', 'building'])
            ->first();

        if ($activeRequest) {
            return response()->json([
                'error' => 'A build is already in progress for this estimate',
                'request_id' => $activeRequest->id,
                'status' => $activeRequest->status,
            ], 409);
        }

        // Create build request
        $buildRequest = AiBuildRequest::create([
            'estimate_id' => $estimateId,
            'status' => 'pending',
        ]);

        // Dispatch artisan command in background
        Artisan::call('estimate:ai-build', [
            'estimateId' => $estimateId,
            'requestId' => $buildRequest->id,
        ]);

        Log::info("AI Build triggered for estimate #{$estimateId}", [
            'request_id' => $buildRequest->id,
        ]);

        return response()->json([
            'status' => 'building',
            'request_id' => $buildRequest->id,
        ]);
    }

    public function status(Request $request, int $requestId): JsonResponse
    {
        if ($request->header('X-AI-Build-Secret') !== config('ai-build.api_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $buildRequest = AiBuildRequest::findOrFail($requestId);

        return response()->json([
            'status' => $buildRequest->status,
            'areas_created' => $buildRequest->areas_created,
            'surfaces_created' => $buildRequest->surfaces_created,
            'summary' => $buildRequest->summary,
            'error_message' => $buildRequest->error_message,
            'started_at' => $buildRequest->started_at?->toISOString(),
            'completed_at' => $buildRequest->completed_at?->toISOString(),
        ]);
    }
}
