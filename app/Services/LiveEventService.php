<?php

namespace App\Services;

use App\Events\LiveEventQuestionReceived;
use App\Models\ChatThread;
use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Models\LiveEventQuestion;
use App\Models\LiveEventRegistration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class LiveEventService
{
    public function __construct(
        private LiveKitService $liveKitService,
    ) {}

    /**
     * Create a new live event with a chat thread and generated room name.
     *
     * @param  array{title: string, description: string, host_user_id: int, scheduled_at: string, service_area_id?: int|null, service_id?: int|null, access_type?: string, max_viewers?: int, enable_recording?: bool, cover_image?: string|null}  $data
     */
    public function createEvent(array $data): LiveEvent
    {
        $chatThread = ChatThread::create([
            'type' => 'live_event',
            'subject' => $data['title'],
            'last_message_at' => now(),
        ]);

        $data['status'] = 'scheduled';
        $data['livekit_room_name'] = 'event-'.Str::random(12);
        $data['slug'] = Str::slug($data['title']);
        $data['chat_thread_id'] = $chatThread->id;

        return LiveEvent::create($data);
    }

    /**
     * Transition an event to live status, creating the LiveKit room and notifying registrations.
     */
    public function goLive(LiveEvent $event): void
    {
        $this->liveKitService->createRoom($event);

        $event->update([
            'status' => 'live',
            'started_at' => now(),
        ]);

        try {
            $event->registrations->each(function (LiveEventRegistration $registration) use ($event) {
                if ($registration->user) {
                    $registration->user->notify(new \App\Notifications\LiveEventStarted($event));
                } else {
                    Notification::route('mail', $registration->email)
                        ->notify(new \App\Notifications\LiveEventStarted($event));
                }
            });
        } catch (\Throwable $e) {
            Log::warning('LiveEvent: Failed to send start notifications', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * End a live event and clean up the LiveKit room.
     */
    public function endEvent(LiveEvent $event): void
    {
        try {
            $this->liveKitService->deleteRoom($event->livekit_room_name);
        } catch (\Throwable $e) {
            Log::warning('LiveEvent: Failed to delete room on end', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }

        $event->update([
            'status' => 'ended',
            'ended_at' => now(),
        ]);
    }

    /**
     * Cancel an event and clean up any existing LiveKit room.
     */
    public function cancelEvent(LiveEvent $event): void
    {
        $event->update(['status' => 'cancelled']);

        try {
            $this->liveKitService->deleteRoom($event->livekit_room_name);
        } catch (\Throwable $e) {
            Log::warning('LiveEvent: Failed to delete room on cancel', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register a viewer for an event.
     *
     * @param  array{name: string, email: string, phone?: string|null, user_id?: int|null}  $data
     */
    public function registerViewer(LiveEvent $event, array $data): LiveEventRegistration
    {
        return $event->registrations()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'user_id' => $data['user_id'] ?? null,
        ]);
    }

    /**
     * Submit a question from a viewer during a live event.
     */
    public function submitQuestion(LiveEvent $event, string $question, string $viewerName, ?int $userId = null): LiveEventQuestion
    {
        $liveEventQuestion = $event->questions()->create([
            'question' => $question,
            'viewer_name' => $viewerName,
            'user_id' => $userId,
            'status' => 'pending',
        ]);

        try {
            broadcast(new LiveEventQuestionReceived($liveEventQuestion));
        } catch (\Throwable $e) {
            Log::warning('LiveEvent: Failed to broadcast question', [
                'event_id' => $event->id,
                'question_id' => $liveEventQuestion->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $liveEventQuestion;
    }

    /**
     * Mark a question as answered.
     */
    public function answerQuestion(LiveEventQuestion $question): void
    {
        $question->update([
            'status' => 'answered',
            'answered_at' => now(),
        ]);
    }

    /**
     * Dismiss a question.
     */
    public function dismissQuestion(LiveEventQuestion $question): void
    {
        $question->update(['status' => 'dismissed']);
    }

    /**
     * Invite a remote guest to participate in the event.
     *
     * @param  array{name: string, email?: string|null, phone?: string|null, instructions?: string|null, slot_number?: int|null}  $data
     */
    public function inviteGuest(LiveEvent $event, array $data): LiveEventGuest
    {
        return $event->guests()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'slot_number' => $data['slot_number'] ?? $this->nextAvailableSlot($event),
        ]);
    }

    /**
     * Generate the guest room URL for a guest invitation.
     */
    public function generateGuestLink(LiveEventGuest $guest): string
    {
        return route('events.guest-room', ['token' => $guest->token]);
    }

    /**
     * Remove a guest invitation.
     */
    public function removeGuest(LiveEventGuest $guest): void
    {
        $guest->delete();
    }

    /**
     * Update guest connection status when they join the green room.
     */
    public function markGuestConnected(LiveEventGuest $guest): void
    {
        $guest->update([
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }

    /**
     * Mark a guest as live (their camera is being broadcast).
     */
    public function markGuestLive(LiveEventGuest $guest): void
    {
        $guest->update([
            'status' => 'live',
            'went_live_at' => now(),
        ]);
    }

    /**
     * Return a guest to connected/waiting state (taken off air).
     */
    public function markGuestOffAir(LiveEventGuest $guest): void
    {
        $guest->update([
            'status' => 'connected',
            'went_live_at' => null,
        ]);
    }

    /**
     * Mark a guest as disconnected.
     */
    public function markGuestDisconnected(LiveEventGuest $guest): void
    {
        $guest->update(['status' => 'disconnected']);
    }

    /**
     * Ensure the event has a backstage chat thread for producer-guest communication.
     */
    public function ensureBackstageChatThread(LiveEvent $event): ChatThread
    {
        if ($event->backstage_chat_thread_id) {
            return $event->backstageChatThread;
        }

        $thread = ChatThread::create([
            'type' => 'live_event',
            'subject' => $event->title.' - Backstage',
            'last_message_at' => now(),
        ]);

        $event->update(['backstage_chat_thread_id' => $thread->id]);

        return $thread;
    }

    /**
     * Find the next available guest slot number (1-8).
     */
    private function nextAvailableSlot(LiveEvent $event): int
    {
        $usedSlots = $event->guests()->whereNotNull('slot_number')->pluck('slot_number')->toArray();

        for ($i = 1; $i <= 8; $i++) {
            if (! in_array($i, $usedSlots)) {
                return $i;
            }
        }

        return count($usedSlots) + 1;
    }
}
