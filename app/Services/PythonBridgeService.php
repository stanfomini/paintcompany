<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PythonBridgeService
{
    protected string $baseUrl;

    protected int $timeout;

    protected int $retryAttempts;

    protected int $retryDelay;

    public function __construct()
    {
        $this->baseUrl = config('python.service_url', 'http://127.0.0.1:8000');
        $this->timeout = config('python.timeout', 60);
        $this->retryAttempts = config('python.retry_attempts', 3);
        $this->retryDelay = config('python.retry_delay', 1000);
    }

    /**
     * Check if the Python service is enabled.
     */
    public function isEnabled(): bool
    {
        return config('python.enabled', false);
    }

    /**
     * Check if the Python service is healthy and reachable.
     */
    public function healthCheck(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $response = $this->client()->get('/health');

            return $response->successful() && $response->json('status') === 'healthy';
        } catch (\Exception $e) {
            Log::warning('Python service health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get detailed health information from the Python service.
     */
    public function detailedHealthCheck(): array
    {
        if (! $this->isEnabled()) {
            return [
                'status' => 'disabled',
                'message' => 'Python service is disabled in configuration',
            ];
        }

        try {
            $response = $this->client()->get('/health/detailed');

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'status' => 'error',
                'message' => 'Failed to get health status',
                'http_status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unreachable',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process an image through the Python ML service.
     *
     * @param  array<string>  $operations  Available: enhance, segment, inpaint, detect_substrate, recolor, remove_background
     */
    public function processImage(string $imageUrl, array $operations, array $options = []): array
    {
        return $this->post('/images/process', [
            'image_url' => $imageUrl,
            'operations' => $operations,
            'options' => $options,
        ]);
    }

    /**
     * Segment objects in an image using SAM.
     */
    public function segmentImage(string $imageUrl, ?string $prompt = null): array
    {
        return $this->post('/images/segment', [
            'image_url' => $imageUrl,
            'prompt' => $prompt,
        ]);
    }

    /**
     * Remove objects from an image using inpainting.
     */
    public function inpaintImage(string $imageUrl, string $maskUrl): array
    {
        return $this->post('/images/inpaint', [
            'image_url' => $imageUrl,
            'mask_url' => $maskUrl,
        ]);
    }

    /**
     * Detect the substrate/surface type in an image.
     *
     * @param  bool  $detectRegions  Whether to detect substrates in different regions
     * @param  int  $gridSize  Grid size for region detection (e.g., 4 = 4x4 grid)
     */
    public function detectSubstrate(
        string $imageUrl,
        bool $detectRegions = false,
        int $gridSize = 4
    ): array {
        return $this->post('/images/detect-substrate', [
            'image_url' => $imageUrl,
            'detect_regions' => $detectRegions,
            'grid_size' => $gridSize,
        ]);
    }

    /**
     * Auto-enhance an image (contrast, denoising, etc.).
     */
    public function autoEnhanceImage(string $imageUrl): array
    {
        return $this->post('/images/process', [
            'image_url' => $imageUrl,
            'operations' => ['auto_enhance'],
        ]);
    }

    /**
     * Detect if an image is blurry.
     */
    public function detectBlur(string $imageUrl): array
    {
        return $this->post('/images/process', [
            'image_url' => $imageUrl,
            'operations' => ['detect_blur'],
        ]);
    }

    /**
     * Remove background from an image.
     */
    public function removeBackground(string $imageUrl): array
    {
        return $this->post('/images/process', [
            'image_url' => $imageUrl,
            'operations' => ['remove_background'],
        ]);
    }

    /**
     * Get available paint colors for recoloring.
     */
    public function getAvailableColors(): array
    {
        return $this->get('/images/colors');
    }

    /**
     * Decode a base64 image response and save to storage.
     *
     * @return string|null Path to saved file or null on failure
     */
    public function saveBase64Image(string $base64, string $path): ?string
    {
        try {
            $imageData = base64_decode($base64);
            if ($imageData === false) {
                return null;
            }

            \Illuminate\Support\Facades\Storage::put($path, $imageData);

            return $path;
        } catch (\Exception $e) {
            Log::error('Failed to save base64 image', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Recolor a surface in an image.
     */
    public function recolorSurface(
        string $imageUrl,
        string $targetColor,
        ?string $maskUrl = null,
        bool $preserveTexture = true
    ): array {
        return $this->post('/images/recolor', [
            'image_url' => $imageUrl,
            'mask_url' => $maskUrl,
            'target_color' => $targetColor,
            'preserve_texture' => $preserveTexture,
        ]);
    }

    /**
     * Scrape a real estate listing.
     *
     * @param  string|null  $source  zillow, realtor, mls, generic
     */
    public function scrapeListing(string $url, ?string $source = null): array
    {
        return $this->post('/scrape/listing', [
            'url' => $url,
            'source' => $source,
        ], config('python.scraping.timeout', 120));
    }

    /**
     * Scrape real estate search results.
     */
    public function scrapeSearch(
        string $source,
        string $location,
        array $filters = [],
        int $maxResults = 50
    ): array {
        return $this->post('/scrape/search', [
            'source' => $source,
            'location' => $location,
            'filters' => $filters,
            'max_results' => $maxResults,
        ], config('python.scraping.timeout', 120));
    }

    /**
     * Transcribe audio using Whisper.
     *
     * @param  string  $model  tiny, base, small, medium, large
     */
    public function transcribeAudio(
        string $audioUrl,
        string $model = 'base',
        ?string $language = null
    ): array {
        return $this->post('/media/transcribe', [
            'audio_url' => $audioUrl,
            'model' => $model,
            'language' => $language,
        ]);
    }

    /**
     * Transcribe an audio file by uploading it directly.
     *
     * @param  string  $filePath  Path to the audio file
     * @param  string  $model  Whisper model: tiny, base, small, medium, large
     */
    public function transcribeAudioFile(
        string $filePath,
        string $model = 'base',
        ?string $language = null
    ): array {
        $this->ensureEnabled();

        if (! file_exists($filePath)) {
            return [
                'success' => false,
                'error' => 'Audio file not found',
            ];
        }

        try {
            $request = Http::baseUrl($this->baseUrl)
                ->timeout(120)
                ->retry($this->retryAttempts, $this->retryDelay);

            $apiKey = config('python.api_key');
            if ($apiKey) {
                $request->withHeaders(['X-API-Key' => $apiKey]);
            }

            $response = $request
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post('/media/transcribe/upload', [
                    'model' => $model,
                    'language' => $language,
                ]);

            return $this->handleResponse($response);
        } catch (RequestException $e) {
            return $this->handleException($e, 'POST', '/media/transcribe/upload');
        } catch (\Exception $e) {
            Log::error('Audio transcription failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to transcribe audio',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a video file (transcode to MP4, extract thumbnail, probe metadata).
     */
    public function processVideo(string $filePath, string $recordingId): \Illuminate\Http\Client\Response
    {
        $this->ensureEnabled();

        return $this->postMultipart('/media/video/process', [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ],
            [
                'name' => 'recording_id',
                'contents' => $recordingId,
            ],
        ], 300);
    }

    /**
     * Clean up temporary video processing files on the Python service.
     */
    public function cleanupVideoProcessing(string $recordingId): void
    {
        try {
            $this->postRaw("/media/video/process/{$recordingId}/cleanup", [], 10);
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup video processing files', [
                'recording_id' => $recordingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start an RTMP streaming session.
     *
     * @param  array<int, array{rtmp_url: string, stream_key: string}>  $destinations
     */
    public function startStream(string $streamId, array $destinations, string $codecHint = 'h264'): array
    {
        return $this->post('/streaming/start', [
            'stream_id' => $streamId,
            'destinations' => $destinations,
            'codec_hint' => $codecHint,
        ]);
    }

    /**
     * Stop an RTMP streaming session.
     */
    public function stopStream(string $streamId): array
    {
        return $this->post('/streaming/stop', [
            'stream_id' => $streamId,
        ]);
    }

    /**
     * Get RTMP stream status.
     */
    public function getStreamStatus(string $streamId): array
    {
        return $this->get("/streaming/{$streamId}/status");
    }

    /**
     * Get the WebSocket URL for streaming media chunks.
     */
    public function getStreamWebSocketUrl(string $streamId): string
    {
        $baseUrl = rtrim($this->baseUrl, '/');
        $wsUrl = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $baseUrl);
        $token = config('python.api_key', '');

        return "{$wsUrl}/streaming/ws/{$streamId}?token={$token}";
    }

    /**
     * Make a GET request to the Python service.
     */
    protected function get(string $endpoint): array
    {
        $this->ensureEnabled();

        try {
            $response = $this->client()->get($endpoint);

            return $this->handleResponse($response);
        } catch (RequestException $e) {
            return $this->handleException($e, 'GET', $endpoint);
        }
    }

    /**
     * Make a POST request to the Python service.
     */
    protected function post(string $endpoint, array $data, ?int $timeout = null): array
    {
        $this->ensureEnabled();

        try {
            $client = $this->client($timeout);
            $response = $client->post($endpoint, $data);

            return $this->handleResponse($response);
        } catch (RequestException $e) {
            return $this->handleException($e, 'POST', $endpoint, $data);
        }
    }

    /**
     * Make a POST request and return the raw HTTP response (for controller use).
     */
    public function postRaw(string $endpoint, array $data = [], ?int $timeout = null): \Illuminate\Http\Client\Response
    {
        $this->ensureEnabled();

        return $this->client($timeout)->post($endpoint, $data);
    }

    /**
     * Make a multipart POST request and return the raw HTTP response.
     *
     * @param  array<int, array{name: string, contents: mixed, filename?: string}>  $multipart
     */
    public function postMultipart(string $endpoint, array $multipart, ?int $timeout = null): \Illuminate\Http\Client\Response
    {
        $this->ensureEnabled();

        $request = Http::baseUrl($this->baseUrl)
            ->timeout($timeout ?? $this->timeout)
            ->acceptJson()
            ->asMultipart();

        $apiKey = config('python.api_key');
        if ($apiKey) {
            $request->withHeaders(['X-API-Key' => $apiKey]);
        }

        // Attach each part
        foreach ($multipart as $part) {
            $request->attach(
                $part['name'],
                $part['contents'],
                $part['filename'] ?? null,
            );
        }

        return $request->post($endpoint);
    }

    /**
     * Create an HTTP client configured for the Python service.
     */
    protected function client(?int $timeout = null): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($timeout ?? $this->timeout)
            ->retry($this->retryAttempts, $this->retryDelay)
            ->acceptJson()
            ->asJson();

        $apiKey = config('python.api_key');
        if ($apiKey) {
            $request->withHeaders(['X-API-Key' => $apiKey]);
        }

        return $request;
    }

    /**
     * Handle the response from the Python service.
     */
    protected function handleResponse($response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        Log::error('Python service request failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return [
            'success' => false,
            'error' => 'Python service returned an error',
            'http_status' => $response->status(),
            'details' => $response->json() ?? $response->body(),
        ];
    }

    /**
     * Handle exceptions from HTTP requests.
     */
    protected function handleException(
        RequestException $e,
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        Log::error('Python service request exception', [
            'method' => $method,
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'error' => 'Failed to communicate with Python service',
            'message' => $e->getMessage(),
        ];
    }

    /**
     * Ensure the Python service is enabled.
     *
     * @throws \RuntimeException
     */
    protected function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new \RuntimeException(
                'Python service is disabled. Set PYTHON_ENABLED=true in your .env file.'
            );
        }
    }
}
