<?php

namespace App\Http\Controllers;

use App\Models\VideoCall;
use App\Services\PythonBridgeService;
use App\Services\VideoRecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoCallRecordingController extends Controller
{
    public function __construct(
        protected PythonBridgeService $python,
        protected VideoRecordingService $recordingService,
    ) {}

    /**
     * Start recording a video call.
     */
    public function start(VideoCall $videoCall): JsonResponse
    {
        if ($videoCall->recording_path) {
            return response()->json([
                'error' => 'This call already has a recording.',
            ], 422);
        }

        $recordingId = "vc-{$videoCall->id}";

        $response = $this->python->postRaw('/media/record/start', [
            'recording_id' => $recordingId,
        ]);

        if (! $response->successful()) {
            return response()->json([
                'error' => 'Failed to start recording.',
                'detail' => $response->json('detail'),
            ], 500);
        }

        return response()->json([
            'status' => 'recording',
            'recording_id' => $recordingId,
        ]);
    }

    /**
     * Upload a media chunk from the browser's MediaRecorder.
     */
    public function chunk(VideoCall $videoCall, Request $request): JsonResponse
    {
        $request->validate([
            'chunk' => 'required|file',
            'recording_id' => 'required|string',
        ]);

        $chunk = $request->file('chunk');
        $recordingId = $request->input('recording_id');
        $filename = $chunk->getClientOriginalName() ?: 'chunk.webm';

        $multipart = [
            ['name' => 'recording_id', 'contents' => $recordingId],
            ['name' => 'chunk', 'contents' => fopen($chunk->getRealPath(), 'r'), 'filename' => $filename],
        ];

        if ($request->has('mime_type')) {
            $multipart[] = ['name' => 'mime_type', 'contents' => $request->input('mime_type')];
        }

        $response = $this->python->postMultipart('/media/record/chunk', $multipart);

        if (! $response->successful()) {
            return response()->json([
                'error' => 'Failed to upload chunk.',
                'detail' => $response->json('detail'),
            ], 500);
        }

        return response()->json($response->json());
    }

    /**
     * Stop recording and store the HLS files.
     */
    public function stop(VideoCall $videoCall, Request $request): JsonResponse
    {
        $request->validate([
            'recording_id' => 'required|string',
        ]);

        $recordingId = $request->input('recording_id');

        // Tell Python to stop FFmpeg and finalize the HLS manifest
        $response = $this->python->postRaw('/media/record/stop', [
            'recording_id' => $recordingId,
        ]);

        if (! $response->successful()) {
            return response()->json([
                'error' => 'Failed to stop recording.',
                'detail' => $response->json('detail'),
            ], 500);
        }

        $data = $response->json();
        $outputDir = $data['output_dir'] ?? null;

        if (! $outputDir) {
            return response()->json([
                'error' => 'No output directory returned from recording service.',
            ], 500);
        }

        // Store the HLS files from the Python temp dir to our storage disk
        $this->recordingService->storeFromPythonOutput($videoCall, $outputDir);

        // Clean up Python temp files
        $this->python->postRaw("/media/record/{$recordingId}/cleanup");

        return response()->json([
            'status' => 'complete',
            'recording_path' => $videoCall->fresh()->recording_path,
        ]);
    }

    /**
     * Get a signed playback URL for a recording.
     */
    public function playbackUrl(VideoCall $videoCall): JsonResponse
    {
        $url = $this->recordingService->getPlaybackUrl($videoCall);

        if (! $url) {
            return response()->json([
                'error' => 'No recording available for this call.',
            ], 404);
        }

        return response()->json([
            'url' => $url,
            'expires_in' => 4 * 60 * 60, // 4 hours
        ]);
    }
}
