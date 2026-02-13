<?php

namespace App\Http\Controllers;

use App\Models\VideoCall;
use Illuminate\Http\Request;

class VideoCallPlaybackController extends Controller
{
    public function show(Request $request, VideoCall $videoCall): \Illuminate\Contracts\View\View
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_unless($videoCall->recording_path, 404);

        $playbackUrl = $videoCall->getRecordingUrl();

        return view('public.video-playback', [
            'playbackUrl' => $playbackUrl,
            'jobTitle' => $videoCall->job?->title ?? 'Video Recording',
            'recordedAt' => $videoCall->ended_at?->format('M j, Y g:ia'),
            'duration' => $videoCall->duration_seconds,
        ]);
    }
}
