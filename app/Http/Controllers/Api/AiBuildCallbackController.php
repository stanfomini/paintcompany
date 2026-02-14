<?php

namespace App\Http\Controllers\Api;

use App\Events\AiBuildCompleted;
use App\Http\Controllers\Controller;
use App\Models\AiBuildRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AiBuildCallbackController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->header('X-AI-Build-Secret') !== config('ai-build.api_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'estimate_id' => 'required|integer',
            'request_id' => 'required|integer',
            'status' => 'required|string|in:building,completed,nothing_to_build,unreadable_blueprints,failed,partial',
            'areas_created' => 'integer',
            'surfaces_created' => 'integer',
            'summary' => 'nullable|string',
            'error_message' => 'nullable|string',
        ]);

        // Update build request record (skip DB update for progress-only callbacks)
        $buildRequest = AiBuildRequest::find($validated['request_id']);
        if ($buildRequest && $validated['status'] !== 'building') {
            if (in_array($validated['status'], ['completed', 'partial'])) {
                $buildRequest->markCompleted(
                    $validated['areas_created'] ?? 0,
                    $validated['surfaces_created'] ?? 0,
                    $validated['summary'] ?? '',
                );
            } else {
                $buildRequest->markFailed(
                    $validated['status'],
                    $validated['error_message'] ?? 'Unknown error',
                );
            }
            $buildRequest->update(['callback_sent_at' => now()]);
        }

        // Update cache so Livewire polling picks up status changes
        $estimateId = $validated['estimate_id'];
        $cacheKey = "ai-build-estimate:{$estimateId}";

        if ($validated['status'] === 'building') {
            // Progress update — keep the cache alive
            Cache::put($cacheKey, [
                'status' => 'building',
                'message' => $validated['summary'] ?? 'AI is working...',
            ], now()->addMinutes(15));
        } else {
            // Final status — write it so the next poll picks it up, then it clears itself
            $message = match ($validated['status']) {
                'completed' => 'AI built '.($validated['areas_created'] ?? 0).' areas with '.($validated['surfaces_created'] ?? 0).' surfaces!',
                'partial' => 'AI built '.($validated['areas_created'] ?? 0).' areas but had some issues.',
                'nothing_to_build' => 'No blueprints, photos, or notes found.',
                'unreadable_blueprints' => "Couldn't read the blueprints.",
                default => $validated['error_message'] ?? 'Build finished.',
            };
            Cache::put($cacheKey, [
                'status' => $validated['status'],
                'message' => $message,
                'areas_created' => $validated['areas_created'] ?? 0,
                'surfaces_created' => $validated['surfaces_created'] ?? 0,
            ], now()->addMinutes(5));
        }

        // Broadcast to connected clients via Reverb
        AiBuildCompleted::dispatch(
            $validated['estimate_id'],
            $validated['status'],
            $validated['areas_created'] ?? 0,
            $validated['surfaces_created'] ?? 0,
            $validated['summary'] ?? null,
            $validated['error_message'] ?? null,
        );

        Log::info("AI Build callback received for estimate #{$validated['estimate_id']}", $validated);

        return response()->json(['status' => 'ok']);
    }
}
