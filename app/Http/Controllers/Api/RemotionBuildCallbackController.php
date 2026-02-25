<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemotionBuildRequest;
use App\Models\VideoRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RemotionBuildCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = $request->header('X-Remotion-Build-Secret');
        if ($secret !== config('services.remotion.build_secret')) {
            return response()->json(['error' => 'Invalid secret'], 403);
        }

        // Handle error-only callbacks (JSON body, no file)
        if (! $request->hasFile('file') && $request->has('error')) {
            $request->validate([
                'request_id' => ['required', 'integer', 'exists:remotion_build_requests,id'],
                'error' => ['required', 'string'],
            ]);

            $buildRequest = RemotionBuildRequest::findOrFail($request->input('request_id'));
            $buildRequest->markFailed($request->input('error'));

            Log::warning("Remotion build failed for request #{$buildRequest->id}", [
                'error' => $request->input('error'),
            ]);

            return response()->json(['success' => true, 'status' => 'failed']);
        }

        // Handle successful build with file upload
        $request->validate([
            'request_id' => ['required', 'integer', 'exists:remotion_build_requests,id'],
            'file' => ['required', 'file', 'mimes:mp4,webm'],
        ]);

        $buildRequest = RemotionBuildRequest::findOrFail($request->input('request_id'));

        // Store the video file
        $path = $request->file('file')->store('remotion-renders', 's3');

        // Create VideoRecording
        $recording = VideoRecording::create([
            'user_id' => $buildRequest->user_id,
            'title' => 'Remotion: '.str()->limit($buildRequest->prompt, 80),
            'video_path' => $path,
            'disk' => 's3',
            'mime_type' => $request->file('file')->getMimeType(),
            'file_size_bytes' => $request->file('file')->getSize(),
            'status' => 'ready',
        ]);

        $buildRequest->markCompleted($recording->id);

        Log::info("Remotion build completed for request #{$buildRequest->id}", [
            'video_recording_id' => $recording->id,
            'file_path' => $path,
        ]);

        return response()->json([
            'success' => true,
            'video_recording_id' => $recording->id,
        ]);
    }
}
