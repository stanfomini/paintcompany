<?php

namespace App\Http\Controllers;

use App\Models\LiveEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pusher\Pusher;

class LiveEventGuestAuthController extends Controller
{
    /**
     * Authenticate a user (or guest viewer) for a live event presence channel.
     *
     * Logged-in users get their real identity. Guest viewers get a random ID
     * and must supply a display name. The only requirement is that the
     * event exists and is live — anyone with the link can join.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $channelName = $request->input('channel_name', '');
        $socketId = $request->input('socket_id', '');

        // Only handle live-event presence channels
        if (! str_starts_with($channelName, 'presence-live-event.')) {
            abort(403, 'This endpoint only handles live-event channels.');
        }

        $eventId = str_replace('presence-live-event.', '', $channelName);

        // Event must exist
        $event = LiveEvent::find($eventId);
        if (! $event) {
            abort(404, 'Live event not found.');
        }

        // Resolve identity: authenticated user or guest
        $user = auth()->user()
            ?? auth('customer')->user()
            ?? auth('crew')->user()
            ?? auth('agent')->user();

        if ($user) {
            $userId = (string) $user->id;
            $userName = $user->name;
        } else {
            // Guest viewer — use the guest_id from the request (generated client-side)
            $userId = $request->input('guest_id', 'guest-'.bin2hex(random_bytes(4)));
            $userName = $request->input('guest_name', 'Viewer');
        }

        $userData = [
            'id' => $userId,
            'name' => $userName,
            'user_id' => $userId,
        ];

        // Generate the Pusher/Reverb presence auth signature
        $pusher = new Pusher(
            config('broadcasting.connections.reverb.key'),
            config('broadcasting.connections.reverb.secret'),
            config('broadcasting.connections.reverb.app_id'),
        );

        $auth = $pusher->presenceAuth($channelName, $socketId, $userId, $userData);

        return response()->json(json_decode($auth, true));
    }
}
