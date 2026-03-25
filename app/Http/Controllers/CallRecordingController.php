<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallRecordingController extends Controller
{
    /**
     * Stream a call recording to the browser.
     * Serves from local storage if available, otherwise proxies from Twilio with auth.
     */
    public function __invoke(CallLog $callLog): Response|StreamedResponse
    {
        $this->authorize('phone.view_call_history');

        if (! $callLog->hasRecording()) {
            abort(404);
        }

        // Prefer local copy
        $localPath = $callLog->metadata['local_recording_path'] ?? null;

        if ($localPath && Storage::exists($localPath)) {
            return Storage::response($localPath, null, [
                'Content-Type' => 'audio/mpeg',
            ]);
        }

        // Proxy from Twilio with Basic Auth
        $response = Http::timeout(30)
            ->withBasicAuth(
                config('services.twilio.sid'),
                config('services.twilio.token')
            )
            ->get($callLog->getRawRecordingUrl());

        if (! $response->successful()) {
            abort(502, 'Failed to fetch recording from Twilio');
        }

        return response($response->body(), 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => strlen($response->body()),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
