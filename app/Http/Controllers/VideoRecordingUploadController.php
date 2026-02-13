<?php

namespace App\Http\Controllers;

use App\Jobs\Python\ProcessVideoRecordingJob;
use App\Models\VideoRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoRecordingUploadController extends Controller
{
    /**
     * Upload a new video recording.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:204800', 'mimetypes:video/webm,video/mp4,video/quicktime,video/x-matroska'],
            'recordable_type' => ['nullable', 'string'],
            'recordable_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $file = $request->file('file');
        $disk = Storage::disk('video-recordings');

        // Store the original file
        $originalPath = 'originals/'.$file->hashName();
        $disk->put($originalPath, file_get_contents($file->getRealPath()));

        // Create the recording record
        $recording = VideoRecording::create([
            'user_id' => $user->id,
            'title' => $request->input('title'),
            'status' => 'uploading',
            'original_path' => $originalPath,
            'disk' => 'video-recordings',
            'recordable_type' => $request->input('recordable_type'),
            'recordable_id' => $request->input('recordable_id'),
        ]);

        // Dispatch processing job
        ProcessVideoRecordingJob::dispatch($recording);

        return response()->json([
            'id' => $recording->id,
            'status' => $recording->status,
            'share_token' => $recording->share_token,
        ], 201);
    }

    /**
     * Start a chunked upload session.
     */
    public function startChunkedUpload(Request $request): JsonResponse
    {
        $request->validate([
            'recordable_type' => ['nullable', 'string'],
            'recordable_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'mime_type' => ['required', 'string'],
        ]);

        $user = $request->user();
        $disk = Storage::disk('video-recordings');
        $extension = str_contains($request->input('mime_type'), 'mp4') ? 'mp4' : 'webm';
        $originalPath = 'originals/'.Str::uuid().'.'.$extension;

        // Create empty file to append chunks to
        $disk->put($originalPath, '');

        $recording = VideoRecording::create([
            'user_id' => $user->id,
            'title' => $request->input('title'),
            'status' => 'uploading',
            'original_path' => $originalPath,
            'disk' => 'video-recordings',
            'mime_type' => $request->input('mime_type'),
            'recordable_type' => $request->input('recordable_type'),
            'recordable_id' => $request->input('recordable_id'),
        ]);

        return response()->json([
            'id' => $recording->id,
            'status' => $recording->status,
            'share_token' => $recording->share_token,
        ], 201);
    }

    /**
     * Upload a single chunk to an existing recording.
     */
    public function uploadChunk(Request $request, VideoRecording $recording): JsonResponse
    {
        $request->validate([
            'chunk' => ['required', 'file', 'max:5120'],
            'chunk_index' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();

        if ($recording->user_id !== $user->id) {
            abort(403, __('You can only upload to your own recordings.'));
        }

        if ($recording->status !== 'uploading') {
            abort(422, __('Recording is not accepting uploads.'));
        }

        $disk = Storage::disk($recording->disk);
        $chunkData = file_get_contents($request->file('chunk')->getRealPath());

        // Append binary chunk data to the existing file (no newline - binary safe)
        $fullPath = $disk->path($recording->original_path);
        file_put_contents($fullPath, $chunkData, FILE_APPEND);

        $totalSize = filesize($fullPath);

        return response()->json([
            'received' => true,
            'chunk_index' => $request->integer('chunk_index'),
            'total_size' => $totalSize,
        ]);
    }

    /**
     * Finalize a chunked upload and start processing.
     */
    public function finalizeUpload(Request $request, VideoRecording $recording): JsonResponse
    {
        $user = $request->user();

        if ($recording->user_id !== $user->id) {
            abort(403, __('You can only finalize your own recordings.'));
        }

        if ($recording->status !== 'uploading') {
            abort(422, __('Recording is not in uploading state.'));
        }

        $disk = Storage::disk($recording->disk);

        if (! $disk->exists($recording->original_path) || $disk->size($recording->original_path) === 0) {
            abort(422, __('No data has been uploaded for this recording.'));
        }

        $recording->update([
            'status' => 'processing',
            'file_size_bytes' => $disk->size($recording->original_path),
        ]);

        ProcessVideoRecordingJob::dispatch($recording);

        return response()->json([
            'id' => $recording->id,
            'status' => 'processing',
        ]);
    }

    /**
     * Get recording status and URLs.
     */
    public function status(VideoRecording $recording): JsonResponse
    {
        return response()->json([
            'id' => $recording->id,
            'status' => $recording->status,
            'title' => $recording->title,
            'video_url' => $recording->getVideoUrl(),
            'thumbnail_url' => $recording->getThumbnailUrl(),
            'share_url' => $recording->getShareableUrl(),
            'duration_seconds' => $recording->duration_seconds,
            'formatted_duration' => $recording->formatted_duration,
            'error_message' => $recording->error_message,
        ]);
    }

    /**
     * Update recording title.
     */
    public function updateTitle(Request $request, VideoRecording $recording): JsonResponse
    {
        $user = $request->user();

        if ($recording->user_id !== $user->id) {
            abort(403, __('You can only edit your own recordings.'));
        }

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $recording->update(['title' => $request->input('title')]);

        return response()->json(['title' => $recording->title]);
    }

    /**
     * Delete a recording.
     */
    public function destroy(Request $request, VideoRecording $recording): JsonResponse
    {
        $user = $request->user();

        if ($recording->user_id !== $user->id) {
            abort(403, __('You can only delete your own recordings.'));
        }

        $disk = Storage::disk($recording->disk);

        // Delete files from storage
        if ($recording->original_path && $disk->exists($recording->original_path)) {
            $disk->delete($recording->original_path);
        }
        if ($recording->video_path && $disk->exists($recording->video_path)) {
            $disk->delete($recording->video_path);
        }
        if ($recording->thumbnail_path && $disk->exists($recording->thumbnail_path)) {
            $disk->delete($recording->thumbnail_path);
        }

        $recording->delete();

        return response()->json(['deleted' => true]);
    }
}
