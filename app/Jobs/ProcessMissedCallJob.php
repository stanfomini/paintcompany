<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\MissedCallNotification;
use App\Notifications\VoicemailReceivedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProcessMissedCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        public int $callLogId
    ) {}

    public function handle(): void
    {
        $callLog = CallLog::with(['customer', 'callGroup'])->find($this->callLogId);

        if (! $callLog) {
            Log::warning('ProcessMissedCallJob: CallLog not found', [
                'call_log_id' => $this->callLogId,
            ]);

            return;
        }

        // Only process missed calls and voicemails
        if (! in_array($callLog->status, ['no-answer', 'voicemail'])) {
            return;
        }

        $recipients = $this->determineRecipients($callLog);

        if ($recipients->isEmpty()) {
            Log::info('ProcessMissedCallJob: No recipients found', [
                'call_log_id' => $this->callLogId,
            ]);

            return;
        }

        // Send appropriate notification
        if ($callLog->isVoicemail()) {
            Notification::send($recipients, new VoicemailReceivedNotification($callLog));
        } else {
            Notification::send($recipients, new MissedCallNotification($callLog));
        }

        // Create follow-up activity
        $this->createFollowUpActivity($callLog, $recipients->first());

        Log::info('ProcessMissedCallJob: Processed', [
            'call_log_id' => $this->callLogId,
            'status' => $callLog->status,
            'recipient_count' => $recipients->count(),
        ]);
    }

    /**
     * Determine who should receive the notification.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function determineRecipients(CallLog $callLog): \Illuminate\Support\Collection
    {
        // Personal voicemail — notify only that user
        if ($callLog->voicemail_for_user_id) {
            $user = User::find($callLog->voicemail_for_user_id);

            return $user ? collect([$user]) : collect();
        }

        // Call group members
        if ($callLog->callGroup) {
            $members = $callLog->callGroup->members()
                ->where('is_active', true)
                ->whereNotNull('user_id')
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter();

            if ($members->isNotEmpty()) {
                return $members;
            }
        }

        // Fallback: internal users with voicemail permissions
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->permission('phone.view_company_voicemails')
            ->get();
    }

    /**
     * Create a follow-up callback activity linked to the call log.
     */
    private function createFollowUpActivity(CallLog $callLog, User $assignee): void
    {
        // Skip if already has a follow-up
        if ($callLog->follow_up_activity_id) {
            return;
        }

        $callerName = $callLog->customer
            ? trim($callLog->customer->first_name.' '.$callLog->customer->last_name)
            : $callLog->from_number;

        $description = $callLog->isVoicemail()
            ? "Voicemail from {$callerName}"
            : "Missed call from {$callerName}";

        if ($callLog->voicemail_transcription) {
            $description .= "\n\nTranscription: {$callLog->voicemail_transcription}";
        }

        $activity = Activity::create([
            'user_id' => $assignee->id,
            'assigned_to' => $assignee->id,
            'subject_type' => $callLog->customer ? Customer::class : null,
            'subject_id' => $callLog->customer_id,
            'type' => 'call',
            'title' => "Callback: {$callerName}",
            'description' => $description,
            'priority' => 'high',
            'due_at' => now()->addMinutes(30),
        ]);

        $callLog->update(['follow_up_activity_id' => $activity->id]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessMissedCallJob: Failed permanently', [
            'call_log_id' => $this->callLogId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
