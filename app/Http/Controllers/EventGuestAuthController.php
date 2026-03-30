<?php

namespace App\Http\Controllers;

use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pusher\Pusher;

class EventGuestAuthController extends Controller
{
    /**
     * Authenticate a guest participant for event backstage private channels
     * and event studio presence channels.
     * Guests provide their token; internal users use their session auth.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $channelName = $request->input('channel_name', '');
        $socketId = $request->input('socket_id', '');

        // Determine channel type and extract event ID
        $eventId = null;
        $isPresence = false;

        if (str_starts_with($channelName, 'private-event-backstage.')) {
            $eventId = str_replace('private-event-backstage.', '', $channelName);
        } elseif (str_starts_with($channelName, 'presence-event-studio.')) {
            $eventId = str_replace('presence-event-studio.', '', $channelName);
            $isPresence = true;
        } else {
            abort(403, 'This endpoint only handles event channels.');
        }

        $event = LiveEvent::find($eventId);
        if (! $event) {
            abort(404, 'Live event not found.');
        }

        // Check for authenticated internal user first
        $user = auth()->user();
        if ($user && $user->isInternal()) {
            return $isPresence
                ? $this->generatePresenceAuth($channelName, $socketId, "host-{$user->id}", $user->name)
                : $this->generateAuth($channelName, $socketId);
        }

        // Check for guest token
        $guestToken = $request->input('guest_token');
        if ($guestToken) {
            $guest = LiveEventGuest::where('token', $guestToken)
                ->where('live_event_id', $event->id)
                ->first();

            if ($guest) {
                return $isPresence
                    ? $this->generatePresenceAuth($channelName, $socketId, $guest->getLivekitIdentity(), $guest->name)
                    : $this->generateAuth($channelName, $socketId);
            }
        }

        abort(403, 'Unauthorized.');
    }

    private function generateAuth(string $channelName, string $socketId): JsonResponse
    {
        $pusher = new Pusher(
            config('broadcasting.connections.reverb.key'),
            config('broadcasting.connections.reverb.secret'),
            config('broadcasting.connections.reverb.app_id'),
        );

        $auth = $pusher->socketAuth($channelName, $socketId);

        return response()->json(json_decode($auth, true));
    }

    private function generatePresenceAuth(string $channelName, string $socketId, string $userId, string $userName): JsonResponse
    {
        $pusher = new Pusher(
            config('broadcasting.connections.reverb.key'),
            config('broadcasting.connections.reverb.secret'),
            config('broadcasting.connections.reverb.app_id'),
        );

        $auth = $pusher->presenceAuth($channelName, $socketId, $userId, [
            'name' => $userName,
        ]);

        return response()->json(json_decode($auth, true));
    }
}
