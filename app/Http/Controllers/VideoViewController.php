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
            'video_id' => ['required', 'exists:video_recordings,id'],
            'duration' => ['required', 'integer', 'min:0'],
            'percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'source' => ['required', 'string', 'in:link,channel,email,embed'],
            'session_id' => ['required', 'string', 'max:64'],
        ]);

        VideoView::updateOrCreate(
            [
                'video_recording_id' => $validated['video_id'],
                'ip_address' => $request->ip(),
            ],
            [
                'viewer_id' => auth()->id(),
                'watch_duration_seconds' => $validated['duration'],
                'watch_percentage' => $validated['percentage'],
                'source' => $validated['source'],
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json(['ok' => true]);
    }
}
