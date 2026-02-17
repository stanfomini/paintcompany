<?php

namespace App\Http\Controllers;

use App\Models\VideoEmail;
use Illuminate\Http\Response;

class VideoEmailTrackingController extends Controller
{
    public function pixel(VideoEmail $videoEmail): Response
    {
        $videoEmail->markOpened();

        // Return a 1x1 transparent GIF
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(VideoEmail $videoEmail): \Illuminate\Http\RedirectResponse
    {
        $videoEmail->markClicked();

        $video = $videoEmail->video;

        return redirect($video->getShareableUrl() ?? '/');
    }
}
