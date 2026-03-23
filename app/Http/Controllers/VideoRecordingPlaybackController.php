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
        $recording = VideoRecording::where('share_token', $shareToken)->firstOrFail();

        $company = CompanySetting::getInstance();

        // Show processing interstitial for videos that aren't ready yet
        if (in_array($recording->status, ['uploading', 'processing'])) {
            return view('public.video-recording-processing', [
                'recording' => $recording,
                'company' => $company,
            ]);
        }

        // Show error page for failed recordings
        if ($recording->status === 'failed') {
            return view('public.video-recording-processing', [
                'recording' => $recording,
                'company' => $company,
                'failed' => true,
            ]);
        }

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
