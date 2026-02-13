<?php

namespace App\Http\Controllers;

use App\Models\VideoRecording;
use Illuminate\View\View;

class VideoRecordingPlaybackController extends Controller
{
    /**
     * Show the public playback page for a video recording.
     */
    public function show(string $shareToken): View
    {
        $recording = VideoRecording::where('share_token', $shareToken)
            ->where('status', 'ready')
            ->firstOrFail();

        return view('public.video-recording-playback', [
            'recording' => $recording,
        ]);
    }
}
