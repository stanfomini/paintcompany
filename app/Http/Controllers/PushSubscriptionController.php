<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|url|max:500',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'content_encoding' => 'nullable|string|in:aesgcm,aes128gcm',
        ]);

        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $request->endpoint],
            [
                'public_key' => $request->input('keys.p256dh'),
                'auth_token' => $request->input('keys.auth'),
                'content_encoding' => $request->input('content_encoding', 'aes128gcm'),
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json(['message' => 'Subscribed'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|url',
        ]);

        $request->user()->pushSubscriptions()
            ->where('endpoint', $request->endpoint)
            ->delete();

        return response()->json([], 204);
    }
}
