<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAudioController extends Controller
{
    /**
     * Stream a chat audio file with proper headers for browser playback.
     *
     * Uses a signed URL parameter for auth (no login required for public chat users).
     */
    public function stream(string $filename): StreamedResponse
    {
        $path = 'chat-audio/'.$filename;

        if (! Storage::exists($path)) {
            abort(404);
        }

        $mimeType = match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'webm' => 'audio/webm',
            'm4a', 'mp4' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            default => 'audio/webm',
        };

        $size = Storage::size($path);

        return response()->stream(function () use ($path) {
            $stream = Storage::readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=86400',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
