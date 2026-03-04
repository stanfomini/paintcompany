<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Python\ProcessVideoRecordingJob;
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

        // Store the raw Remotion output as the original file (same as regular uploads)
        $disk = config('filesystems.default');
        $originalPath = $request->file('file')->store('video-recordings/originals', $disk);

        // Create VideoRecording in processing state — Python pipeline handles re-encode,
        // thumbnail generation, duration detection, and Apple device compatibility
        $recording = VideoRecording::create([
            'user_id' => $buildRequest->user_id,
            'title' => 'Remotion: '.str()->limit($buildRequest->prompt, 80),
            'original_path' => $originalPath,
            'disk' => $disk,
            'mime_type' => $request->file('file')->getMimeType(),
            'file_size_bytes' => $request->file('file')->getSize(),
            'status' => 'processing',
            'metadata' => [
                'source' => 'remotion',
                'build_request_id' => $buildRequest->id,
            ],
        ]);

        ProcessVideoRecordingJob::dispatch($recording);

        $buildRequest->markCompleted($recording->id);

        Log::info("Remotion build completed for request #{$buildRequest->id}", [
            'video_recording_id' => $recording->id,
            'original_path' => $originalPath,
        ]);

        return response()->json([
            'success' => true,
            'video_recording_id' => $recording->id,
        ]);
    }
}
