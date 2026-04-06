<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class VideoUrlExtractorService
{
    /**
     * Path to the yt-dlp binary.
     */
    private string $binaryPath;

    public function __construct()
    {
        $this->binaryPath = config('services.ytdlp.path', '/home/forge/bin/yt-dlp');
    }

    /**
     * Extract a direct media URL from a video page URL (YouTube, Vimeo, Twitch, etc.).
     * Returns null if extraction fails.
     *
     * @return array{url: string, title: string, duration: int|null, thumbnail: string|null}|null
     */
    public function extract(string $pageUrl): ?array
    {
        $cacheKey = 'video-url-extract:'.md5($pageUrl);

        return Cache::remember($cacheKey, now()->addMinutes(20), function () use ($pageUrl) {
            return $this->runExtraction($pageUrl);
        });
    }

    /**
     * @return array{url: string, title: string, duration: int|null, thumbnail: string|null}|null
     */
    private function runExtraction(string $pageUrl): ?array
    {
        if (! is_executable($this->binaryPath)) {
            Log::error('VideoUrlExtractor: yt-dlp binary not found or not executable', [
                'path' => $this->binaryPath,
            ]);

            return null;
        }

        $process = new Process([
            $this->binaryPath,
            '--no-warnings',
            '--no-playlist',
            '--no-check-certificate',
            '--format', 'best[ext=mp4][height<=720]/best[ext=mp4]/best',
            '--print', '%(url)s',
            '--print', '%(title)s',
            '--print', '%(duration)s',
            '--print', '%(thumbnail)s',
            $pageUrl,
        ]);

        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('VideoUrlExtractor: process failed', [
                'url' => $pageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::warning('VideoUrlExtractor: yt-dlp returned non-zero', [
                'url' => $pageUrl,
                'stderr' => $process->getErrorOutput(),
            ]);

            return null;
        }

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput())), fn ($l) => $l !== ''));

        if (count($lines) < 2 || ! filter_var($lines[0], FILTER_VALIDATE_URL)) {
            return null;
        }

        return [
            'url' => $lines[0],
            'title' => $lines[1] ?? 'Video',
            'duration' => isset($lines[2]) && is_numeric($lines[2]) ? (int) $lines[2] : null,
            'thumbnail' => $lines[3] ?? null,
        ];
    }
}
