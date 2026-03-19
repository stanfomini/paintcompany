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
use Illuminate\Support\Facades\Notification;

class ProcessMissedCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $callLogId
    ) {}

    public function handle(): void
    {
        $callLog = CallLog::with('customer')->find($this->callLogId);

        if (! $callLog) {
            return;
        }

        // Skip if already processed (deduplication)
        if ($callLog->follow_up_activity_id) {
            return;
        }

        // Determine notification recipients based on ownership
        $recipients = $this->getRecipients($callLog);

        if ($recipients->isEmpty()) {
            return;
        }

        // Send appropriate notification
        $notification = $callLog->isVoicemail()
            ? new VoicemailReceivedNotification($callLog)
            : new MissedCallNotification($callLog);

        Notification::send($recipients, $notification);

        // Create follow-up Activity only if customer is identified
        if ($callLog->customer_id) {
            $assigneeId = $callLog->voicemail_for_user_id ?? $recipients->first()?->id;

            $activity = Activity::create([
                'user_id' => $assigneeId,
                'assigned_to' => $assigneeId,
                'subject_type' => Customer::class,
                'subject_id' => $callLog->customer_id,
                'type' => 'call',
                'title' => $callLog->isVoicemail()
                    ? __('Callback: :name (voicemail)', ['name' => $callLog->customer->full_name])
                    : __('Callback: :name (missed call)', ['name' => $callLog->customer->full_name]),
                'description' => $callLog->voicemail_transcription
                    ? __('Voicemail transcription: ":text"', ['text' => $callLog->voicemail_transcription])
                    : __('Missed call - no voicemail left'),
                'priority' => 'high',
                'due_at' => now()->addMinutes(30),
                'automation_key' => 'missed_call_callback_'.$callLog->id,
            ]);

            $callLog->update(['follow_up_activity_id' => $activity->id]);
        }
    }

    /**
     * Get users who should receive the notification.
     */
    private function getRecipients(CallLog $callLog): \Illuminate\Support\Collection
    {
        if ($callLog->voicemail_for_user_id) {
            // Personal: notify the target user + users with view_all permission
            $userIds = collect([$callLog->voicemail_for_user_id]);

            $viewAllIds = User::query()
                ->where('type', 'internal')
                ->where('id', '!=', $callLog->voicemail_for_user_id)
                ->permission('phone.view_all_voicemails')
                ->pluck('id');

            $userIds = $userIds->merge($viewAllIds)->unique();

            return User::whereIn('id', $userIds)->get();
        }

        // Company-wide: notify users with relevant permissions
        return User::query()
            ->where('type', 'internal')
            ->where(function ($q) {
                $q->permission('phone.view_company_voicemails')
                    ->orWhere(fn ($q2) => $q2->permission('phone.receive_calls'));
            })
            ->get();
    }
}
