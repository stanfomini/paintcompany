<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudioMessageService
{
    /**
     * Maximum audio duration in seconds (5 minutes).
     */
    private const MAX_DURATION_SECONDS = 300;

    /**
     * Allowed audio MIME types.
     *
     * @var array<string>
     */
    private const ALLOWED_MIME_TYPES = [
        'audio/webm',
        'audio/mp3',
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/mp4',
        'audio/m4a',
        'audio/ogg',
        'audio/flac',
        'audio/aac',
    ];

    public function __construct(
        private PythonBridgeService $python
    ) {}

    /**
     * Process an uploaded audio file for a chat message.
     *
     * @return array{audio_url: string, audio_path: string, audio_duration: float|null, transcription: string|null, transcription_status: string}
     */
    public function processAudioMessage(UploadedFile $audio): array
    {
        // Store the audio file
        $filename = Str::uuid().'.'.$this->getExtension($audio);
        $path = $audio->storeAs('chat-audio', $filename, 'public');

        // Get transcription from Python service
        $transcription = $this->transcribe(Storage::disk('public')->path($path));

        return [
            'audio_url' => Storage::disk('public')->url($path),
            'audio_path' => $path,
            'audio_duration' => $transcription['duration'] ?? null,
            'transcription' => $transcription['text'] ?? null,
            'transcription_status' => ($transcription['success'] ?? false) ? 'completed' : 'failed',
        ];
    }

    /**
     * Process base64 encoded audio data.
     *
     * @return array{audio_url: string, audio_path: string, audio_duration: float|null, transcription: string|null, transcription_status: string}
     */
    public function processBase64Audio(string $base64Audio, string $mimeType = 'audio/webm'): array
    {
        // Decode base64 audio
        $audioData = base64_decode($base64Audio, true);

        if ($audioData === false || empty($audioData)) {
            throw new \InvalidArgumentException('Invalid base64 audio data');
        }

        // Determine file extension from mime type
        $extension = $this->getExtensionFromMimeType($mimeType);

        // Store the audio file
        $filename = Str::uuid().'.'.$extension;
        $path = 'chat-audio/'.$filename;

        Storage::disk('public')->put($path, $audioData);

        // Get transcription from Python service
        $transcription = $this->transcribe(Storage::disk('public')->path($path));

        return [
            'audio_url' => Storage::disk('public')->url($path),
            'audio_path' => $path,
            'audio_duration' => $transcription['duration'] ?? null,
            'transcription' => $transcription['text'] ?? null,
            'transcription_status' => ($transcription['success'] ?? false) ? 'completed' : 'failed',
        ];
    }

    /**
     * Transcribe audio using the Python service.
     *
     * @return array{success: bool, text?: string, duration?: float, error?: string}
     */
    private function transcribe(string $filePath): array
    {
        if (! $this->python->isEnabled()) {
            Log::warning('Python service is disabled, skipping audio transcription');

            return [
                'success' => false,
                'error' => 'Transcription service unavailable',
            ];
        }

        try {
            return $this->python->transcribeAudioFile($filePath);
        } catch (\Exception $e) {
            Log::error('Audio transcription failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate an audio file.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateAudio(UploadedFile $audio): array
    {
        // Check MIME type
        $mimeType = $audio->getMimeType();
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return [
                'valid' => false,
                'error' => "Unsupported audio format: {$mimeType}",
            ];
        }

        // Check file size (max 25MB)
        $maxSize = 25 * 1024 * 1024;
        if ($audio->getSize() > $maxSize) {
            return [
                'valid' => false,
                'error' => 'Audio file too large. Maximum size is 25MB.',
            ];
        }

        return ['valid' => true];
    }

    /**
     * Delete an audio file from storage.
     */
    public function deleteAudio(string $path): bool
    {
        return Storage::disk('public')->delete($path);
    }

    /**
     * Get file extension from uploaded file.
     */
    private function getExtension(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();

        if (empty($extension)) {
            $extension = $this->getExtensionFromMimeType($file->getMimeType() ?? 'audio/webm');
        }

        return $extension;
    }

    /**
     * Get file extension from MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/webm' => 'webm',
            'audio/mp3', 'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            'audio/aac' => 'aac',
            default => 'webm',
        };
    }

    /**
     * Check if the Python transcription service is available.
     */
    public function isTranscriptionAvailable(): bool
    {
        return $this->python->isEnabled() && $this->python->healthCheck();
    }
}
