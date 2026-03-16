<?php

namespace App\Http\Controllers;

use App\Events\CallConnected;
use App\Events\CallEnded;
use App\Models\CallLog;
use App\Services\TwilioService;
use App\Services\TwilioVoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CallController extends Controller
{
    public function __construct(
        protected TwilioVoiceService $voice,
        protected TwilioService $twilio
    ) {}

    /**
     * Initiate an outbound call.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'to' => 'required|string',
        ]);

        if (empty($request->user()->forwarding_number)) {
            return response()->json([
                'error' => 'You must configure a forwarding number before making calls.',
            ], 422);
        }

        if (! $this->voice->isEnabled()) {
            return response()->json([
                'error' => 'Voice calling is not enabled.',
            ], 422);
        }

        try {
            $callLog = $this->voice->initiateOutboundCall(
                $request->user(),
                $request->input('to')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'call_log_id' => $callLog->id,
            'ulid' => $callLog->ulid,
            'status' => $callLog->status,
        ]);
    }

    /**
     * Transfer an active call to another user by extension.
     */
    public function transfer(Request $request, CallLog $callLog): JsonResponse
    {
        $request->validate([
            'extension' => 'required|string',
        ]);

        if (! $request->user()->can('phone.transfer_calls')) {
            return response()->json(['error' => 'You do not have permission to transfer calls.'], 403);
        }

        if ($callLog->status !== 'in-progress' || empty($callLog->twilio_call_sid)) {
            return response()->json(['error' => 'Call is not currently active.'], 422);
        }

        try {
            $originalUserId = $callLog->answered_by_user_id ?? $callLog->caller_user_id ?? 0;
            $updatedCallLog = $this->voice->transferCall(
                $callLog->twilio_call_sid,
                $request->input('extension')
            );

            // Notify original user that the call has ended for them
            try {
                event(new CallEnded($updatedCallLog, $originalUserId));
            } catch (\Throwable $e) {
                Log::warning('CallController: broadcast CallEnded on transfer failed', [
                    'call_log_id' => $updatedCallLog->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Notify new user that they have a connected call
            try {
                event(new CallConnected($updatedCallLog, $updatedCallLog->answered_by_user_id));
            } catch (\Throwable $e) {
                Log::warning('CallController: broadcast CallConnected on transfer failed', [
                    'call_log_id' => $updatedCallLog->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'status' => 'transferring',
                'transferred_to' => $updatedCallLog->answeredByUser?->name,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Hang up an active call.
     */
    public function hangup(CallLog $callLog): JsonResponse
    {
        // End the call on Twilio if it has a real SID
        if ($callLog->twilio_call_sid && ! str_starts_with($callLog->twilio_call_sid, 'pending-')) {
            try {
                $this->twilio->getClient()->calls($callLog->twilio_call_sid)->update(['status' => 'completed']);
            } catch (\Throwable $e) {
                // Call may already be ended on Twilio's side
                Log::warning('CallController: failed to end Twilio call', [
                    'call_log_id' => $callLog->id,
                    'sid' => $callLog->twilio_call_sid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $callLog->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);

        $userId = $callLog->answered_by_user_id ?? $callLog->caller_user_id ?? 0;

        try {
            event(new CallEnded($callLog, $userId));
        } catch (\Throwable $e) {
            Log::warning('CallController: broadcast CallEnded failed', [
                'call_log_id' => $callLog->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'completed']);
    }
}
