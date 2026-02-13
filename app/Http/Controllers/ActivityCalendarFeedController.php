<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityCalendarFeedService;
use Illuminate\Http\Response;

class ActivityCalendarFeedController extends Controller
{
    public function __invoke(string $token, ActivityCalendarFeedService $service): Response
    {
        $user = User::where('calendar_token', $token)->firstOrFail();

        $content = $service->generateFeed($user);

        return response($content, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="activities.ics"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
