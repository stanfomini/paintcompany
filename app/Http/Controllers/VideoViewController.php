<?php

namespace App\Http\Controllers;

use App\Models\VideoView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoViewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'video_id' => ['required', 'integer', 'exists:video_recordings,id'],
            'duration' => ['required', 'integer', 'min:0'],
            'percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'source' => ['required', 'string', 'in:link,channel,email,embed'],
            'session_id' => ['required', 'string', 'max:64'],
        ]);

        // Find existing view for same video + IP within the last 30 minutes
        $existingView = VideoView::query()
            ->where('video_recording_id', $validated['video_id'])
            ->where('ip_address', $request->ip())
            ->where('created_at', '>=', now()->subMinutes(30))
            ->first();

        if ($existingView) {
            $existingView->update([
                'watch_duration_seconds' => max($validated['duration'], $existingView->watch_duration_seconds),
                'watch_percentage' => max($validated['percentage'], $existingView->watch_percentage),
                'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            ]);
        } else {
            VideoView::create([
                'video_recording_id' => $validated['video_id'],
                'viewer_id' => auth()->id(),
                'ip_address' => $request->ip(),
                'watch_duration_seconds' => max($validated['duration'], 0),
                'watch_percentage' => min($validated['percentage'], 100),
                'source' => $validated['source'],
                'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
