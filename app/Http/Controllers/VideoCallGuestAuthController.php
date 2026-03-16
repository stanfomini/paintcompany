<?php

namespace App\Http\Controllers;

use App\Models\VideoCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pusher\Pusher;

class VideoCallGuestAuthController extends Controller
{
    /**
     * Authenticate a user (or guest) for a video-call presence channel.
     *
     * Logged-in users get their real identity. Guests get a random ID
     * and must supply a display name. The only requirement is that the
     * room exists — anyone with the link can join.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $channelName = $request->input('channel_name', '');
        $socketId = $request->input('socket_id', '');

        // Only handle video-call presence channels
        if (! str_starts_with($channelName, 'presence-video-call.')) {
            abort(403, 'This endpoint only handles video-call channels.');
        }

        $roomId = str_replace('presence-video-call.', '', $channelName);

        // Room must exist — invalid links get a 404
        $videoCall = VideoCall::where('room_id', $roomId)->first();
        if (! $videoCall) {
            abort(404, 'Video call room not found.');
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
            // Guest — use the guest_id from the request (generated client-side)
            $userId = $request->input('guest_id', 'guest-'.bin2hex(random_bytes(4)));
            $userName = $request->input('guest_name', 'Guest');
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
