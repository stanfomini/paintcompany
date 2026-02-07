<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CalendarFeedService;
use Illuminate\Http\Response;

class CalendarFeedController extends Controller
{
    public function __invoke(string $token, CalendarFeedService $service): Response
    {
        $user = User::where('calendar_token', $token)->firstOrFail();

        $content = $service->generateFeed($user);

        return response($content, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="life-commitments.ics"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
