<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VideoRecordingPlaybackController extends Controller
{
    /**
     * Show the public playback page for a video recording.
     */
    public function show(Request $request, string $shareToken): View
    {
        $recording = VideoRecording::where('share_token', $shareToken)
            ->where('status', 'ready')
            ->firstOrFail();

        $company = CompanySetting::getInstance();

        // Handle email click tracking
        if ($request->query('ref') === 'email' && $eid = $request->query('eid')) {
            $email = VideoEmail::find($eid);
            $email?->markClicked();
        }

        return view('public.video-recording-playback', [
            'recording' => $recording,
            'company' => $company,
        ]);
    }
}
