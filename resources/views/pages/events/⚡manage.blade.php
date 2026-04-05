<?php

use App\Events\BackstageChatMessageSent;
use App\Events\LiveEventChatMessageSent;
use App\Events\LiveEventQuestionAnswered;
use App\Events\LiveEventViewerCountUpdated;
use App\Models\ChatMessage;
use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Models\LiveEventQuestion;
use App\Models\LiveEventResource;
use App\Models\LiveEventSceneTemplate;
use App\Services\LiveEventService;
use App\Services\LiveKitService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Event Control Room')]
class extends Component
{
    public LiveEvent $event;

    public string $livekitToken = '';

    public string $livekitUrl = '';

    public bool $isHost = false;

    public int $viewerCount = 0;

    // Resource form fields
    public string $newResourceTitle = '';

    public string $newResourceUrl = '';

    public string $newResourceType = 'link';

    // Chat
    public string $chatMessage = '';

    // Guest invite form
    public string $guestName = '';

    public string $guestEmail = '';

    public string $guestPhone = '';

    public string $guestInstructions = '';

    // Scene/overlay editing
    public string $templateName = '';

    // Backstage chat
    public string $backstageMessage = '';

    /**
     * Register Echo listeners for real-time broadcasting.
     * Replaces wire:poll with event-driven updates via Reverb.
     */
    public function getListeners(): array
    {
        return [
            // Presence channel — chat messages from all participants
            "echo-presence:live-event.{$this->event->id},.chat.message" => 'onChatMessage',

            // Presence channel — viewer count updates
            "echo-presence:live-event.{$this->event->id},.viewer.count" => 'onViewerCountUpdated',

            // Presence channel — someone joined or left
            "echo-presence:live-event.{$this->event->id},here" => 'onPresenceHere',
            "echo-presence:live-event.{$this->event->id},joining" => 'onPresenceJoining',
            "echo-presence:live-event.{$this->event->id},leaving" => 'onPresenceLeaving',

            // Private host channel — new questions from viewers
            "echo-private:live-event-host.{$this->event->id},.question.received" => 'onQuestionReceived',

            // Private backstage channel — messages from guests
            "echo-private:event-backstage.{$this->event->id},.backstage.message" => 'onBackstageMessage',
        ];
    }

    /**
     * Echo handler: new backstage message from a guest.
     */
    #[Renderless]
    public function onBackstageMessage(array $event): void
    {
        unset($this->backstageMessages);
        $this->dispatch('backstage-message-received', content: $event['content'] ?? '', createdAt: $event['created_at'] ?? '');
    }

    public function boot(): void
    {
        if (auth()->user()?->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(LiveEvent $liveEvent): void
    {
        $this->event = $liveEvent->load(['questions', 'resources', 'registrations', 'serviceArea', 'service', 'host', 'chatThread']);
        $this->livekitUrl = config('services.livekit.url') ?? '';
        $this->isHost = $this->event->host_user_id === auth()->id();

        // Auto-generate token if event is already live (page refresh while streaming)
        if ($this->event->status === 'live') {
            $liveKitService = app(LiveKitService::class);
            $this->livekitToken = $this->isHost
                ? $liveKitService->generateHostToken($this->event, auth()->user())
                : $liveKitService->generateCoproducerToken($this->event, auth()->user());
        }
    }

    public function goLive()
    {
        if (! in_array($this->event->status, ['draft', 'scheduled'])) {
            session()->flash('error', __('This event cannot go live from its current status.'));

            return;
        }

        // Reset any live guests back to connected so they can be re-brought-on after redirect
        $this->event->guests()->where('status', 'live')->update(['status' => 'connected']);

        try {
            $liveEventService = app(LiveEventService::class);
            $liveEventService->goLive($this->event);
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to start live stream. Please try again.'));

            return;
        }

        $this->event->refresh();

        if ($this->event->status !== 'live') {
            session()->flash('error', __('Failed to go live. Please try again.'));

            return;
        }

        // Redirect to force a clean page load — the live template is different from setup.
        // Guests will need to be brought on again after the page reloads.
        return $this->redirect(route('events.manage', $this->event), navigate: false);
    }

    #[Renderless]
    public function endStream(): void
    {
        if ($this->event->status !== 'live') {
            return;
        }

        $liveEventService = app(LiveEventService::class);
        $liveEventService->endEvent($this->event);

        $this->event->refresh();

        $this->dispatch('livekit-disconnect');
    }

    #[Renderless]
    public function answerQuestion(int $questionId): void
    {
        $question = LiveEventQuestion::where('live_event_id', $this->event->id)->findOrFail($questionId);

        app(LiveEventService::class)->answerQuestion($question);

        // Notify viewers that a question was answered so they can refresh their list
        try {
            broadcast(new LiveEventQuestionAnswered($this->event->id, $questionId));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveEvent: Failed to broadcast question answered', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }

        unset($this->pendingQuestions, $this->answeredQuestions);
        $this->dispatch('question-updated', questionId: $questionId, action: 'answered');
    }

    #[Renderless]
    public function dismissQuestion(int $questionId): void
    {
        $question = LiveEventQuestion::where('live_event_id', $this->event->id)->findOrFail($questionId);

        app(LiveEventService::class)->dismissQuestion($question);

        unset($this->pendingQuestions, $this->answeredQuestions);
        $this->dispatch('question-updated', questionId: $questionId, action: 'dismissed');
    }

    #[Renderless]
    public function addResource(): void
    {
        $this->validate([
            'newResourceTitle' => 'required|string|max:255',
            'newResourceUrl' => 'required|url|max:2048',
            'newResourceType' => 'required|in:link,document,video',
        ]);

        $maxSort = $this->event->resources()->max('sort_order') ?? 0;

        $resource = $this->event->resources()->create([
            'title' => $this->newResourceTitle,
            'url' => $this->newResourceUrl,
            'type' => $this->newResourceType,
            'sort_order' => $maxSort + 1,
        ]);

        $this->dispatch('resource-added', id: $resource->id, title: $resource->title, url: $resource->url, type: $resource->type);

        $this->reset('newResourceTitle', 'newResourceUrl', 'newResourceType');
        $this->newResourceType = 'link';

        unset($this->resources);
    }

    #[Renderless]
    public function removeResource(int $resourceId): void
    {
        LiveEventResource::where('live_event_id', $this->event->id)->where('id', $resourceId)->delete();

        unset($this->resources);
        $this->dispatch('resource-removed', id: $resourceId);
    }

    #[Renderless]
    public function refreshViewerCount(): void
    {
        if ($this->event->status !== 'live') {
            return;
        }

        try {
            $count = app(LiveKitService::class)->getParticipantCount($this->event->livekit_room_name);

            // Subtract 1 for the host
            $this->viewerCount = max(0, $count - 1);

            // Broadcast the updated count to all viewers
            broadcast(new LiveEventViewerCountUpdated($this->event->id, $this->viewerCount));

            // Push to Alpine (renderless won't re-render the Blade template)
            $this->dispatch('viewer-count-updated', count: $this->viewerCount);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveEvent: Failed to refresh viewer count', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    #[Renderless]
    public function sendChatMessage(): void
    {
        $this->validate([
            'chatMessage' => 'required|string|max:500',
        ]);

        if (! $this->event->chat_thread_id) {
            return;
        }

        $user = auth()->user();
        $content = $user->name.': '.$this->chatMessage;

        $message = ChatMessage::create([
            'thread_id' => $this->event->chat_thread_id,
            'user_id' => $user->id,
            'content' => $content,
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => false,
        ]);

        // Broadcast chat message to all viewers via Reverb
        try {
            broadcast(new LiveEventChatMessageSent($message, $this->event->id));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveEvent: Failed to broadcast chat message', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->dispatch('chat-message-appended', content: $content, id: $message->id);

        $this->chatMessage = '';
        unset($this->chatMessages);
    }

    /**
     * Echo handler: new chat message received via broadcast.
     */
    #[Renderless]
    public function onChatMessage(array $event): void
    {
        unset($this->chatMessages);
        $this->dispatch('chat-message-appended', content: $event['content'] ?? '', id: $event['id'] ?? 0);
    }

    /**
     * Echo handler: viewer count updated via broadcast.
     */
    #[Renderless]
    public function onViewerCountUpdated(array $event): void
    {
        $this->viewerCount = $event['count'] ?? $this->viewerCount;
        $this->dispatch('viewer-count-updated', count: $this->viewerCount);
    }

    /**
     * Echo handler: presence channel reports who is here on initial join.
     */
    #[Renderless]
    public function onPresenceHere(array $event): void
    {
        $this->refreshViewerCount();
        unset($this->guests);
        $this->dispatchGuestList();
    }

    /**
     * Echo handler: someone joined the presence channel.
     */
    #[Renderless]
    public function onPresenceJoining(array $event): void
    {
        $this->refreshViewerCount();
        unset($this->guests);
        $this->dispatchGuestList();
    }

    /**
     * Echo handler: someone left the presence channel.
     */
    #[Renderless]
    public function onPresenceLeaving(array $event): void
    {
        $this->refreshViewerCount();
        unset($this->guests);
        $this->dispatchGuestList();
    }

    /**
     * Echo handler: new question received from a viewer.
     */
    #[Renderless]
    public function onQuestionReceived(array $event): void
    {
        unset($this->pendingQuestions);
        $this->dispatch('question-received', question: $event['question'] ?? '', viewerName: $event['viewer_name'] ?? '', id: $event['id'] ?? 0);
    }

    #[Computed]
    public function chatMessages(): \Illuminate\Support\Collection
    {
        if (! $this->event->chat_thread_id) {
            return collect();
        }

        return ChatMessage::where('thread_id', $this->event->chat_thread_id)
            ->where('is_internal', false)
            ->latest()
            ->take(50)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function pendingQuestions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->event->questions()->pending()->latest()->get();
    }

    #[Computed]
    public function answeredQuestions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->event->questions()->answered()->latest()->get();
    }

    #[Computed]
    public function resources(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->event->resources()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function registrationCount(): int
    {
        return $this->event->registrations()->count();
    }

    // ─── Guest Management ───────────────────────────────────────────

    #[Computed]
    public function guests(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->event->guests()->orderBy('slot_number')->get();
    }

    #[Renderless]
    public function inviteGuest(): void
    {
        $this->validate([
            'guestName' => 'required|string|max:255',
            'guestEmail' => 'nullable|email|max:255',
            'guestPhone' => 'nullable|string|max:50',
            'guestInstructions' => 'nullable|string|max:1000',
        ]);

        app(LiveEventService::class)->inviteGuest($this->event, [
            'name' => $this->guestName,
            'email' => $this->guestEmail ?: null,
            'phone' => $this->guestPhone ?: null,
            'instructions' => $this->guestInstructions ?: null,
        ]);

        $this->reset('guestName', 'guestEmail', 'guestPhone', 'guestInstructions');
        unset($this->guests);
        $this->dispatchGuestList();
    }

    #[Renderless]
    public function removeGuest(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        app(LiveEventService::class)->removeGuest($guest);

        unset($this->guests);
        $this->dispatchGuestList();
    }

    #[Renderless]
    public function copyGuestLink(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        $link = app(LiveEventService::class)->generateGuestLink($guest);

        $this->dispatch('copy-to-clipboard', link: $link);
    }

    // ─── Scene & Overlay Management ─────────────────────────────────

    #[Computed]
    public function sceneTemplates(): \Illuminate\Database\Eloquent\Collection
    {
        return LiveEventSceneTemplate::where('user_id', auth()->id())->orderBy('name')->get();
    }

    #[Renderless]
    public function updateScenes(array $scenes): void
    {
        foreach ($scenes as $scene) {
            if (! isset($scene['name'], $scene['slots'])) {
                return;
            }
        }

        $this->event->update(['scenes' => $scenes]);
    }

    #[Renderless]
    public function toggleFrameMode(): void
    {
        $this->event->update(['use_frame_mode' => ! $this->event->use_frame_mode]);
        $this->event->refresh();
    }

    #[Renderless]
    public function saveAsTemplate(array $sceneData = []): void
    {
        $this->validate([
            'templateName' => 'required|string|max:255',
        ]);

        if (empty($sceneData) || ! isset($sceneData['name'], $sceneData['slots'])) {
            $sceneData = $this->event->getScenesOrDefaults()[0] ?? [];
            if (empty($sceneData)) {
                return;
            }
        }

        $template = LiveEventSceneTemplate::create([
            'user_id' => auth()->id(),
            'name' => $this->templateName,
            'base_layout' => $sceneData['base_layout'] ?? null,
            'scene_data' => $sceneData,
        ]);

        $this->reset('templateName');
        unset($this->sceneTemplates);
        $this->dispatch('template-saved', id: $template->id, name: $template->name);
    }

    public function loadTemplate(int $templateId): void
    {
        $template = LiveEventSceneTemplate::where('user_id', auth()->id())->findOrFail($templateId);

        $sceneData = $template->scene_data ?? [];
        if (empty($sceneData) || ! isset($sceneData['name'], $sceneData['slots'])) {
            return;
        }

        if (! isset($sceneData['overlays'])) {
            $sceneData['overlays'] = [];
        }

        $scenes = $this->event->scenes ?? $this->event->getScenesOrDefaults();
        $scenes[] = $sceneData;

        $this->event->update([
            'scenes' => $scenes,
        ]);

        $this->event->refresh();

        $this->dispatch('template-loaded', scenes: $this->event->getScenesOrDefaults());
    }

    #[Renderless]
    public function deleteTemplate(int $templateId): void
    {
        LiveEventSceneTemplate::where('user_id', auth()->id())
            ->where('id', $templateId)
            ->delete();

        unset($this->sceneTemplates);
        $this->dispatch('template-deleted', id: $templateId);
    }

    // ─── RTMP Ingress / Egress ────────────────────────────────────────

    #[Renderless]
    public function createRtmpIngress(): ?array
    {
        if ($this->event->status !== 'live') {
            return null;
        }

        try {
            return app(LiveKitService::class)->createRtmpIngress($this->event);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LiveEvent: Failed to create RTMP ingress', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    #[Renderless]
    public function deleteRtmpIngress(string $ingressId): void
    {
        try {
            app(LiveKitService::class)->deleteRtmpIngress($ingressId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LiveEvent: Failed to delete RTMP ingress', [
                'ingress_id' => $ingressId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    #[Renderless]
    public function startRtmpEgress(string $rtmpUrl): ?string
    {
        if ($this->event->status !== 'live' || empty($rtmpUrl)) {
            return null;
        }

        try {
            return app(LiveKitService::class)->startRtmpEgress($this->event, $rtmpUrl);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LiveEvent: Failed to start RTMP egress', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    #[Renderless]
    public function stopRtmpEgress(string $egressId): void
    {
        try {
            app(LiveKitService::class)->stopRtmpEgress($egressId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LiveEvent: Failed to stop RTMP egress', [
                'egress_id' => $egressId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─── Backstage Chat ─────────────────────────────────────────────

    #[Computed]
    public function backstageMessages(): \Illuminate\Support\Collection
    {
        if (! $this->event->backstage_chat_thread_id) {
            return collect();
        }

        return ChatMessage::where('thread_id', $this->event->backstage_chat_thread_id)
            ->latest()
            ->take(50)
            ->get()
            ->reverse()
            ->values();
    }

    #[Renderless]
    public function sendBackstageMessage(): void
    {
        $this->validate([
            'backstageMessage' => 'required|string|max:500',
        ]);

        $service = app(LiveEventService::class);
        $thread = $service->ensureBackstageChatThread($this->event);
        $this->event->refresh();

        $user = auth()->user();
        $content = $user->name.': '.$this->backstageMessage;

        $message = ChatMessage::create([
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'content' => $content,
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => false,
        ]);

        try {
            broadcast(new BackstageChatMessageSent($message, $this->event->id));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveEvent: Failed to broadcast backstage message', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->dispatch('backstage-message-sent', content: $content);

        $this->backstageMessage = '';
        unset($this->backstageMessages);
    }

    // ─── Live Production Controls ──────────────────────────────────

    #[Renderless]
    public function switchScene(int $index): void
    {
        $scenes = $this->event->getScenesOrDefaults();

        if ($index < 0 || $index >= count($scenes)) {
            return;
        }

        $this->event->update(['active_scene_index' => $index]);

        try {
            broadcast(new \App\Events\LiveEventSceneChanged($this->event, $index));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveEvent: Failed to broadcast scene change', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    #[Renderless]
    public function bringGuestOn(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        // Grant publish permission in LiveKit — guest can now send camera/mic
        try {
            app(LiveKitService::class)->grantPublish($this->event, $guest);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveKit: Failed to grant publish to guest', [
                'guest_id' => $guest->id,
                'error' => $e->getMessage(),
            ]);
        }

        app(LiveEventService::class)->markGuestLive($guest);

        unset($this->guests);
        $this->dispatchGuestSlotMap();
        $this->dispatchGuestList();
    }

    #[Renderless]
    public function takeGuestOff(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        // Revoke publish permission — LiveKit automatically unpublishes all tracks
        try {
            app(LiveKitService::class)->revokePublish($this->event, $guest);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveKit: Failed to revoke publish from guest', [
                'guest_id' => $guest->id,
                'error' => $e->getMessage(),
            ]);
        }

        app(LiveEventService::class)->markGuestOffAir($guest);

        // Tell Alpine to remove the guest source
        $this->dispatch('guest-take-off', identity: $guest->getLivekitIdentity(), guestId: $guest->id);

        unset($this->guests);
        $this->dispatchGuestSlotMap();
        $this->dispatchGuestList();
    }

    #[Renderless]
    public function refreshGuestStatuses(): void
    {
        unset($this->guests);
        $this->dispatchGuestSlotMap();
        $this->dispatchGuestList();
    }

    private function dispatchGuestSlotMap(): void
    {
        $map = $this->guests->mapWithKeys(
            fn ($g) => [$g->getLivekitIdentity() => 'guest-' . $g->slot_number]
        )->toArray();

        $this->dispatch('update-guest-slot-map', map: $map);
    }

    private function dispatchGuestList(): void
    {
        $guests = $this->guests->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'status' => $g->status,
            'slot_number' => $g->slot_number,
            'identity' => $g->getLivekitIdentity(),
        ])->values()->toArray();

        $this->dispatch('guests-updated', guests: $guests);
    }
}; ?>

<div>
    {{-- ============================================================ --}}
    {{-- PRE-LIVE STATE (draft / scheduled) --}}
    {{-- ============================================================ --}}
    @if (in_array($event->status, ['draft', 'scheduled']))
        <div class="mx-auto max-w-4xl px-4 py-8">
            {{-- Event summary card --}}
            <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="xl">{{ $event->title }}</flux:heading>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <flux:badge :color="$event->status === 'scheduled' ? 'blue' : 'zinc'" size="sm">
                                {{ ucfirst($event->status) }}
                            </flux:badge>
                            @if ($event->scheduled_at)
                                <span class="flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    <flux:icon name="calendar" variant="micro" />
                                    {{ $event->scheduled_at->format('M j, Y g:i A') }}
                                </span>
                            @endif
                            @if ($event->serviceArea)
                                <span class="flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    <flux:icon name="map-pin" variant="micro" />
                                    {{ $event->serviceArea->city }}
                                </span>
                            @endif
                            @if ($event->service)
                                <span class="flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    <flux:icon name="wrench-screwdriver" variant="micro" />
                                    {{ $event->service->title }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="eye" :href="route('live.show', $event->slug)" target="_blank">
                            {{ __('Public Page') }}
                        </flux:button>
                        <flux:button size="sm" variant="ghost" icon="pencil" :href="route('events.edit', $event)">
                            {{ __('Edit') }}
                        </flux:button>
                    </div>
                </div>

                @if ($event->description)
                    <flux:text class="mt-3">{{ $event->description }}</flux:text>
                @endif

                <div class="mt-4 flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="flex items-center gap-1">
                        <flux:icon name="users" variant="micro" />
                        {{ $this->registrationCount }} {{ __('registered') }}
                    </span>
                    @if ($event->max_viewers)
                        <span class="flex items-center gap-1">
                            <flux:icon name="eye" variant="micro" />
                            {{ __('Max :count viewers', ['count' => $event->max_viewers]) }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Camera preview --}}
            <div
                x-data="cameraPreview"
                class="relative mb-6 overflow-hidden rounded-xl bg-black shadow-lg"
                style="aspect-ratio: 16/9;"
            >
                <video x-ref="preview" autoplay muted playsinline class="h-full w-full object-cover"></video>

                {{-- No camera fallback --}}
                <div x-show="!hasStream" class="absolute inset-0 flex flex-col items-center justify-center bg-zinc-900">
                    <div class="mb-3 flex size-16 items-center justify-center rounded-full bg-zinc-700">
                        <flux:icon name="video-camera" class="size-8 text-zinc-400" />
                    </div>
                    <span class="text-sm text-zinc-400">{{ __('Camera preview loading...') }}</span>
                </div>

                {{-- Audio level indicator --}}
                <div x-show="hasStream" class="absolute bottom-4 left-4 flex items-center gap-2">
                    <flux:icon name="microphone" class="size-4 text-white/70" />
                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-white/20">
                        <div
                            class="h-full rounded-full bg-green-500 transition-all duration-75"
                            :style="`width: ${Math.min(audioLevel * 100, 100)}%`"
                        ></div>
                    </div>
                </div>

                {{-- Camera error --}}
                <div x-show="cameraError" class="absolute inset-0 flex flex-col items-center justify-center bg-zinc-900">
                    <div class="mb-3 flex size-16 items-center justify-center rounded-full bg-red-500/20">
                        <flux:icon name="exclamation-triangle" class="size-8 text-red-400" />
                    </div>
                    <span class="text-sm text-red-400" x-text="cameraError"></span>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- PRODUCTION SETUP --}}
            {{-- ============================================================ --}}
            <div class="mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Production Setup') }}</flux:heading>

                <div class="grid gap-6 lg:grid-cols-2">
                    {{-- Left Column: Scenes + Overlays --}}
                    <div class="space-y-6">
                        {{-- Scene Configuration --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="sm" class="mb-3">{{ __('Scene Presets') }}</flux:heading>

                            @php
                                $defaultScenes = \App\Models\LiveEventSceneTemplate::defaultScenes();
                                $currentScenes = $event->getScenesOrDefaults();
                                $currentSceneNames = collect($currentScenes)->pluck('name')->toArray();
                                $sceneIcons = [
                                    'Solo' => ['slots' => 1, 'layout' => 'full'],
                                    'Interview' => ['slots' => 2, 'layout' => 'split'],
                                    'Guest Spotlight' => ['slots' => 1, 'layout' => 'full-guest'],
                                    'Screen Share' => ['slots' => 1, 'layout' => 'screen'],
                                    'Picture-in-Picture' => ['slots' => 2, 'layout' => 'pip'],
                                ];
                            @endphp

                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                                @foreach ($defaultScenes as $index => $scene)
                                    @php $layout = $sceneIcons[$scene['name']]['layout'] ?? 'full'; @endphp
                                    <button
                                        type="button"
                                        wire:click="updateScenes(@js([$scene]))"
                                        class="group flex flex-col items-center rounded-lg border-2 p-3 text-center transition-all
                                            {{ in_array($scene['name'], $currentSceneNames) ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 bg-zinc-50 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:border-zinc-500' }}"
                                    >
                                        {{-- Visual layout representation --}}
                                        <div class="mb-2 flex h-10 w-full items-end justify-center gap-0.5">
                                            @if ($layout === 'full')
                                                <div class="h-full w-full rounded bg-blue-500/60"></div>
                                            @elseif ($layout === 'split')
                                                <div class="h-full w-1/2 rounded-l bg-blue-500/60"></div>
                                                <div class="h-full w-1/2 rounded-r bg-emerald-500/60"></div>
                                            @elseif ($layout === 'full-guest')
                                                <div class="h-full w-full rounded bg-emerald-500/60"></div>
                                            @elseif ($layout === 'screen')
                                                <div class="h-full w-full rounded bg-purple-500/60"></div>
                                            @elseif ($layout === 'pip')
                                                <div class="relative h-full w-full rounded bg-emerald-500/60">
                                                    <div class="absolute bottom-0.5 right-0.5 h-3 w-4 rounded-sm bg-blue-500/80"></div>
                                                </div>
                                            @endif
                                        </div>
                                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ $scene['name'] }}</span>
                                    </button>
                                @endforeach
                            </div>

                            {{-- Templates --}}
                            @if ($this->sceneTemplates->isNotEmpty())
                                <div class="mt-4 space-y-1">
                                    @foreach ($this->sceneTemplates as $template)
                                        <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-1.5 dark:bg-zinc-900">
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300 truncate">{{ $template->name }}</span>
                                            <div class="flex items-center gap-1 shrink-0">
                                                <flux:button size="xs" variant="ghost" icon="arrow-down-tray"
                                                    wire:click="loadTemplate({{ $template->id }})">
                                                    {{ __('Load') }}
                                                </flux:button>
                                                <flux:button size="xs" variant="ghost" icon="trash"
                                                    wire:click="deleteTemplate({{ $template->id }})"
                                                    wire:confirm="{{ __('Delete this template?') }}" />
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Save as template --}}
                            <div class="mt-3 flex items-center gap-2">
                                <flux:input wire:model="templateName" placeholder="{{ __('Template name...') }}" size="sm" class="flex-1" />
                                <flux:button size="sm" variant="ghost" icon="bookmark" wire:click="saveAsTemplate" wire:loading.attr="disabled">
                                    {{ __('Save') }}
                                </flux:button>
                            </div>
                            @error('templateName') <span class="mt-1 text-xs text-red-500">{{ $message }}</span> @enderror
                        </div>

                        {{-- Overlay Display (read-only — managed during live session) --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="sm" class="mb-3">{{ __('Overlays') }}</flux:heading>

                            @php
                                $activeScene = ($event->scenes ?? [])[0] ?? null;
                                $sceneOverlays = $activeScene['overlays'] ?? [];
                            @endphp

                            @if (! empty($sceneOverlays))
                                <div class="mb-3 space-y-2">
                                    @foreach ($sceneOverlays as $index => $overlay)
                                        <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-900">
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300 truncate">
                                                <flux:badge size="sm" color="zinc" class="mr-1">{{ ucfirst($overlay['type'] ?? 'text') }}</flux:badge>
                                                {{ $overlay['content'] ?? '' }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <flux:text class="text-sm text-zinc-400">{{ __('No overlays configured. Add overlays during the live session.') }}</flux:text>
                            @endif

                            {{-- Frame mode toggle --}}
                            <div class="mt-4 flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-900">
                                <div>
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Frame Mode') }}</span>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Add decorative frame around video') }}</p>
                                </div>
                                <flux:switch wire:click="toggleFrameMode" :checked="$event->use_frame_mode" />
                            </div>
                        </div>
                    </div>

                    {{-- Right Column: Guest Management --}}
                    <div class="space-y-6">
                        {{-- Guest Invite Panel --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="sm" class="mb-3">{{ __('Guest Management') }}</flux:heading>

                            {{-- Invite form --}}
                            <form wire:submit="inviteGuest" class="space-y-3">
                                <flux:input wire:model="guestName" label="{{ __('Name') }}" placeholder="{{ __('Guest name') }}" size="sm" required />
                                <div class="grid grid-cols-2 gap-2">
                                    <flux:input wire:model="guestEmail" label="{{ __('Email') }}" placeholder="{{ __('email@example.com') }}" size="sm" type="email" />
                                    <flux:input wire:model="guestPhone" label="{{ __('Phone') }}" placeholder="{{ __('(555) 123-4567') }}" size="sm" />
                                </div>
                                <flux:textarea wire:model="guestInstructions" label="{{ __('Instructions') }}" placeholder="{{ __('Notes for the guest...') }}" rows="2" size="sm" />
                                <flux:button type="submit" size="sm" variant="primary" icon="user-plus" wire:loading.attr="disabled" class="w-full">
                                    <span wire:loading.remove wire:target="inviteGuest">{{ __('Invite Guest') }}</span>
                                    <span wire:loading wire:target="inviteGuest">{{ __('Inviting...') }}</span>
                                </flux:button>
                                @error('guestName') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                @error('guestEmail') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </form>

                            {{-- Guest list --}}
                            @if ($this->guests->isNotEmpty())
                                <div
                                    class="mt-4 space-y-2"
                                    x-data
                                    x-on:copy-to-clipboard.window="if ($event.detail.link) { navigator.clipboard.writeText($event.detail.link); $flux.toast('{{ __('Link copied!') }}') }"
                                >
                                    @foreach ($this->guests as $guest)
                                        <div wire:key="guest-{{ $guest->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                            <div class="flex items-center gap-2 min-w-0">
                                                {{-- Status indicator --}}
                                                @if ($guest->isConnected() || $guest->isLive())
                                                    <span class="flex size-2.5 shrink-0">
                                                        <span class="absolute inline-flex size-2.5 animate-ping rounded-full bg-green-400 opacity-75"></span>
                                                        <span class="relative inline-flex size-2.5 rounded-full bg-green-500"></span>
                                                    </span>
                                                @else
                                                    <span class="inline-flex size-2.5 shrink-0 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                                                @endif

                                                <div class="min-w-0">
                                                    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200 truncate block">{{ $guest->name }}</span>
                                                    @if ($guest->slot_number)
                                                        <span class="text-xs text-zinc-400">{{ __('Slot :num', ['num' => $guest->slot_number]) }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1 shrink-0">
                                                <flux:badge size="sm" :color="match($guest->status) {
                                                    'connected' => 'green',
                                                    'live' => 'red',
                                                    'disconnected' => 'zinc',
                                                    default => 'blue',
                                                }">
                                                    {{ ucfirst($guest->status) }}
                                                </flux:badge>
                                                <flux:button size="xs" variant="ghost" icon="link" wire:click="copyGuestLink({{ $guest->id }})" title="{{ __('Copy invite link') }}" />
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
                                                    icon="trash"
                                                    wire:click="removeGuest({{ $guest->id }})"
                                                    wire:confirm="{{ __('Remove this guest?') }}"
                                                />
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-4 rounded-lg bg-zinc-50 p-4 text-center dark:bg-zinc-900">
                                    <flux:icon name="user-group" class="mx-auto mb-2 size-6 text-zinc-400" />
                                    <flux:text class="text-sm">{{ __('No guests invited yet.') }}</flux:text>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Backstage Chat (full width) --}}
                @if ($this->guests->where('status', 'connected')->isNotEmpty() || $event->backstage_chat_thread_id)
                    <div class="mt-6 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                            <flux:heading size="sm" class="flex items-center gap-2">
                                <flux:icon name="chat-bubble-left-right" variant="micro" class="text-amber-500" />
                                {{ __('Backstage Chat') }}
                            </flux:heading>
                            @if ($this->backstageMessages->isNotEmpty())
                                <flux:badge color="amber" size="sm">{{ $this->backstageMessages->count() }}</flux:badge>
                            @endif
                        </div>

                        <div
                            class="h-48 overflow-y-auto px-5 py-3 space-y-1"
                            x-data
                            x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        >
                            @forelse ($this->backstageMessages as $msg)
                                <div wire:key="bs-{{ $msg->id }}" class="text-sm">
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $msg->content }}</span>
                                </div>
                            @empty
                                <div class="flex items-center justify-center h-full">
                                    <flux:text class="text-sm text-zinc-400">{{ __('Backstage chat — communicate with guests before going live.') }}</flux:text>
                                </div>
                            @endforelse
                        </div>

                        <form wire:submit="sendBackstageMessage" class="flex items-center gap-2 border-t border-zinc-200 px-5 py-3 dark:border-zinc-700">
                            <flux:input
                                wire:model="backstageMessage"
                                placeholder="{{ __('Message your guests...') }}"
                                size="sm"
                                class="flex-1"
                                maxlength="500"
                            />
                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" />
                        </form>
                    </div>
                @endif
            </div>

            {{-- Go Live button --}}
            <flux:button
                wire:click="goLive"
                wire:loading.attr="disabled"
                wire:confirm="{{ __('Are you ready to go live? Registered viewers will be notified.') }}"
                variant="danger"
                class="w-full h-12 text-base"
                icon="signal"
            >
                <span wire:loading.remove wire:target="goLive">{{ __('Go Live') }}</span>
                <span wire:loading wire:target="goLive">{{ __('Starting stream...') }}</span>
            </flux:button>

            {{-- Pre-live info cards --}}
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                {{-- Resources --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-3">{{ __('Resources') }}</flux:heading>
                    @if ($this->resources->isEmpty())
                        <flux:text class="text-sm">{{ __('No resources added yet. You can add them during the stream.') }}</flux:text>
                    @else
                        <div class="space-y-2">
                            @foreach ($this->resources as $resource)
                                <div class="flex items-center justify-between text-sm">
                                    <a href="{{ $resource->url }}" target="_blank" class="text-blue-600 hover:underline dark:text-blue-400">
                                        {{ $resource->title }}
                                    </a>
                                    <flux:badge size="sm" color="zinc">{{ $resource->type }}</flux:badge>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Registrations --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-3">{{ __('Registrations') }}</flux:heading>
                    <div class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->registrationCount }}</div>
                    <flux:text class="mt-1 text-sm">{{ __('people registered for this event') }}</flux:text>
                </div>
            </div>
        </div>

    {{-- ============================================================ --}}
    {{-- LIVE STATE — Production Studio --}}
    {{-- ============================================================ --}}
    @elseif ($event->status === 'live')
        @php
            $scenes = $event->getScenesOrDefaults();
            $activeSceneIndex = $event->active_scene_index ?? 0;
            $connectedGuests = $this->guests->whereIn('status', ['connected', 'live']);
        @endphp
        <div
            x-data="livekitProducer"
            x-init="$nextTick(() => {
                scenes = (@js($scenes)).map(s => ({ ...s, overlays: s.overlays || [] }));
                liveSceneIndex = @js($activeSceneIndex);
                previewSceneIndex = @js($activeSceneIndex);
                isHost = @js($isHost);
                liveGuests = @js($connectedGuests->merge($this->guests)->unique('id')->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'status' => $g->status, 'slot_number' => $g->slot_number, 'identity' => $g->getLivekitIdentity()])->values()->toArray());
                @if ($livekitToken)
                    startStudio(@js($livekitToken), @js($livekitUrl), @js($event->id));
                @endif
            })"
            class="flex h-[calc(100vh-4rem)] flex-col lg:flex-row"
        >
            {{-- Connection error banner --}}
            <div x-show="connectionError" x-cloak class="border-b border-red-700 bg-red-900/90 px-4 py-2 text-sm text-red-200">
                <span x-text="connectionError"></span>
                <button type="button" @click="connectionError = null" class="ml-2 text-red-400 hover:text-white">&times;</button>
            </div>

            {{-- Left column: Monitors + Sources + Scenes + Controls --}}
            <div class="flex flex-1 flex-col overflow-hidden">
                {{-- Header bar --}}
                <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-900 px-4 py-2">
                    <div class="flex items-center gap-3">
                        <template x-if="broadcasting">
                            <span class="relative flex size-3">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex size-3 rounded-full bg-red-500"></span>
                            </span>
                        </template>
                        <template x-if="!broadcasting">
                            <span class="flex size-3 rounded-full bg-yellow-500"></span>
                        </template>
                        <flux:heading size="sm" class="text-white">{{ $event->title }}</flux:heading>
                        <span class="rounded px-2 py-0.5 text-xs font-bold"
                              :class="broadcasting ? 'bg-red-600 text-white' : 'bg-yellow-500 text-black'"
                              x-text="broadcasting ? 'BROADCASTING' : 'SETUP'"></span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="flex items-center gap-1 text-sm text-zinc-400">
                            <flux:icon name="eye" variant="micro" />
                            <span x-text="viewerCount">{{ $viewerCount }}</span> {{ __('viewers') }}
                        </span>
                        @if ($event->started_at)
                            <span class="text-sm font-mono text-zinc-400"
                                x-data="{ elapsed: '' }"
                                x-init="
                                    const started = new Date(@js($event->started_at->toIso8601String()));
                                    setInterval(() => {
                                        const diff = Math.floor((Date.now() - started) / 1000);
                                        const h = Math.floor(diff / 3600);
                                        const m = Math.floor((diff % 3600) / 60);
                                        const s = diff % 60;
                                        elapsed = (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                                    }, 1000);
                                "
                                x-text="elapsed"
                            ></span>
                        @endif
                        <a href="{{ route('live.show', $event->slug) }}" target="_blank" class="text-xs text-blue-400 hover:text-blue-300 underline">{{ __('Public Page') }}</a>
                    </div>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- DUAL MONITORS: PREVIEW + PROGRAM --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="flex gap-2 bg-black p-2" style="max-height: 45vh;" wire:ignore>
                    {{-- Preview Monitor --}}
                    <div class="relative flex-1 rounded-lg border-2 bg-black"
                         :class="editorMode ? 'border-green-400' : 'border-green-500/40'"
                         style="aspect-ratio: 16/9;">
                        <canvas x-ref="previewCanvas" class="absolute inset-0 w-full h-full" width="1280" height="720"></canvas>
                        {{-- Editor handles layer — exact same size as canvas, on top --}}
                        <div x-ref="editorHandles"
                             class="absolute inset-0 z-10"
                             :style="editorMode ? 'pointer-events: auto;' : 'pointer-events: none; display: none;'"></div>
                        <span class="absolute top-2 left-2 rounded px-2 py-0.5 text-[10px] font-bold text-white tracking-wider z-20"
                              :class="editorMode ? 'bg-yellow-500' : 'bg-green-600/90'"
                              x-text="editorMode ? 'EDITOR' : 'PREVIEW'"></span>
                        <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px] text-zinc-300 z-20"
                              x-text="scenes[previewSceneIndex]?.name ?? 'Solo'"></span>
                        {{-- Edit mode toggle --}}
                        <button type="button" @click="toggleEditor()"
                                class="absolute bottom-2 right-2 rounded px-2 py-1 text-[10px] font-medium transition-colors z-20"
                                :class="editorMode ? 'bg-yellow-500 text-black hover:bg-yellow-400' : 'bg-zinc-700/80 text-zinc-300 hover:bg-zinc-600'">
                            <span x-text="editorMode ? 'Done Editing' : 'Edit Layout'"></span>
                        </button>
                    </div>

                    {{-- Program Monitor --}}
                    <div class="relative flex-1 overflow-hidden rounded-lg border-2 border-red-500/40 bg-black">
                        <canvas x-ref="programCanvas" class="h-full w-full object-contain" width="1280" height="720"></canvas>
                        <span class="absolute top-2 left-2 rounded bg-red-600/90 px-2 py-0.5 text-[10px] font-bold text-white tracking-wider">PROGRAM</span>
                        <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px] text-zinc-300" x-text="scenes[liveSceneIndex]?.name ?? 'Solo'"></span>
                    </div>
                </div>

                {{-- Hidden container for SourceManager video elements --}}
                <div x-ref="sourceContainer" class="sr-only" aria-hidden="true" wire:ignore></div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- SOURCE MONITORS --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="border-t border-zinc-700 bg-zinc-950 px-3 py-2 overflow-x-auto" wire:ignore>
                    <div class="flex items-center gap-1 mb-1.5">
                        <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Sources') }}</span>
                    </div>
                    <div class="flex items-start gap-2" x-ref="sourceMonitors">
                        <template x-for="source in sources" :key="source.key">
                            <div class="shrink-0 w-36">
                                <div class="relative overflow-hidden rounded border-2 bg-zinc-800 transition-colors"
                                     style="aspect-ratio: 16/9;"
                                     :class="source.state === 'live' ? 'border-green-500/60' : 'border-zinc-700'"
                                     :id="'source-thumb-' + source.key"
                                     x-init="$nextTick(() => renderSourceThumb(source.key))"
                                >
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-[10px] text-zinc-600" x-text="source.name"></span>
                                    </div>
                                </div>
                                <div class="mt-1 flex items-center justify-between px-0.5">
                                    <span class="text-[10px] font-semibold text-zinc-300" x-text="source.name"></span>
                                    <span x-show="source.state === 'live'" x-cloak class="size-2 rounded-full bg-green-500"></span>
                                </div>
                            </div>
                        </template>

                        {{-- Add Video URL button --}}
                        <div class="shrink-0 w-36">
                            <button type="button" @click="$flux.modal('add-video-modal').show()"
                                class="relative w-full overflow-hidden rounded border-2 border-dashed border-zinc-600 bg-zinc-800/50 hover:border-zinc-500 hover:bg-zinc-800 transition-colors cursor-pointer"
                                style="aspect-ratio: 16/9;">
                                <div class="absolute inset-0 flex flex-col items-center justify-center gap-1">
                                    <flux:icon name="play-circle" class="size-5 text-zinc-500" />
                                    <span class="text-[9px] text-zinc-500">{{ __('Add Video') }}</span>
                                </div>
                            </button>
                        </div>

                        {{-- Empty state --}}
                        <div x-show="sources.length === 0" class="shrink-0 w-36">
                            <div class="relative overflow-hidden rounded border-2 border-dashed border-zinc-700 bg-zinc-900" style="aspect-ratio: 16/9;">
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-[9px] text-zinc-600">{{ __('No sources') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- SCENE SWITCHER + TAKE --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="flex items-center gap-1.5 border-t border-zinc-700 bg-zinc-900 px-3 py-2">
                    <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Scenes') }}</span>
                    @foreach ($scenes as $index => $scene)
                        <button
                            type="button"
                            @click="previewScene({{ $index }})"
                            class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                            :class="{
                                'ring-2 ring-red-500 bg-red-600/20 text-red-300': liveSceneIndex === {{ $index }},
                                'ring-2 ring-green-500 bg-green-600/20 text-green-300': previewSceneIndex === {{ $index }} && liveSceneIndex !== {{ $index }},
                                'bg-zinc-700 text-zinc-300 hover:bg-zinc-600': previewSceneIndex !== {{ $index }} && liveSceneIndex !== {{ $index }},
                            }"
                        >
                            {{ $scene['name'] }}
                        </button>
                    @endforeach

                    <div class="mx-2 h-5 w-px bg-zinc-700"></div>

                    {{-- TAKE button --}}
                    <button
                        type="button"
                        @click="take()"
                        class="rounded bg-red-600 px-6 py-1.5 text-xs font-bold uppercase tracking-wider text-white transition-colors hover:bg-red-500 disabled:opacity-40"
                        :disabled="previewSceneIndex === liveSceneIndex"
                    >
                        {{ __('TAKE') }}
                    </button>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- VERSION TABS --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="flex items-center gap-1.5 border-t border-zinc-700 bg-zinc-850 px-3 py-1.5" wire:ignore>
                    <span class="mr-1 text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Versions') }}</span>
                    <template x-for="(scene, idx) in scenes" :key="idx">
                        <button type="button"
                                @click="previewScene(idx)"
                                class="rounded px-2.5 py-1 text-[10px] font-medium transition-colors truncate max-w-[120px]"
                                :class="{
                                    'ring-1 ring-red-500 bg-red-600/20 text-red-300': liveSceneIndex === idx,
                                    'ring-1 ring-green-500 bg-green-600/20 text-green-300': previewSceneIndex === idx && liveSceneIndex !== idx,
                                    'bg-zinc-700 text-zinc-400 hover:bg-zinc-600 hover:text-zinc-300': previewSceneIndex !== idx && liveSceneIndex !== idx,
                                }"
                                x-text="scene.name || ('Version ' + (idx + 1))">
                        </button>
                    </template>
                    <button type="button" @click="addVersion()" class="rounded bg-zinc-700 px-2 py-1 text-[10px] text-zinc-400 hover:bg-zinc-600">+ New</button>
                    <button type="button" @click="cloneVersion()" class="rounded bg-zinc-700 px-2 py-1 text-[10px] text-zinc-400 hover:bg-zinc-600">Clone</button>
                    <button type="button" @click="deleteVersion()"
                            x-show="scenes.length > 1 && previewSceneIndex !== liveSceneIndex"
                            class="rounded bg-zinc-700 px-2 py-1 text-[10px] text-red-400 hover:bg-red-600/30">Delete</button>
                    <div class="mx-1 h-4 w-px bg-zinc-700"></div>
                    <button type="button" @click="$flux.modal('live-template-modal').show()" class="rounded bg-zinc-700 px-2 py-1 text-[10px] text-blue-400 hover:bg-zinc-600">Templates</button>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- SCENE CONFIG — Layout Picker + Source Assignment --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="flex flex-wrap items-center gap-2 border-t border-zinc-700 bg-zinc-850 px-3 py-2" wire:ignore>
                    <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Layout') }}</span>
                    <select
                        x-model="currentLayoutKey"
                        @change="changeLayout(currentLayoutKey)"
                        class="rounded bg-zinc-700 px-2 py-1 text-xs text-zinc-200 border-zinc-600 focus:border-green-500 focus:ring-green-500"
                    >
                        <template x-for="layout in availableLayouts" :key="layout.key">
                            <option :value="layout.key" x-text="layout.name"></option>
                        </template>
                    </select>

                    <div class="mx-1 h-4 w-px bg-zinc-700"></div>

                    <template x-for="(slot, slotIdx) in currentLayoutSlots" :key="slotIdx">
                        <div class="flex items-center gap-1">
                            <span class="text-[10px] font-medium text-zinc-400" x-text="'Slot ' + slot.label + ':'"></span>
                            <select
                                :value="previewSlotSources[slotIdx] ?? ''"
                                @change="assignSlotSource(slotIdx, $event.target.value)"
                                class="rounded bg-zinc-700 px-2 py-1 text-xs text-zinc-200 border-zinc-600 focus:border-green-500 focus:ring-green-500"
                            >
                                <option value="">{{ __('(none)') }}</option>
                                <template x-for="source in sources" :key="source.key">
                                    <option :value="source.key" x-text="source.name"></option>
                                </template>
                            </select>
                        </div>
                    </template>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- OVERLAY PANEL --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="border-t border-zinc-700 bg-zinc-800 px-3 py-2" wire:ignore>
                    <div class="flex items-center gap-1 mb-1.5">
                        <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Overlays') }}</span>
                    </div>
                    {{-- List of overlays with toggle/edit/remove --}}
                    <template x-for="(overlay, idx) in currentOverlays" :key="idx">
                        <div class="flex items-center gap-2 py-1">
                            <button @click="toggleOverlayVisibility(idx)"
                                    class="size-3 rounded-full shrink-0"
                                    :class="overlay.visible !== false ? 'bg-green-500' : 'bg-zinc-600'"></button>
                            <span class="text-xs text-zinc-300 truncate flex-1" x-text="overlayLabel(overlay)"></span>
                            <button @click="editOverlay(idx)" class="text-xs text-zinc-500 hover:text-zinc-300">{{ __('Edit') }}</button>
                            <button @click="removeOverlay(idx)" class="text-xs text-zinc-500 hover:text-red-400">&times;</button>
                        </div>
                    </template>
                    <div x-show="currentOverlays.length === 0" class="py-1">
                        <span class="text-[10px] text-zinc-600">{{ __('No overlays. Add one below.') }}</span>
                    </div>
                    {{-- Add buttons --}}
                    <div class="flex gap-1 mt-1.5">
                        <button @click="showAddOverlay('text')" class="text-[10px] px-2 py-1 rounded bg-zinc-700 text-zinc-400 hover:bg-zinc-600">+ {{ __('Text') }}</button>
                        <button @click="showAddOverlay('image')" class="text-[10px] px-2 py-1 rounded bg-zinc-700 text-zinc-400 hover:bg-zinc-600">+ {{ __('Image') }}</button>
                        <button @click="showAddOverlay('html')" class="text-[10px] px-2 py-1 rounded bg-zinc-700 text-zinc-400 hover:bg-zinc-600">+ {{ __('HTML') }}</button>
                        <button @click="showAddOverlay('caption')" class="text-[10px] px-2 py-1 rounded bg-zinc-700 text-zinc-400 hover:bg-zinc-600">+ {{ __('Caption') }}</button>
                    </div>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- PROPERTIES PANEL (visible when item selected in editor) --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div x-show="editorMode && selectedItem" x-cloak
                     class="border-t border-zinc-700 bg-zinc-800 px-3 py-2" wire:ignore>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">Properties</span>
                        <span class="text-[10px] text-zinc-400" x-text="selectedItemLabel"></span>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        {{-- Source dropdown (slots only) --}}
                        <template x-if="selectedItem?.type === 'slot'">
                            <div class="flex items-center gap-1">
                                <span class="text-[10px] text-zinc-500">Source:</span>
                                <select @change="updateSelectedProperty('source', $event.target.value)"
                                        class="rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                                    <option value="">(none)</option>
                                    <template x-for="source in sources" :key="source.key">
                                        <option :value="source.key" :selected="getSelectedProperty('source') === source.key" x-text="source.name"></option>
                                    </template>
                                </select>
                            </div>
                        </template>
                        {{-- Position/Size --}}
                        <div class="flex items-center gap-1">
                            <span class="text-[10px] text-zinc-500">X:</span>
                            <input type="number" min="0" max="100" step="1"
                                   :value="Math.round((getSelectedProperty('x') ?? 0) * 100)"
                                   @change="updateSelectedProperty('x', $event.target.value / 100)"
                                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-[10px] text-zinc-500">Y:</span>
                            <input type="number" min="0" max="100" step="1"
                                   :value="Math.round((getSelectedProperty('y') ?? 0) * 100)"
                                   @change="updateSelectedProperty('y', $event.target.value / 100)"
                                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-[10px] text-zinc-500">W:</span>
                            <input type="number" min="1" max="100" step="1"
                                   :value="Math.round((getSelectedProperty('w') ?? 50) * 100)"
                                   @change="updateSelectedProperty('w', $event.target.value / 100)"
                                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-[10px] text-zinc-500">H:</span>
                            <input type="number" min="1" max="100" step="1"
                                   :value="Math.round((getSelectedProperty('h') ?? 50) * 100)"
                                   @change="updateSelectedProperty('h', $event.target.value / 100)"
                                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                        </div>
                        {{-- Border Radius (slots) --}}
                        <template x-if="selectedItem?.type === 'slot'">
                            <div class="flex items-center gap-1">
                                <span class="text-[10px] text-zinc-500">Radius:</span>
                                <input type="number" min="0" max="50" step="1"
                                       :value="getSelectedProperty('borderRadius') ?? 0"
                                       @change="updateSelectedProperty('borderRadius', parseInt($event.target.value))"
                                       class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                                <span class="text-[10px] text-zinc-600">px</span>
                            </div>
                        </template>
                        {{-- Crop/Zoom (slots) --}}
                        <template x-if="selectedItem?.type === 'slot'">
                            <div class="flex items-center gap-2">
                                <div class="flex items-center gap-1">
                                    <span class="text-[10px] text-zinc-500">Zoom:</span>
                                    <input type="range" min="1" max="4" step="0.1"
                                           :value="getSelectedProperty('cropZoom') ?? 1"
                                           @input="updateSelectedProperty('cropZoom', parseFloat($event.target.value))"
                                           class="w-16 h-1 accent-green-500">
                                    <span class="text-[10px] text-zinc-400" x-text="(getSelectedProperty('cropZoom') ?? 1).toFixed(1) + 'x'"></span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="text-[10px] text-zinc-500">Pan X:</span>
                                    <input type="range" min="-50" max="50" step="1"
                                           :value="Math.round((getSelectedProperty('cropX') ?? 0) * 100)"
                                           @input="updateSelectedProperty('cropX', $event.target.value / 100)"
                                           class="w-14 h-1 accent-green-500">
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="text-[10px] text-zinc-500">Pan Y:</span>
                                    <input type="range" min="-50" max="50" step="1"
                                           :value="Math.round((getSelectedProperty('cropY') ?? 0) * 100)"
                                           @input="updateSelectedProperty('cropY', $event.target.value / 100)"
                                           class="w-14 h-1 accent-green-500">
                                </div>
                            </div>
                        </template>
                        {{-- Content (text overlays) --}}
                        <template x-if="selectedItem?.type === 'overlay' && getSelectedOverlay()?.type === 'text'">
                            <div class="flex items-center gap-1">
                                <span class="text-[10px] text-zinc-500">Text:</span>
                                <input type="text"
                                       :value="getSelectedProperty('content') ?? ''"
                                       @input="updateSelectedProperty('content', $event.target.value)"
                                       class="w-40 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Hidden container for OverlayRenderer DOM elements --}}
                <div x-ref="overlayRenderContainer" class="sr-only" aria-hidden="true" wire:ignore></div>

                {{-- Guest Controls + Invite (Alpine-rendered for real-time updates) --}}
                <div class="flex items-center gap-2 overflow-x-auto border-t border-zinc-700 bg-zinc-800 px-3 py-2" wire:ignore>
                    <span class="mr-1 shrink-0 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Guests') }}</span>
                    <template x-for="guest in liveGuests" :key="guest.id">
                        <div class="flex shrink-0 items-center gap-1.5 rounded-lg bg-zinc-900 px-2.5 py-1.5">
                            <span class="text-xs font-medium text-zinc-300" x-text="guest.name"></span>
                            <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset"
                                :class="{
                                    'bg-amber-400/10 text-amber-400 ring-amber-400/20': guest.status === 'connected',
                                    'bg-green-400/10 text-green-400 ring-green-400/20': guest.status === 'live',
                                    'bg-zinc-400/10 text-zinc-400 ring-zinc-400/20': guest.status === 'disconnected' || guest.status === 'invited',
                                }"
                                x-text="guest.status.charAt(0).toUpperCase() + guest.status.slice(1)">
                            </span>
                            <button x-show="guest.status === 'connected'" type="button"
                                @click="$wire.bringGuestOn(guest.id)"
                                class="rounded-md bg-blue-600 px-2 py-1 text-[10px] font-medium text-white hover:bg-blue-500 transition-colors">
                                {{ __('Bring On') }}
                            </button>
                            <button x-show="guest.status === 'live'" type="button"
                                @click="$wire.takeGuestOff(guest.id)"
                                class="rounded-md bg-zinc-700 px-2 py-1 text-[10px] font-medium text-zinc-300 hover:bg-zinc-600 transition-colors">
                                {{ __('Take Off') }}
                            </button>
                            <button type="button" @click="$wire.copyGuestLink(guest.id)"
                                class="rounded-md bg-zinc-700 p-1 text-zinc-400 hover:text-zinc-200 transition-colors" title="{{ __('Copy link') }}">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9.75a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" /></svg>
                            </button>
                        </div>
                    </template>
                    <template x-if="liveGuests.length === 0">
                        <span class="text-[10px] text-zinc-600">{{ __('No guests') }}</span>
                    </template>
                    <button type="button" wire:click="refreshGuestStatuses"
                        class="shrink-0 rounded-md bg-zinc-700 p-1.5 text-zinc-400 hover:text-zinc-200 transition-colors" title="{{ __('Refresh') }}">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" /></svg>
                    </button>

                    {{-- Quick invite --}}
                    <div class="mx-1 h-4 w-px bg-zinc-700 shrink-0"></div>
                    <form wire:submit="inviteGuest" class="flex shrink-0 items-center gap-1.5">
                        <input type="text" wire:model="guestName" placeholder="{{ __('Guest name...') }}"
                               class="w-28 rounded bg-zinc-700 px-2 py-1 text-xs text-zinc-200 border-zinc-600 placeholder-zinc-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none" />
                        <input type="email" wire:model="guestEmail" placeholder="{{ __('Email (optional)') }}"
                               class="w-36 rounded bg-zinc-700 px-2 py-1 text-xs text-zinc-200 border-zinc-600 placeholder-zinc-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none" />
                        <flux:button type="submit" size="xs" variant="primary" icon="plus" wire:loading.attr="disabled">
                            {{ __('Invite') }}
                        </flux:button>
                    </form>
                </div>

                {{-- Controls bar --}}
                <div class="flex items-center justify-center gap-3 border-t border-zinc-700 bg-zinc-900 px-4 py-3">
                    {{-- Mic toggle (host only) --}}
                    <template x-if="isHost">
                        <button type="button" @click="toggleAudio()"
                            class="flex size-11 items-center justify-center rounded-full transition-colors"
                            :class="audioEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                        >
                            <flux:icon name="microphone" class="size-5" />
                        </button>
                    </template>

                    {{-- Camera toggle (host only) --}}
                    <template x-if="isHost">
                        <button type="button" @click="toggleVideo()"
                            class="flex size-11 items-center justify-center rounded-full transition-colors"
                            :class="videoEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                        >
                            <flux:icon name="video-camera" class="size-5" />
                        </button>
                    </template>

                    {{-- Screen share (host only) --}}
                    <template x-if="isHost">
                        <button type="button" @click="toggleScreenShare()"
                            class="flex size-11 items-center justify-center rounded-full transition-colors"
                            :class="screenStream ? 'bg-purple-600 text-white' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'"
                        >
                            <flux:icon name="computer-desktop" class="size-5" />
                        </button>
                    </template>

                    <div class="mx-2 h-6 w-px bg-zinc-700"></div>

                    {{-- Start Broadcasting / End Stream (host only) --}}
                    <template x-if="isHost && !broadcasting">
                        <button type="button" @click="startBroadcasting()"
                            class="flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-red-500">
                            <span class="relative flex size-3">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-white/60"></span>
                                <span class="relative inline-flex size-3 rounded-full bg-white"></span>
                            </span>
                            {{ __('Start Broadcasting') }}
                        </button>
                    </template>
                    <template x-if="isHost && broadcasting">
                        <flux:button wire:click="endStream"
                            wire:confirm="{{ __('Are you sure you want to end the stream?') }}"
                            variant="danger" size="sm" icon="x-mark">
                            {{ __('End Stream') }}
                        </flux:button>
                    </template>
                    {{-- Co-producer badge --}}
                    <template x-if="!isHost">
                        <span class="rounded-lg bg-blue-600/20 px-3 py-1.5 text-xs font-medium text-blue-400">
                            {{ __('Co-Producer') }}
                        </span>
                    </template>

                    <div class="mx-2 h-6 w-px bg-zinc-700"></div>

                    {{-- RTMP Ingress --}}
                    <button type="button" @click="$flux.modal('rtmp-ingress-modal').show()"
                        class="flex items-center gap-1.5 rounded-lg bg-zinc-700 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:bg-zinc-600">
                        <flux:icon name="arrow-down-tray" class="size-4" />
                        {{ __('RTMP In') }}
                    </button>

                    {{-- RTMP Simulcast --}}
                    <template x-if="isHost">
                        <button type="button" @click="$flux.modal('rtmp-simulcast-modal').show()"
                            class="flex items-center gap-1.5 rounded-lg bg-zinc-700 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:bg-zinc-600">
                            <flux:icon name="arrow-up-tray" class="size-4" />
                            {{ __('Simulcast') }}
                        </button>
                    </template>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- ADD VIDEO URL MODAL --}}
                {{-- ═══════════════════════════════════════════ --}}
                <flux:modal name="add-video-modal" class="w-full max-w-md">
                    <div class="space-y-4" x-data="{ videoUrl: '', videoName: '' }">
                        <flux:heading size="lg">{{ __('Add Video Source') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-400">{{ __('Enter a video URL (MP4, WebM, HLS) to add as a source.') }}</flux:text>

                        <div class="space-y-3">
                            <flux:input x-model="videoName" placeholder="{{ __('Source name (e.g., Intro Video)') }}" size="sm" />
                            <flux:input x-model="videoUrl" placeholder="{{ __('https://example.com/video.mp4') }}" size="sm" />
                        </div>

                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" variant="ghost" @click="$flux.modal('add-video-modal').close()">{{ __('Cancel') }}</flux:button>
                            <flux:button size="sm" variant="primary" @click="addVideoSource(videoUrl, videoName || 'Video'); videoUrl = ''; videoName = ''; $flux.modal('add-video-modal').close()">
                                {{ __('Add Video') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- RTMP SIMULCAST MODAL --}}
                {{-- ═══════════════════════════════════════════ --}}
                <flux:modal name="rtmp-simulcast-modal" class="w-full max-w-lg">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('Simulcast Destinations') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-400">{{ __('Forward your broadcast to YouTube Live, Facebook Live, Twitch, etc.') }}</flux:text>

                        {{-- Active destinations --}}
                        <template x-if="rtmpDestinations.length > 0">
                            <div class="space-y-2">
                                <template x-for="(dest, idx) in rtmpDestinations" :key="idx">
                                    <div class="flex items-center justify-between rounded-lg bg-zinc-800 px-3 py-2">
                                        <div class="min-w-0">
                                            <span class="text-sm font-medium text-zinc-200 block truncate" x-text="dest.name"></span>
                                            <span class="text-xs text-zinc-500 block truncate" x-text="dest.url.substring(0, 40) + '...'"></span>
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0 ml-2">
                                            <template x-if="dest.active">
                                                <span class="flex items-center gap-1 text-xs text-green-400">
                                                    <span class="size-2 rounded-full bg-green-500 animate-pulse"></span> Live
                                                </span>
                                            </template>
                                            <template x-if="!dest.active && broadcasting">
                                                <flux:button size="xs" variant="primary" @click="startSimulcast(idx)">{{ __('Start') }}</flux:button>
                                            </template>
                                            <template x-if="dest.active">
                                                <flux:button size="xs" variant="danger" @click="stopSimulcast(idx)">{{ __('Stop') }}</flux:button>
                                            </template>
                                            <flux:button size="xs" variant="ghost" icon="trash" @click="removeRtmpDestination(idx)" />
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Add new destination --}}
                        <div class="border-t border-zinc-700 pt-4 space-y-3" x-data="{ destName: '', destUrl: '' }">
                            <flux:heading size="sm">{{ __('Add Destination') }}</flux:heading>
                            <flux:input x-model="destName" placeholder="{{ __('Name (e.g., YouTube Live)') }}" size="sm" />
                            <flux:input x-model="destUrl" placeholder="{{ __('rtmp://a.rtmp.youtube.com/live2/xxxx-xxxx') }}" size="sm" />
                            <flux:button size="sm" variant="primary" class="w-full"
                                @click="addRtmpDestination(destName || 'RTMP', destUrl); destName = ''; destUrl = ''">
                                {{ __('Add') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- RTMP INGRESS MODAL --}}
                {{-- ═══════════════════════════════════════════ --}}
                <flux:modal name="rtmp-ingress-modal" class="w-full max-w-lg">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('RTMP Ingest') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-400">{{ __('Send an RTMP stream from OBS or another tool into your studio.') }}</flux:text>

                        <template x-if="ingressInfo">
                            <div class="space-y-3">
                                <div class="rounded-lg bg-zinc-800 p-3">
                                    <label class="block text-xs font-medium text-zinc-400 mb-1">{{ __('RTMP URL') }}</label>
                                    <div class="flex items-center gap-2">
                                        <code class="flex-1 rounded bg-zinc-900 px-2 py-1 text-xs text-zinc-200 truncate" x-text="ingressInfo.url"></code>
                                        <button type="button" @click="navigator.clipboard.writeText(ingressInfo.url)" class="text-xs text-blue-400 hover:text-blue-300 shrink-0">{{ __('Copy') }}</button>
                                    </div>
                                </div>
                                <div class="rounded-lg bg-zinc-800 p-3">
                                    <label class="block text-xs font-medium text-zinc-400 mb-1">{{ __('Stream Key') }}</label>
                                    <div class="flex items-center gap-2">
                                        <code class="flex-1 rounded bg-zinc-900 px-2 py-1 text-xs text-zinc-200 truncate" x-text="ingressInfo.streamKey"></code>
                                        <button type="button" @click="navigator.clipboard.writeText(ingressInfo.streamKey)" class="text-xs text-blue-400 hover:text-blue-300 shrink-0">{{ __('Copy') }}</button>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="size-2 rounded-full" :class="ingressInfo.active ? 'bg-green-500 animate-pulse' : 'bg-zinc-600'"></span>
                                    <span class="text-xs" :class="ingressInfo.active ? 'text-green-400' : 'text-zinc-500'" x-text="ingressInfo.active ? 'Receiving stream' : 'Waiting for stream...'"></span>
                                </div>
                            </div>
                        </template>

                        <template x-if="!ingressInfo">
                            <flux:button size="sm" variant="primary" class="w-full" @click="createIngress()" wire:loading.attr="disabled">
                                {{ __('Generate RTMP Endpoint') }}
                            </flux:button>
                        </template>

                        <template x-if="ingressInfo">
                            <flux:button size="sm" variant="danger" class="w-full" @click="deleteIngress()">
                                {{ __('Remove RTMP Endpoint') }}
                            </flux:button>
                        </template>
                    </div>
                </flux:modal>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- TEMPLATE MODAL --}}
                {{-- ═══════════════════════════════════════════ --}}
                <flux:modal name="live-template-modal" class="w-full max-w-md">
                    <div class="space-y-4" x-data="{ templates: @js($this->sceneTemplates->toArray()) }"
                         x-init="
                            $wire.on('template-saved', ({ id, name }) => {
                                templates.push({ id, name });
                            });
                            $wire.on('template-deleted', ({ id }) => {
                                templates = templates.filter(t => t.id !== id);
                            });
                         ">
                        <flux:heading size="lg">{{ __('Scene Templates') }}</flux:heading>

                        {{-- Template list --}}
                        <template x-if="templates.length > 0">
                            <div class="space-y-2 max-h-60 overflow-y-auto">
                                <template x-for="template in templates" :key="template.id">
                                    <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300 truncate" x-text="template.name"></span>
                                        <div class="flex items-center gap-1 shrink-0 ml-2">
                                            <flux:button size="xs" variant="primary"
                                                @click="loadTemplate(template.id); $flux.modal('live-template-modal').close()">
                                                {{ __('Load') }}
                                            </flux:button>
                                            <flux:button size="xs" variant="ghost" icon="trash"
                                                @click="if (confirm('{{ __('Delete this template?') }}')) $wire.deleteTemplate(template.id)" />
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="templates.length === 0">
                            <flux:text class="text-sm text-zinc-400">{{ __('No saved templates yet.') }}</flux:text>
                        </template>

                        {{-- Save current --}}
                        <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <flux:heading size="sm" class="mb-2">{{ __('Save Current Scenes') }}</flux:heading>
                            <div class="flex gap-2">
                                <flux:input wire:model="templateName" placeholder="{{ __('Template name...') }}" size="sm" class="flex-1" />
                                <flux:button size="sm" variant="primary" @click="saveTemplate(); $flux.modal('live-template-modal').close()">
                                    {{ __('Save') }}
                                </flux:button>
                            </div>
                            @error('templateName') <span class="mt-1 text-xs text-red-500">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </flux:modal>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- OVERLAY MODALS --}}
                {{-- ═══════════════════════════════════════════ --}}

                {{-- Text Overlay Modal --}}
                <flux:modal name="overlay-text-modal" class="w-full max-w-md">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('Text Overlay') }}</flux:heading>
                        <flux:textarea x-model="overlayForm.content" label="{{ __('Content') }}" placeholder="{{ __('Enter text...') }}" rows="2" />
                        <flux:select x-model="overlayForm.style" label="{{ __('Style') }}">
                            <flux:select.option value="lower-third">{{ __('Lower Third') }}</flux:select.option>
                            <flux:select.option value="title">{{ __('Title') }}</flux:select.option>
                            <flux:select.option value="ticker">{{ __('Ticker') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Custom') }}</flux:select.option>
                        </flux:select>
                        <flux:select x-model="overlayForm.position" label="{{ __('Position') }}">
                            <flux:select.option value="top-left">{{ __('Top Left') }}</flux:select.option>
                            <flux:select.option value="top-center">{{ __('Top Center') }}</flux:select.option>
                            <flux:select.option value="top-right">{{ __('Top Right') }}</flux:select.option>
                            <flux:select.option value="center">{{ __('Center') }}</flux:select.option>
                            <flux:select.option value="bottom-left">{{ __('Bottom Left') }}</flux:select.option>
                            <flux:select.option value="bottom-center">{{ __('Bottom Center') }}</flux:select.option>
                            <flux:select.option value="bottom-right">{{ __('Bottom Right') }}</flux:select.option>
                            <flux:select.option value="bottom">{{ __('Full-Width Bottom') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Custom X/Y') }}</flux:select.option>
                        </flux:select>
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input x-model="overlayForm.color" label="{{ __('Text Color') }}" type="color" />
                            <flux:input x-model="overlayForm.bg" label="{{ __('Background') }}" type="color" />
                        </div>
                        <flux:input x-model="overlayForm.fontSize" label="{{ __('Font Size') }}" type="number" min="12" max="72" />
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" @click="$flux.modal('overlay-text-modal').close()">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" @click="saveOverlay(); $flux.modal('overlay-text-modal').close()">{{ __('Save') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>

                {{-- Image Overlay Modal --}}
                <flux:modal name="overlay-image-modal" class="w-full max-w-md">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('Image Overlay') }}</flux:heading>
                        <flux:input x-model="overlayForm.src" label="{{ __('Image URL') }}" placeholder="{{ __('https://... or /storage/...') }}" />
                        <flux:select x-model="overlayForm.position" label="{{ __('Position') }}">
                            <flux:select.option value="top-left">{{ __('Top Left') }}</flux:select.option>
                            <flux:select.option value="top-center">{{ __('Top Center') }}</flux:select.option>
                            <flux:select.option value="top-right">{{ __('Top Right') }}</flux:select.option>
                            <flux:select.option value="center">{{ __('Center') }}</flux:select.option>
                            <flux:select.option value="bottom-left">{{ __('Bottom Left') }}</flux:select.option>
                            <flux:select.option value="bottom-center">{{ __('Bottom Center') }}</flux:select.option>
                            <flux:select.option value="bottom-right">{{ __('Bottom Right') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Custom X/Y') }}</flux:select.option>
                        </flux:select>
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input x-model="overlayForm.w" label="{{ __('Width (0-1)') }}" type="number" min="0" max="1" step="0.01" />
                            <flux:input x-model="overlayForm.h" label="{{ __('Height (0-1)') }}" type="number" min="0" max="1" step="0.01" />
                        </div>
                        <flux:input x-model="overlayForm.opacity" label="{{ __('Opacity') }}" type="number" min="0" max="1" step="0.1" />
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" @click="$flux.modal('overlay-image-modal').close()">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" @click="saveOverlay(); $flux.modal('overlay-image-modal').close()">{{ __('Save') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>

                {{-- HTML Overlay Modal --}}
                <flux:modal name="overlay-html-modal" class="w-full max-w-md">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('HTML Overlay') }}</flux:heading>
                        <flux:select x-model="overlayForm.htmlType" label="{{ __('Type') }}">
                            <flux:select.option value="iframe">{{ __('Iframe URL') }}</flux:select.option>
                            <flux:select.option value="raw">{{ __('Raw HTML') }}</flux:select.option>
                        </flux:select>
                        <div x-show="overlayForm.htmlType === 'iframe'">
                            <flux:input x-model="overlayForm.src" label="{{ __('URL') }}" placeholder="{{ __('https://...') }}" />
                        </div>
                        <div x-show="overlayForm.htmlType === 'raw'">
                            <flux:textarea x-model="overlayForm.html" label="{{ __('HTML') }}" placeholder="{{ __('<div>...</div>') }}" rows="4" />
                        </div>
                        <flux:select x-model="overlayForm.position" label="{{ __('Position') }}">
                            <flux:select.option value="bottom">{{ __('Full-Width Bottom') }}</flux:select.option>
                            <flux:select.option value="top-left">{{ __('Top Left') }}</flux:select.option>
                            <flux:select.option value="top-center">{{ __('Top Center') }}</flux:select.option>
                            <flux:select.option value="center">{{ __('Center') }}</flux:select.option>
                            <flux:select.option value="bottom-center">{{ __('Bottom Center') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Custom X/Y') }}</flux:select.option>
                        </flux:select>
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input x-model="overlayForm.w" label="{{ __('Width (0-1)') }}" type="number" min="0" max="1" step="0.01" />
                            <flux:input x-model="overlayForm.h" label="{{ __('Height (0-1)') }}" type="number" min="0" max="1" step="0.01" />
                        </div>
                        <flux:input x-model="overlayForm.refreshInterval" label="{{ __('Refresh Interval (ms)') }}" type="number" min="500" max="10000" step="500" />
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" @click="$flux.modal('overlay-html-modal').close()">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" @click="saveOverlay(); $flux.modal('overlay-html-modal').close()">{{ __('Save') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>

                {{-- Caption Overlay Modal --}}
                <flux:modal name="overlay-caption-modal" class="w-full max-w-md">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('Caption Overlay') }}</flux:heading>
                        <flux:select x-model="overlayForm.position" label="{{ __('Position') }}">
                            <flux:select.option value="bottom-center">{{ __('Bottom Center') }}</flux:select.option>
                            <flux:select.option value="top-center">{{ __('Top Center') }}</flux:select.option>
                            <flux:select.option value="bottom-left">{{ __('Bottom Left') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Custom X/Y') }}</flux:select.option>
                        </flux:select>
                        <flux:select x-model="overlayForm.captionStyle" label="{{ __('Style') }}">
                            <flux:select.option value="white-on-black">{{ __('White on Black') }}</flux:select.option>
                            <flux:select.option value="yellow-on-transparent">{{ __('Yellow on Transparent') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Custom') }}</flux:select.option>
                        </flux:select>
                        <flux:input x-model="overlayForm.fontSize" label="{{ __('Font Size') }}" type="number" min="14" max="48" />
                        <flux:input x-model="overlayForm.maxLines" label="{{ __('Max Lines') }}" type="number" min="1" max="4" />
                        <div class="flex items-center gap-2">
                            <input type="checkbox" x-model="overlayForm.showSpeakerName" id="showSpeakerName" class="rounded border-zinc-300 text-blue-500" />
                            <label for="showSpeakerName" class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Show Speaker Name') }}</label>
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" @click="$flux.modal('overlay-caption-modal').close()">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" @click="saveOverlay(); $flux.modal('overlay-caption-modal').close()">{{ __('Save') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>
            </div>

            {{-- Right column: Tabbed sidebar (Chat, Q&A, Resources, Backstage) --}}
            <div class="flex w-full flex-col border-l border-zinc-200 dark:border-zinc-700 lg:w-96" x-data="{ activeTab: 'chat' }">
                {{-- Tab bar --}}
                <div class="flex border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <button type="button" @click="activeTab = 'chat'"
                        class="flex-1 px-3 py-2.5 text-xs font-medium transition-colors"
                        :class="activeTab === 'chat' ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    >
                        <flux:icon name="chat-bubble-oval-left" variant="micro" class="mr-1 inline size-3.5" />
                        {{ __('Chat') }}
                        @if ($this->chatMessages->isNotEmpty())
                            <flux:badge color="green" size="sm" class="ml-1">{{ $this->chatMessages->count() }}</flux:badge>
                        @endif
                    </button>
                    <button type="button" @click="activeTab = 'qa'"
                        class="flex-1 px-3 py-2.5 text-xs font-medium transition-colors"
                        :class="activeTab === 'qa' ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    >
                        <flux:icon name="question-mark-circle" variant="micro" class="mr-1 inline size-3.5" />
                        {{ __('Q&A') }}
                        @if ($this->pendingQuestions->isNotEmpty())
                            <flux:badge color="amber" size="sm" class="ml-1">{{ $this->pendingQuestions->count() }}</flux:badge>
                        @endif
                    </button>
                    <button type="button" @click="activeTab = 'resources'"
                        class="flex-1 px-3 py-2.5 text-xs font-medium transition-colors"
                        :class="activeTab === 'resources' ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    >
                        <flux:icon name="link" variant="micro" class="mr-1 inline size-3.5" />
                        {{ __('Resources') }}
                    </button>
                    <button type="button" @click="activeTab = 'backstage'"
                        class="flex-1 px-3 py-2.5 text-xs font-medium transition-colors"
                        :class="activeTab === 'backstage' ? 'border-b-2 border-amber-500 text-amber-600 dark:text-amber-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    >
                        <flux:icon name="chat-bubble-left-right" variant="micro" class="mr-1 inline size-3.5" />
                        {{ __('Backstage') }}
                    </button>
                </div>

                {{-- Tab content --}}
                <div class="flex-1 overflow-y-auto">
                    {{-- Chat Tab --}}
                    <div x-show="activeTab === 'chat'" class="flex h-full flex-col">
                        <div
                            id="live-chat-messages"
                            class="flex-1 overflow-y-auto px-4 py-2 space-y-1"
                            x-data
                            x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                            x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        >
                            @forelse ($this->chatMessages as $msg)
                                <div wire:key="chat-{{ $msg->id }}" class="text-sm">
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $msg->content }}</span>
                                </div>
                            @empty
                                <div class="flex items-center justify-center h-full">
                                    <flux:text class="text-sm text-zinc-400">{{ __('No messages yet. Chat will appear here.') }}</flux:text>
                                </div>
                            @endforelse
                        </div>

                        <form wire:submit="sendChatMessage" class="flex items-center gap-2 border-t border-zinc-200 px-4 py-2 dark:border-zinc-700">
                            <flux:input
                                wire:model="chatMessage"
                                placeholder="{{ __('Type a message...') }}"
                                size="sm"
                                class="flex-1"
                                maxlength="500"
                            />
                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" />
                        </form>
                    </div>

                    {{-- Q&A Tab --}}
                    <div x-show="activeTab === 'qa'" x-cloak class="p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <flux:heading size="sm">{{ __('Q&A Queue') }}</flux:heading>
                            @if ($this->pendingQuestions->isNotEmpty())
                                <flux:badge color="amber" size="sm">{{ $this->pendingQuestions->count() }} {{ __('pending') }}</flux:badge>
                            @endif
                        </div>

                        <div id="live-qa-panel">
                        @if ($this->pendingQuestions->isEmpty())
                            <div class="rounded-lg bg-zinc-50 p-4 text-center dark:bg-zinc-900">
                                <flux:icon name="chat-bubble-left-right" class="mx-auto mb-2 size-6 text-zinc-400" />
                                <flux:text class="text-sm">{{ __('No questions yet.') }}</flux:text>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($this->pendingQuestions as $question)
                                    <div wire:key="q-{{ $question->id }}" id="question-{{ $question->id }}" class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="text-xs font-medium text-amber-700 dark:text-amber-400">{{ $question->viewer_name }}</span>
                                            <span class="text-xs text-zinc-400">{{ $question->created_at->diffForHumans() }}</span>
                                        </div>
                                        <p class="mb-2 text-sm text-zinc-800 dark:text-zinc-200">{{ $question->question }}</p>
                                        <div class="flex items-center gap-2">
                                            <flux:button size="xs" variant="primary" icon="check" wire:click="answerQuestion({{ $question->id }})">
                                                {{ __('Answer') }}
                                            </flux:button>
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="dismissQuestion({{ $question->id }})">
                                                {{ __('Dismiss') }}
                                            </flux:button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        </div>{{-- /live-qa-panel --}}

                        {{-- Answered Questions (collapsed) --}}
                        <div class="mt-4" x-data="{ showAnswered: false }">
                            <button
                                type="button"
                                @click="showAnswered = !showAnswered"
                                class="flex w-full items-center justify-between text-left"
                            >
                                <flux:heading size="sm">{{ __('Answered') }} ({{ $this->answeredQuestions->count() }})</flux:heading>
                                <flux:icon
                                    name="chevron-down"
                                    variant="micro"
                                    class="size-4 text-zinc-400 transition-transform"
                                    ::class="showAnswered && 'rotate-180'"
                                />
                            </button>

                            <div x-show="showAnswered" x-collapse>
                                @if ($this->answeredQuestions->isEmpty())
                                    <flux:text class="mt-2 text-sm">{{ __('No answered questions yet.') }}</flux:text>
                                @else
                                    <div class="mt-3 space-y-2">
                                        @foreach ($this->answeredQuestions as $question)
                                            <div wire:key="aq-{{ $question->id }}" class="rounded-lg bg-green-50 p-3 dark:bg-green-900/20">
                                                <div class="mb-1 flex items-center justify-between">
                                                    <span class="text-xs font-medium text-green-700 dark:text-green-400">{{ $question->viewer_name }}</span>
                                                    <span class="text-xs text-zinc-400">{{ $question->answered_at?->diffForHumans() }}</span>
                                                </div>
                                                <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $question->question }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Resources Tab --}}
                    <div x-show="activeTab === 'resources'" x-cloak class="p-4">
                        <flux:heading size="sm" class="mb-3">{{ __('Resources') }}</flux:heading>

                        @if ($this->resources->isNotEmpty())
                            <div class="mb-3 space-y-2">
                                @foreach ($this->resources as $resource)
                                    <div wire:key="r-{{ $resource->id }}" class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-900">
                                        <div class="min-w-0 flex-1">
                                            <a href="{{ $resource->url }}" target="_blank" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400 truncate block">
                                                {{ $resource->title }}
                                            </a>
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0 ml-2">
                                            <flux:badge size="sm" color="zinc">{{ $resource->type }}</flux:badge>
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="removeResource({{ $resource->id }})"
                                                wire:confirm="{{ __('Remove this resource?') }}"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <form wire:submit="addResource" class="space-y-2">
                            <flux:input wire:model="newResourceTitle" placeholder="{{ __('Resource title') }}" size="sm" />
                            <flux:input wire:model="newResourceUrl" placeholder="{{ __('https://...') }}" size="sm" />
                            <div class="flex items-center gap-2">
                                <flux:select wire:model="newResourceType" size="sm" class="flex-1">
                                    <flux:select.option value="link">{{ __('Link') }}</flux:select.option>
                                    <flux:select.option value="document">{{ __('Document') }}</flux:select.option>
                                    <flux:select.option value="video">{{ __('Video') }}</flux:select.option>
                                </flux:select>
                                <flux:button type="submit" size="sm" variant="primary" icon="plus" wire:loading.attr="disabled">
                                    {{ __('Add') }}
                                </flux:button>
                            </div>
                            @error('newResourceTitle') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            @error('newResourceUrl') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                        </form>
                    </div>

                    {{-- Backstage Tab --}}
                    <div x-show="activeTab === 'backstage'" x-cloak class="flex h-full flex-col">
                        <div
                            id="live-backstage-messages"
                            class="flex-1 overflow-y-auto px-4 py-2 space-y-1"
                            x-data
                            x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        >
                            @forelse ($this->backstageMessages as $msg)
                                <div wire:key="bs-{{ $msg->id }}" class="text-sm">
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $msg->content }}</span>
                                </div>
                            @empty
                                <div class="flex items-center justify-center h-full">
                                    <flux:text class="text-sm text-zinc-400">{{ __('Private backstage chat with guests.') }}</flux:text>
                                </div>
                            @endforelse
                        </div>

                        <form wire:submit="sendBackstageMessage" class="flex items-center gap-2 border-t border-zinc-200 px-4 py-2 dark:border-zinc-700">
                            <flux:input
                                wire:model="backstageMessage"
                                placeholder="{{ __('Message guests...') }}"
                                size="sm"
                                class="flex-1"
                                maxlength="500"
                            />
                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" />
                        </form>
                    </div>
                </div>
            </div>
        </div>

        @script
        <script>
        Alpine.data('livekitProducer', () => {
            let room = null;
            let sourceManager = null;
            let previewCompositor = null;
            let programCompositor = null;
            let hostAudioTrack = null; // Separate reference for mute/unmute
            let overlayRenderer = null;
            let captionEngine = null;
            let canvasEditor = null;
            let guestAudioTracks = new Map();

            // Position preset lookup
            const POSITION_PRESETS = {
                'top-left':      { x: 0.02, y: 0.02 },
                'top-center':    { x: 0.5,  y: 0.02 },
                'top-right':     { x: 0.85, y: 0.02 },
                'center':        { x: 0.5,  y: 0.5 },
                'bottom-left':   { x: 0.02, y: 0.88 },
                'bottom-center': { x: 0.5,  y: 0.88 },
                'bottom-right':  { x: 0.85, y: 0.88 },
                'bottom':        { x: 0,    y: 0.92 },
                'custom':        { x: 0.5,  y: 0.5 },
            };

            return {
                isHost: false,
                connected: false,
                broadcasting: false,   // true when canvas is published to LiveKit
                audioEnabled: true,
                videoEnabled: true,
                viewerCount: 0,
                connectionError: null,
                sources: [],
                liveGuests: [],
                previewSceneIndex: 0,
                liveSceneIndex: 0,
                scenes: [],
                screenStream: null,
                rtmpDestinations: [],   // [{name, url, active, egressId}]
                ingressInfo: null,      // {url, streamKey, ingressId, active}
                _videoSources: [],      // Track video URL sources for cleanup

                get currentOverlays() {
                    return this.scenes[this.previewSceneIndex]?.overlays || [];
                },

                // Editor state
                editorMode: false,
                selectedItem: null,

                // Scene Config state
                currentLayoutKey: 'solo',
                previewSlotSources: {},
                availableLayouts: [
                    { key: 'solo', name: 'Solo', slots: [{label: 'A', x:0, y:0, w:1, h:1}] },
                    { key: 'interview', name: 'Interview', slots: [{label:'A', x:0, y:0, w:0.5, h:1}, {label:'B', x:0.5, y:0, w:0.5, h:1}] },
                    { key: 'panel-3up', name: 'Panel 3-Up', slots: [{label:'A', x:0, y:0, w:0.333, h:1}, {label:'B', x:0.333, y:0, w:0.334, h:1}, {label:'C', x:0.667, y:0, w:0.333, h:1}] },
                    { key: 'pip', name: 'PiP', slots: [{label:'A', x:0, y:0, w:1, h:1}, {label:'B', x:0.73, y:0.7, w:0.25, h:0.25}] },
                    { key: 'sidebar', name: 'Sidebar', slots: [{label:'A', x:0, y:0, w:0.7, h:1}, {label:'B', x:0.7, y:0, w:0.3, h:1}] },
                    { key: 'screen-speaker', name: 'Screen+Speaker', slots: [{label:'A', x:0, y:0, w:1, h:1}, {label:'B', x:0.75, y:0.72, w:0.23, h:0.25}] },
                ],
                get currentLayoutSlots() {
                    const layout = this.availableLayouts.find(l => l.key === this.currentLayoutKey);
                    return layout ? layout.slots : this.availableLayouts[0].slots;
                },

                // Overlay panel state
                addOverlayType: null,
                editOverlayIndex: null,
                overlayForm: {
                    type: '', content: '', style: 'lower-third', position: 'bottom-center',
                    color: '#ffffff', bg: '#000000', fontSize: 28, fontWeight: '700',
                    src: '', opacity: 0.8, w: 0.12, h: 0.08,
                    html: '', htmlType: 'iframe', refreshInterval: 2000,
                    captionStyle: 'white-on-black', maxLines: 2, showSpeakerName: true,
                },

                init() {
                    this.$wire.on('livekit-disconnect', () => this.disconnect());

                    this.$wire.on('guest-take-off', ({ identity, guestId }) => {
                        const slotKey = this._guestSlotKey(identity);
                        if (sourceManager) sourceManager.removeSource(slotKey);
                        this._updateGuestStatus(identity, 'connected');
                        console.log('[Studio] Guest taken off:', slotKey);
                        // No whisper needed — LiveKit API already revoked publish permission
                    });

                    // --- Renderless event listeners (real-time updates without re-render) ---

                    this.$wire.on('viewer-count-updated', ({ count }) => {
                        this.viewerCount = count;
                    });

                    this.$wire.on('chat-message-appended', ({ content, id }) => {
                        this._appendToChatPanel(content);
                    });

                    this.$wire.on('backstage-message-sent', ({ content }) => {
                        this._appendToBackstagePanel(content);
                    });

                    this.$wire.on('backstage-message-received', ({ content, createdAt }) => {
                        this._appendToBackstagePanel(content);
                    });

                    this.$wire.on('question-received', ({ question, viewerName, id }) => {
                        this._appendToQAPanel(question, viewerName, id);
                    });

                    this.$wire.on('question-updated', ({ questionId, action }) => {
                        const el = document.getElementById(`question-${questionId}`);
                        if (el) el.remove();
                    });

                    this.$wire.on('guests-updated', ({ guests }) => {
                        this.liveGuests = guests;
                    });

                    this.$wire.on('template-loaded', ({ scenes }) => {
                        this.scenes = scenes.map(s => ({ ...s, overlays: s.overlays || [] }));
                        this.previewSceneIndex = Math.min(this.previewSceneIndex, scenes.length - 1);
                        const scene = this.scenes[this.previewSceneIndex];
                        if (previewCompositor && scene) {
                            previewCompositor.setScene(scene);
                            previewCompositor.setOverlays(scene.overlays || []);
                        }
                        if (programCompositor && this.scenes[this.liveSceneIndex]) {
                            programCompositor.setScene(this.scenes[this.liveSceneIndex]);
                            programCompositor.setOverlays(this.scenes[this.liveSceneIndex].overlays || []);
                        }
                        if (canvasEditor && this.editorMode && scene) {
                            canvasEditor.setSlots(scene.slots || []);
                            canvasEditor.setOverlays(scene.overlays || []);
                            this.selectedItem = null;
                        }
                        console.log('[Studio] Template loaded:', scenes.length, 'scenes');
                    });
                },

                // --- DOM helpers for renderless updates ---

                _appendToChatPanel(content) {
                    const panel = document.getElementById('live-chat-messages');
                    if (!panel) return;
                    const div = document.createElement('div');
                    div.className = 'text-xs text-zinc-300';
                    div.textContent = content;
                    panel.appendChild(div);
                    panel.scrollTop = panel.scrollHeight;
                },

                _appendToBackstagePanel(content) {
                    const panel = document.getElementById('live-backstage-messages');
                    if (!panel) return;
                    const div = document.createElement('div');
                    div.className = 'text-sm text-zinc-300';
                    div.textContent = content;
                    panel.appendChild(div);
                    panel.scrollTop = panel.scrollHeight;
                },

                _appendToQAPanel(question, viewerName, id) {
                    const panel = document.getElementById('live-qa-panel');
                    if (!panel) return;
                    const div = document.createElement('div');
                    div.id = `question-${id}`;
                    div.className = 'flex items-start justify-between rounded-lg bg-zinc-800 px-3 py-2';
                    div.innerHTML = `
                        <div class="min-w-0">
                            <span class="text-xs text-zinc-500">${viewerName}</span>
                            <p class="text-sm text-zinc-200">${question}</p>
                        </div>
                        <div class="flex gap-1 shrink-0 ml-2">
                            <button type="button" wire:click="answerQuestion(${id})" class="text-[10px] px-2 py-1 rounded bg-green-600/20 text-green-400 hover:bg-green-600/30">Answer</button>
                            <button type="button" wire:click="dismissQuestion(${id})" class="text-[10px] px-2 py-1 rounded bg-zinc-700 text-zinc-400 hover:bg-zinc-600">Dismiss</button>
                        </div>`;
                    panel.prepend(div);
                },

                // --- Template helpers ---

                saveTemplate() {
                    const scene = this.scenes[this.previewSceneIndex];
                    if (scene) {
                        this.$wire.saveAsTemplate(scene);
                    }
                },

                loadTemplate(templateId) {
                    this.$wire.loadTemplate(parseInt(templateId));
                },

                // ==============================================================
                // Studio startup
                // ==============================================================

                /**
                 * Phase 1: Set up the studio locally (camera, compositors).
                 * Does NOT connect to LiveKit or start broadcasting.
                 * Call startBroadcasting() when ready to go live to viewers.
                 */
                async startStudio(livekitToken, livekitUrl, eventId) {
                    this.connectionError = null;
                    this._livekitToken = livekitToken;
                    this._livekitUrl = livekitUrl;
                    this._eventId = eventId;

                    // 1. Initialize SourceManager (required — fail hard if this breaks)
                    try {
                        const SourceManager = await window.loadSourceManager();
                        sourceManager = new SourceManager(this.$refs.sourceContainer);

                        const syncSources = () => {
                            this.sources = sourceManager.getAllSources();
                        };
                        sourceManager.on('source-added', syncSources);
                        sourceManager.on('source-ready', syncSources);
                        sourceManager.on('source-removed', syncSources);
                        sourceManager.on('source-lost', syncSources);
                        console.log('[Studio] SourceManager initialized');
                    } catch (err) {
                        console.error('[Studio] SourceManager failed:', err);
                        this.connectionError = 'Failed to initialize sources: ' + err.message;
                        return;
                    }

                    // 2. Get local camera + mic (host only — co-producers don't publish)
                    if (this.isHost) {
                        try {
                            const cameraStream = await navigator.mediaDevices.getUserMedia({
                                video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                                audio: { echoCancellation: true, noiseSuppression: true },
                            });

                            hostAudioTrack = cameraStream.getAudioTracks()[0];
                            const videoOnlyStream = new MediaStream(cameraStream.getVideoTracks());
                            sourceManager.addSource('host', videoOnlyStream, 'You (Host)');
                            console.log('[Studio] Host camera + mic acquired');
                        } catch (err) {
                            console.warn('[Studio] Camera/mic unavailable (non-fatal):', err.message);
                        }
                    } else {
                        console.log('[Studio] Co-producer mode — skipping camera/mic capture');
                    }

                    // 3. Initialize dual compositors (required for rendering)
                    try {
                        const FrameCompositor = await window.loadFrameCompositor();

                        previewCompositor = new FrameCompositor(this.$refs.previewCanvas, sourceManager);
                        programCompositor = new FrameCompositor(this.$refs.programCanvas, sourceManager);

                        const scene = this.scenes[this.liveSceneIndex] ?? this.scenes[0];
                        previewCompositor.setScene(scene);
                        programCompositor.setScene(scene);

                        if (scene?.overlays?.length > 0) {
                            programCompositor.setOverlays(scene.overlays);
                            previewCompositor.setOverlays(scene.overlays);
                        }

                        previewCompositor.start();
                        programCompositor.start();
                        console.log('[Studio] Compositors started');
                    } catch (err) {
                        console.error('[Studio] Compositor failed:', err);
                        this.connectionError = 'Failed to start compositors: ' + err.message;
                    }

                    // 3b. Initialize OverlayRenderer for HTML overlays (optional)
                    try {
                        const OverlayRendererClass = await window.loadOverlayRenderer();
                        overlayRenderer = new OverlayRendererClass(this.$refs.overlayRenderContainer);
                        if (programCompositor) programCompositor.setOverlayRenderer(overlayRenderer);
                        if (previewCompositor) previewCompositor.setOverlayRenderer(overlayRenderer);

                        const initScene = this.scenes[this.liveSceneIndex] ?? this.scenes[0];
                        (initScene?.overlays || []).forEach((overlay, idx) => {
                            if (overlay.type === 'html' && overlay.visible !== false) {
                                overlayRenderer.addOverlay(idx, overlay);
                            }
                        });
                        console.log('[Studio] OverlayRenderer initialized');
                    } catch (err) {
                        console.warn('[Studio] OverlayRenderer init failed (non-critical):', err.message);
                    }

                    // 4. Connect to LiveKit room early (for guest tracks)
                    // This lets guests be brought on BEFORE the host clicks "Start Broadcasting"
                    try {
                        const { Room, RoomEvent } = await window.loadLiveKit();
                        room = new Room({ adaptiveStream: true, dynacast: true });

                        room.on(RoomEvent.ParticipantConnected, () => this._updateViewerCount());
                        room.on(RoomEvent.ParticipantDisconnected, () => this._updateViewerCount());
                        room.on(RoomEvent.Disconnected, () => { this.broadcasting = false; });

                        await room.connect(this._livekitUrl, this._livekitToken);
                        this._setupGuestTracksFromLiveKit();
                        console.log('[Studio] LiveKit room connected (guest tracks ready)');
                    } catch (err) {
                        console.warn('[Studio] LiveKit room connection failed (non-fatal):', err.message);
                    }

                    this.connected = true;

                    // 5. Auto-start captions if any scene has a caption overlay
                    const hasCaption = this.scenes.some(s =>
                        (s.overlays || []).some(o => o.type === 'caption' && o.visible !== false)
                    );
                    if (hasCaption) {
                        this.enableCaptions();
                    }

                    console.log('[Studio] Setup complete — ready to broadcast');
                },

                /**
                 * Phase 2: Connect to LiveKit and start broadcasting to viewers.
                 * Call after startStudio() when the host is ready to go live.
                 */
                async startBroadcasting() {
                    if (this.broadcasting) return;
                    if (!programCompositor) {
                        this.connectionError = 'Studio not ready — compositors failed to initialize. Try refreshing.';
                        console.error('[Broadcast] programCompositor is null — studio init may have failed');
                        return;
                    }

                    try {
                        // Room is already connected from startStudio()
                        // If not connected yet, connect now
                        if (!room) {
                            const { Room, RoomEvent } = await window.loadLiveKit();
                            room = new Room({ adaptiveStream: true, dynacast: true });

                            room.on(RoomEvent.ParticipantConnected, () => this._updateViewerCount());
                            room.on(RoomEvent.ParticipantDisconnected, () => this._updateViewerCount());
                            room.on(RoomEvent.Disconnected, () => { this.broadcasting = false; });

                            await room.connect(this._livekitUrl, this._livekitToken);
                            this._setupGuestTracksFromLiveKit();
                        }

                        // Publish PROGRAM canvas to LiveKit
                        const canvasStream = programCompositor.getOutputStream();
                        const canvasTrack = canvasStream.getVideoTracks()[0];
                        await room.localParticipant.publishTrack(canvasTrack, {
                            name: 'program-output',
                            simulcast: false,
                        });
                        console.log('[Broadcast] Program canvas published to LiveKit');

                        // Publish host audio
                        if (hostAudioTrack) {
                            await room.localParticipant.publishTrack(hostAudioTrack, {
                                name: 'host-mic',
                            });
                            console.log('[Broadcast] Host audio published');
                        }

                        this.broadcasting = true;
                        this._updateViewerCount();
                        console.log('[Broadcast] Now live to viewers!');
                    } catch (err) {
                        console.error('[Broadcast] Failed to start broadcasting:', err);
                        this.connectionError = 'Failed to start broadcasting: ' + err.message;
                    }
                },

                // ==============================================================
                // LiveKit guest track listeners (replaces PeerManager)
                // ==============================================================

                _setupGuestTracksFromLiveKit() {
                    if (!room) {
                        console.warn('[Studio] No LiveKit room — guest tracks disabled');
                        return;
                    }

                    const { RoomEvent } = window.LiveKitClient;

                    // When a guest starts publishing (brought on), their tracks arrive here
                    room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
                        // Only handle guest participants (identity starts with "guest-")
                        if (!participant.identity.startsWith('guest-')) return;

                        const guestId = participant.identity;

                        if (track.kind === 'video') {
                            const mediaStream = new MediaStream([track.mediaStreamTrack]);
                            const name = participant.name || guestId;
                            const isScreen = publication.source === 'screen_share' || track.source === 'screen_share';

                            if (isScreen) {
                                // Guest screen share — add as separate source
                                const screenKey = `${guestId}-screen`;
                                sourceManager.addSource(screenKey, mediaStream, `${name} (Screen)`);
                                console.log(`[Studio] Guest screen share from LiveKit: ${screenKey}`);
                            } else {
                                // Guest camera — add with slot key
                                const slotKey = this._guestSlotKey(guestId);
                                sourceManager.addSource(slotKey, mediaStream, name);
                                console.log(`[Studio] Guest video from LiveKit: ${slotKey} (${name})`);
                                this._updateGuestStatus(guestId, 'live', name);
                            }
                        }

                        if (track.kind === 'audio') {
                            // Guest audio is automatically forwarded to all subscribers by LiveKit
                            // Viewers hear it directly — no need to re-publish
                            const name = participant.name || guestId;
                            guestAudioTracks.set(guestId, track.mediaStreamTrack);
                            if (captionEngine) {
                                captionEngine.addSource(guestId, track.mediaStreamTrack, name);
                            }
                            console.log(`[Studio] Guest audio from LiveKit: ${guestId} (${name})`);
                        }
                    });

                    // When a guest stops publishing (taken off), their tracks are removed
                    room.on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
                        if (!participant.identity.startsWith('guest-')) return;

                        if (track.kind === 'video') {
                            const isScreen = publication.source === 'screen_share' || track.source === 'screen_share';
                            if (isScreen) {
                                const screenKey = `${participant.identity}-screen`;
                                if (sourceManager) sourceManager.removeSource(screenKey);
                                console.log(`[Studio] Guest screen share removed: ${screenKey}`);
                            } else {
                                const slotKey = this._guestSlotKey(participant.identity);
                                if (sourceManager) sourceManager.removeSource(slotKey);
                                console.log(`[Studio] Guest video removed: ${slotKey}`);
                            }
                        }

                        if (track.kind === 'audio') {
                            guestAudioTracks.delete(participant.identity);
                            if (captionEngine) captionEngine.removeSource(participant.identity);
                            console.log(`[Studio] Guest audio removed: ${participant.identity}`);
                        }
                    });

                    // When a participant joins the room
                    room.on(RoomEvent.ParticipantConnected, (participant) => {
                        if (participant.identity.startsWith('guest-')) {
                            console.log(`[Studio] Guest joined room: ${participant.identity} (${participant.name})`);
                            this._updateGuestStatus(participant.identity, 'connected', participant.name);
                            this.$wire.refreshGuestStatuses();
                        }
                    });

                    // When a participant leaves the room
                    room.on(RoomEvent.ParticipantDisconnected, (participant) => {
                        if (participant.identity.startsWith('guest-')) {
                            const slotKey = this._guestSlotKey(participant.identity);
                            if (sourceManager) sourceManager.removeSource(slotKey);
                            console.log(`[Studio] Guest left room: ${participant.identity}`);
                            this._updateGuestStatus(participant.identity, 'disconnected');
                            this.$wire.refreshGuestStatuses();
                        }
                    });

                    // Check for guests already in the room (reconnection case)
                    room.remoteParticipants.forEach((participant) => {
                        if (participant.identity.startsWith('guest-')) {
                            this._updateGuestStatus(participant.identity, 'connected', participant.name);

                            // If they're already publishing (brought on before host connected), add their tracks
                            participant.trackPublications.forEach((pub) => {
                                if (pub.track && pub.track.kind === 'video') {
                                    const mediaStream = new MediaStream([pub.track.mediaStreamTrack]);
                                    const slotKey = this._guestSlotKey(participant.identity);
                                    sourceManager.addSource(slotKey, mediaStream, participant.name || participant.identity);
                                    this._updateGuestStatus(participant.identity, 'live', participant.name);
                                }
                            });
                        }
                    });

                    console.log('[Studio] LiveKit guest track listeners set up');
                },

                // ==============================================================
                // Preview / Program / TAKE
                // ==============================================================

                previewScene(index) {
                    this.previewSceneIndex = index;
                    const scene = this.scenes[index];
                    if (!scene) return;
                    if (!scene.overlays) scene.overlays = [];

                    if (previewCompositor) {
                        previewCompositor.setScene(scene);
                        previewCompositor.setOverlays(scene.overlays);
                    }

                    // Auto-start captions if this scene has a caption overlay
                    if (!captionEngine && scene.overlays.some(o => o.type === 'caption' && o.visible !== false)) {
                        this.enableCaptions();
                    }

                    if (canvasEditor && this.editorMode) {
                        canvasEditor.setSlots(scene.slots || []);
                        canvasEditor.setOverlays(scene.overlays);
                        this.selectedItem = null;
                    }
                },

                take() {
                    this.liveSceneIndex = this.previewSceneIndex;
                    const scene = this.scenes[this.liveSceneIndex];
                    if (!scene) return;
                    if (!scene.overlays) scene.overlays = [];

                    if (programCompositor) {
                        programCompositor.setScene(scene);
                        programCompositor.setOverlays(scene.overlays);
                    }

                    // Ensure captions are running if this scene uses them
                    if (!captionEngine && scene.overlays.some(o => o.type === 'caption' && o.visible !== false)) {
                        this.enableCaptions();
                    }

                    this.$wire.switchScene(this.liveSceneIndex);
                    console.log('[Studio] TAKE:', scene.name);
                },

                // ==============================================================
                // Controls
                // ==============================================================

                async toggleAudio() {
                    this.audioEnabled = !this.audioEnabled;
                    // Toggle the raw audio track
                    if (hostAudioTrack) {
                        hostAudioTrack.enabled = this.audioEnabled;
                    }
                    // Also mute/unmute in LiveKit so viewers don't hear it
                    if (room && room.localParticipant) {
                        const audioPubs = Array.from(room.localParticipant.audioTrackPublications.values());
                        for (const pub of audioPubs) {
                            if (pub.track) {
                                if (this.audioEnabled) {
                                    pub.unmute();
                                } else {
                                    pub.mute();
                                }
                            }
                        }
                    }
                },

                async toggleVideo() {
                    this.videoEnabled = !this.videoEnabled;
                    const hostEl = sourceManager?.getElement('host');
                    if (hostEl) {
                        const tracks = hostEl.srcObject?.getVideoTracks();
                        if (tracks?.[0]) tracks[0].enabled = this.videoEnabled;
                    }
                },

                async toggleScreenShare() {
                    if (this.screenStream) {
                        // Stop screen share and unpublish audio if it was published
                        this.screenStream.getTracks().forEach(t => t.stop());
                        this.screenStream = null;
                        if (sourceManager) sourceManager.removeSource('screen');
                        // Unpublish screen audio from LiveKit
                        if (room) {
                            const pubs = Array.from(room.localParticipant.trackPublications.values());
                            for (const pub of pubs) {
                                if (pub.trackName === 'screen-audio') {
                                    await room.localParticipant.unpublishTrack(pub.track);
                                }
                            }
                        }
                        return;
                    }

                    try {
                        this.screenStream = await navigator.mediaDevices.getDisplayMedia({
                            video: { width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate: { ideal: 30 } },
                            audio: true,
                            preferCurrentTab: false,
                        });

                        // Add video to SourceManager for compositor
                        if (sourceManager) {
                            const videoOnly = new MediaStream(this.screenStream.getVideoTracks());
                            sourceManager.addSource('screen', videoOnly, 'Screen Share');
                        }

                        // Publish screen audio to LiveKit so viewers hear it
                        const screenAudio = this.screenStream.getAudioTracks()[0];
                        if (screenAudio && room) {
                            await room.localParticipant.publishTrack(screenAudio, {
                                name: 'screen-audio',
                                source: 'screen_share_audio',
                            });
                            console.log('[Studio] Screen share audio published');
                        }

                        this.screenStream.getVideoTracks()[0].addEventListener('ended', () => {
                            this.screenStream = null;
                            if (sourceManager) sourceManager.removeSource('screen');
                        });
                    } catch (err) {
                        console.warn('[Studio] Screen share cancelled:', err.message);
                    }
                },

                // ==============================================================
                // Video URL Sources
                // ==============================================================

                async addVideoSource(url, name) {
                    if (!url || !sourceManager) return;

                    const videoEl = document.createElement('video');
                    videoEl.crossOrigin = 'anonymous';
                    videoEl.src = url;
                    videoEl.loop = true;
                    videoEl.muted = true; // Muted to allow autoplay
                    videoEl.playsInline = true;
                    videoEl.style.display = 'none';
                    document.body.appendChild(videoEl);

                    try {
                        await videoEl.play();
                    } catch (err) {
                        console.warn('[Studio] Video autoplay failed, trying with interaction:', err.message);
                    }

                    const key = 'video-' + Date.now();
                    const stream = videoEl.captureStream ? videoEl.captureStream() : videoEl.mozCaptureStream();
                    sourceManager.addSource(key, stream, name);
                    this._videoSources.push({ key, el: videoEl });
                    console.log(`[Studio] Video source added: ${name} (${key})`);
                },

                // ==============================================================
                // RTMP Ingress (external stream INTO studio)
                // ==============================================================

                async createIngress() {
                    try {
                        const result = await this.$wire.createRtmpIngress();
                        if (result) {
                            this.ingressInfo = result;
                            console.log('[Studio] RTMP ingress created:', result.url);
                        }
                    } catch (err) {
                        console.error('[Studio] Failed to create ingress:', err);
                        this.connectionError = 'Failed to create RTMP endpoint: ' + (err.message || 'Unknown error');
                    }
                },

                async deleteIngress() {
                    if (!this.ingressInfo?.ingressId) return;
                    try {
                        await this.$wire.deleteRtmpIngress(this.ingressInfo.ingressId);
                        this.ingressInfo = null;
                        console.log('[Studio] RTMP ingress deleted');
                    } catch (err) {
                        console.error('[Studio] Failed to delete ingress:', err);
                    }
                },

                // ==============================================================
                // RTMP Egress / Simulcast (studio → external platforms)
                // ==============================================================

                addRtmpDestination(name, url) {
                    if (!url) return;
                    this.rtmpDestinations.push({ name, url, active: false, egressId: null });
                },

                removeRtmpDestination(index) {
                    const dest = this.rtmpDestinations[index];
                    if (dest?.active && dest?.egressId) {
                        this.stopSimulcast(index);
                    }
                    this.rtmpDestinations.splice(index, 1);
                },

                async startSimulcast(index) {
                    const dest = this.rtmpDestinations[index];
                    if (!dest || dest.active) return;

                    try {
                        const egressId = await this.$wire.startRtmpEgress(dest.url);
                        if (egressId) {
                            this.rtmpDestinations[index].active = true;
                            this.rtmpDestinations[index].egressId = egressId;
                            console.log(`[Studio] Simulcast started: ${dest.name} → ${dest.url}`);
                        }
                    } catch (err) {
                        console.error('[Studio] Failed to start simulcast:', err);
                        this.connectionError = 'Failed to start simulcast: ' + (err.message || 'Unknown error');
                    }
                },

                async stopSimulcast(index) {
                    const dest = this.rtmpDestinations[index];
                    if (!dest?.egressId) return;

                    try {
                        await this.$wire.stopRtmpEgress(dest.egressId);
                        this.rtmpDestinations[index].active = false;
                        this.rtmpDestinations[index].egressId = null;
                        console.log(`[Studio] Simulcast stopped: ${dest.name}`);
                    } catch (err) {
                        console.error('[Studio] Failed to stop simulcast:', err);
                    }
                },

                // ==============================================================
                // Canvas Editor
                // ==============================================================

                async toggleEditor() {
                    this.editorMode = !this.editorMode;

                    if (this.editorMode) {
                        if (!canvasEditor) {
                            const CanvasEditor = await window.loadCanvasEditor();
                            canvasEditor = new CanvasEditor(this.$refs.previewCanvas, this.$refs.editorHandles);

                            canvasEditor.onSlotChanged(({ index, x, y, w, h }) => {
                                const scene = this.scenes[this.previewSceneIndex];
                                if (scene?.slots?.[index]) {
                                    Object.assign(scene.slots[index], { x, y, w, h });
                                    if (previewCompositor) previewCompositor.setScene(scene);
                                    this._autoSave();
                                }
                            });

                            canvasEditor.onOverlayChanged(({ index, x, y, w, h }) => {
                                const scene = this.scenes[this.previewSceneIndex];
                                if (scene?.overlays?.[index]) {
                                    Object.assign(scene.overlays[index], { x, y, w, h });
                                    if (previewCompositor) previewCompositor.setOverlays(scene.overlays);
                                    this._autoSave();
                                }
                            });

                            canvasEditor.onSelect(({ type, index }) => {
                                this.selectedItem = { type, index };
                            });
                        }

                        // Sync handles with current preview scene
                        const scene = this.scenes[this.previewSceneIndex];
                        if (scene) {
                            canvasEditor.setSlots(scene.slots || []);
                            canvasEditor.setOverlays(scene.overlays || []);
                        }
                        canvasEditor.enable();
                    } else {
                        if (canvasEditor) canvasEditor.disable();
                        this.selectedItem = null;
                    }
                },

                get selectedItemLabel() {
                    if (!this.selectedItem) return '';
                    const { type, index } = this.selectedItem;
                    const scene = this.scenes[this.previewSceneIndex];
                    if (type === 'slot') {
                        const slot = scene?.slots?.[index];
                        return `Slot ${slot?.label || String.fromCharCode(65 + index)}: ${slot?.source || '(none)'}`;
                    } else {
                        const overlay = scene?.overlays?.[index];
                        return overlay ? `${overlay.type}: ${overlay.content || overlay.src || ''}`.substring(0, 40) : 'Overlay';
                    }
                },

                getSelectedProperty(prop) {
                    if (!this.selectedItem) return null;
                    const { type, index } = this.selectedItem;
                    const scene = this.scenes[this.previewSceneIndex];
                    if (type === 'slot') return scene?.slots?.[index]?.[prop];
                    return scene?.overlays?.[index]?.[prop];
                },

                getSelectedOverlay() {
                    if (!this.selectedItem || this.selectedItem.type !== 'overlay') return null;
                    const scene = this.scenes[this.previewSceneIndex];
                    return scene?.overlays?.[this.selectedItem.index];
                },

                updateSelectedProperty(prop, value) {
                    if (!this.selectedItem) return;
                    const { type, index } = this.selectedItem;
                    const scene = this.scenes[this.previewSceneIndex];
                    if (!scene) return;

                    if (type === 'slot' && scene.slots?.[index]) {
                        scene.slots[index][prop] = value;
                        if (previewCompositor) previewCompositor.setScene(scene);
                        if (canvasEditor) canvasEditor.setSlots(scene.slots);
                    } else if (type === 'overlay') {
                        if (!scene.overlays) scene.overlays = [];
                        if (scene.overlays[index]) {
                            scene.overlays[index][prop] = value;
                            if (previewCompositor) previewCompositor.setOverlays(scene.overlays);
                            if (canvasEditor) canvasEditor.setOverlays(scene.overlays);
                        }
                    }
                },

                // Version management
                addVersion() {
                    const newScene = {
                        name: `Version ${this.scenes.length + 1}`,
                        layout: 'solo',
                        slots: [{ label: 'A', source: 'host', x: 0, y: 0, w: 1, h: 1 }],
                        overlays: [],
                    };
                    this.scenes.push(newScene);
                    this.previewScene(this.scenes.length - 1);
                    this.$wire.updateScenes(this.scenes);
                },

                cloneVersion() {
                    const current = this.scenes[this.previewSceneIndex];
                    if (!current) return;
                    const clone = JSON.parse(JSON.stringify(current));
                    clone.name = `${current.name} (copy)`;
                    this.scenes.push(clone);
                    this.previewScene(this.scenes.length - 1);
                    this.$wire.updateScenes(this.scenes);
                },

                deleteVersion() {
                    if (this.scenes.length <= 1) return;
                    if (this.previewSceneIndex === this.liveSceneIndex) return;
                    this.scenes.splice(this.previewSceneIndex, 1);
                    if (this.previewSceneIndex >= this.scenes.length) {
                        this.previewSceneIndex = this.scenes.length - 1;
                    }
                    if (this.liveSceneIndex >= this.scenes.length) {
                        this.liveSceneIndex = this.scenes.length - 1;
                    }
                    this.previewScene(this.previewSceneIndex);
                    this.$wire.updateScenes(this.scenes);
                },

                // ==============================================================
                // Scene Config — Layout + Source Assignment
                // ==============================================================

                changeLayout(layoutKey) {
                    this.currentLayoutKey = layoutKey;
                    const layout = this.availableLayouts.find(l => l.key === layoutKey);
                    if (!layout) return;

                    // Reset slot sources
                    this.previewSlotSources = {};

                    // Build a new scene from the preset layout
                    const scene = this.scenes[this.previewSceneIndex] ?? this.scenes[0];
                    if (scene) {
                        scene.slots = layout.slots.map((s, i) => ({
                            ...s,
                            source: scene.slots?.[i]?.source ?? null,
                        }));
                        if (previewCompositor) previewCompositor.setScene(scene);
                        this._autoSave();
                    }
                },

                assignSlotSource(slotIndex, sourceKey) {
                    this.previewSlotSources[slotIndex] = sourceKey;

                    const scene = this.scenes[this.previewSceneIndex] ?? this.scenes[0];
                    if (scene && scene.slots?.[slotIndex]) {
                        scene.slots[slotIndex].source = sourceKey || null;
                        if (previewCompositor) previewCompositor.setScene(scene);
                        this._autoSave();
                    }
                },

                // ==============================================================
                // Overlay Panel — Add/Edit/Toggle/Remove
                // ==============================================================

                showAddOverlay(type) {
                    this.addOverlayType = type;
                    this.editOverlayIndex = null;
                    // Reset form with defaults
                    this.overlayForm = {
                        type, content: '', style: 'lower-third', position: 'bottom-center',
                        color: '#ffffff', bg: '#000000', fontSize: 28, fontWeight: '700',
                        src: '', opacity: 0.8, w: 0.12, h: 0.08,
                        html: '', htmlType: 'iframe', refreshInterval: 2000,
                        captionStyle: 'white-on-black', maxLines: 2, showSpeakerName: true,
                    };
                    // Set type-specific defaults
                    if (type === 'html') {
                        this.overlayForm.w = 1.0;
                        this.overlayForm.h = 0.08;
                        this.overlayForm.position = 'bottom';
                    } else if (type === 'caption') {
                        this.overlayForm.position = 'bottom-center';
                        this.overlayForm.fontSize = 24;
                    }
                    $flux.modal('overlay-' + type + '-modal').show();
                },

                saveOverlay() {
                    const pos = POSITION_PRESETS[this.overlayForm.position] ?? POSITION_PRESETS['bottom-center'];
                    const type = this.overlayForm.type || this.addOverlayType;
                    let overlay = { type, visible: true, position: this.overlayForm.position, x: pos.x, y: pos.y };

                    if (type === 'text') {
                        Object.assign(overlay, {
                            content: this.overlayForm.content,
                            style: this.overlayForm.style,
                            fontSize: parseInt(this.overlayForm.fontSize) || 28,
                            fontWeight: this.overlayForm.fontWeight,
                            color: this.overlayForm.color,
                            bg: this.overlayForm.bg + 'cc',
                        });
                    } else if (type === 'image') {
                        Object.assign(overlay, {
                            src: this.overlayForm.src,
                            w: parseFloat(this.overlayForm.w) || 0.12,
                            h: parseFloat(this.overlayForm.h) || 0.08,
                            opacity: parseFloat(this.overlayForm.opacity) || 0.8,
                        });
                    } else if (type === 'html') {
                        Object.assign(overlay, {
                            src: this.overlayForm.htmlType === 'iframe' ? this.overlayForm.src : null,
                            html: this.overlayForm.htmlType === 'raw' ? this.overlayForm.html : null,
                            w: parseFloat(this.overlayForm.w) || 1.0,
                            h: parseFloat(this.overlayForm.h) || 0.08,
                            refreshInterval: parseInt(this.overlayForm.refreshInterval) || 2000,
                        });
                    } else if (type === 'caption') {
                        Object.assign(overlay, {
                            style: this.overlayForm.captionStyle,
                            fontSize: parseInt(this.overlayForm.fontSize) || 24,
                            maxLines: parseInt(this.overlayForm.maxLines) || 2,
                            showSpeakerName: this.overlayForm.showSpeakerName,
                            w: 0.8,
                        });
                    }

                    const scene = this.scenes[this.previewSceneIndex];
                    if (!scene) return;
                    if (!scene.overlays) scene.overlays = [];

                    if (this.editOverlayIndex !== null && this.editOverlayIndex >= 0) {
                        scene.overlays[this.editOverlayIndex] = overlay;
                    } else {
                        scene.overlays.push(overlay);
                    }

                    if (previewCompositor) previewCompositor.setOverlays([...scene.overlays]);
                    if (programCompositor && this.previewSceneIndex === this.liveSceneIndex) {
                        programCompositor.setOverlays([...scene.overlays]);
                    }
                    this._autoSave();

                    // Auto-start caption engine when a caption overlay is added
                    if (type === 'caption' && !captionEngine) {
                        this.enableCaptions();
                    }

                    this.editOverlayIndex = null;
                    this.addOverlayType = null;
                },

                editOverlay(idx) {
                    const overlay = this.currentOverlays[idx];
                    if (!overlay) return;

                    this.editOverlayIndex = idx;
                    this.addOverlayType = overlay.type;
                    this.overlayForm = {
                        type: overlay.type,
                        content: overlay.content ?? '',
                        style: overlay.style ?? 'lower-third',
                        position: overlay.position ?? 'bottom-center',
                        color: (overlay.color ?? '#ffffff').replace(/[a-f0-9]{2}$/i, ''),
                        bg: (overlay.bg ?? '#000000').substring(0, 7),
                        fontSize: overlay.fontSize ?? 28,
                        fontWeight: overlay.fontWeight ?? '700',
                        src: overlay.src ?? '',
                        opacity: overlay.opacity ?? 0.8,
                        w: overlay.w ?? 0.12,
                        h: overlay.h ?? 0.08,
                        html: overlay.html ?? '',
                        htmlType: overlay.html ? 'raw' : 'iframe',
                        refreshInterval: overlay.refreshInterval ?? 2000,
                        captionStyle: overlay.type === 'caption' ? (overlay.style ?? 'white-on-black') : 'white-on-black',
                        maxLines: overlay.maxLines ?? 2,
                        showSpeakerName: overlay.showSpeakerName !== false,
                    };
                    $flux.modal('overlay-' + overlay.type + '-modal').show();
                },

                removeOverlay(index) {
                    const scene = this.scenes[this.previewSceneIndex];
                    if (!scene) return;
                    scene.overlays.splice(index, 1);
                    if (previewCompositor) previewCompositor.setOverlays([...scene.overlays]);
                    if (programCompositor && this.previewSceneIndex === this.liveSceneIndex) {
                        programCompositor.setOverlays([...scene.overlays]);
                    }
                    if (overlayRenderer) overlayRenderer.removeOverlay(index);
                    this._autoSave();
                },

                toggleOverlayVisibility(index) {
                    const scene = this.scenes[this.previewSceneIndex];
                    if (!scene?.overlays?.[index]) return;
                    scene.overlays[index].visible = !scene.overlays[index].visible;
                    if (previewCompositor) previewCompositor.setOverlays([...scene.overlays]);
                    if (programCompositor && this.previewSceneIndex === this.liveSceneIndex) {
                        programCompositor.setOverlays([...scene.overlays]);
                    }
                    this._autoSave();
                },

                overlayLabel(overlay) {
                    switch (overlay.type) {
                        case 'text': return (overlay.style ?? 'Text') + ': "' + (overlay.content ?? '').substring(0, 30) + '"';
                        case 'image': return 'Image: ' + (overlay.src ?? '').split('/').pop();
                        case 'html': return 'HTML: ' + (overlay.src ?? overlay.html?.substring(0, 20) ?? 'custom');
                        case 'caption': return 'Captions: ' + (overlay.style ?? 'Active Speaker');
                        default: return overlay.type ?? 'Overlay';
                    }
                },

                // ==============================================================
                // Caption Engine
                // ==============================================================

                async enableCaptions() {
                    try {
                        const CaptionEngineClass = await window.loadCaptionEngine();
                        captionEngine = new CaptionEngineClass();

                        const guests = new Map();
                        for (const [id, track] of guestAudioTracks) {
                            guests.set(id, { track, name: id });
                        }
                        const audioSources = {
                            host: hostAudioTrack ? { track: hostAudioTrack, name: 'Host' } : null,
                            guests,
                        };

                        captionEngine.onCaption(({ speaker, text }) => {
                            if (programCompositor) programCompositor.setCaptionText(speaker, text);
                            if (previewCompositor) previewCompositor.setCaptionText(speaker, text);
                        });

                        captionEngine.start(audioSources);
                        console.log('[Studio] CaptionEngine started');
                    } catch (err) {
                        console.warn('[Studio] CaptionEngine init failed:', err.message);
                    }
                },

                // ==============================================================
                // Source monitor thumbnails
                // ==============================================================

                renderSourceThumb(key) {
                    const container = document.getElementById('source-thumb-' + key);
                    const el = sourceManager?.getElement(key);
                    if (!container || !el?.srcObject) return;

                    const existing = container.querySelector('video');
                    if (existing) existing.remove();

                    const thumbEl = document.createElement('video');
                    thumbEl.srcObject = el.srcObject;
                    thumbEl.autoplay = true;
                    thumbEl.muted = true;
                    thumbEl.playsInline = true;
                    thumbEl.style.cssText = 'width:100%;height:100%;object-fit:cover;position:absolute;inset:0;';
                    container.appendChild(thumbEl);
                    thumbEl.play().catch(() => {});
                },

                // ==============================================================
                // Helpers
                // ==============================================================

                _guestSlotKey(guestId) {
                    if (!this._guestSlotMap) this._guestSlotMap = {};
                    if (this._guestSlotMap[guestId]) return this._guestSlotMap[guestId];

                    const usedSlots = Object.values(this._guestSlotMap);
                    let slotNum = 1;
                    while (usedSlots.includes('guest-' + slotNum)) slotNum++;
                    this._guestSlotMap[guestId] = 'guest-' + slotNum;
                    return 'guest-' + slotNum;
                },

                _findGuest(guestId) {
                    return this.liveGuests.find(g => g.identity === guestId) ?? null;
                },

                _updateGuestStatus(guestId, status, name) {
                    const idx = this.liveGuests.findIndex(g => g.identity === guestId);
                    if (idx !== -1) {
                        this.liveGuests[idx].status = status;
                        if (name) this.liveGuests[idx].name = name;
                    } else if (status !== 'disconnected') {
                        // Guest not in our list yet — add them
                        this.liveGuests.push({
                            id: null,
                            name: name ?? guestId,
                            status: status,
                            slot_number: null,
                            identity: guestId,
                        });
                    }
                },

                _autoSave() {
                    clearTimeout(this._saveTimer);
                    this._saveTimer = setTimeout(() => {
                        this.$wire.updateScenes(this.scenes);
                    }, 500);
                },

                _updateViewerCount() {
                    if (room) {
                        this.viewerCount = room.remoteParticipants.size;
                        this.$wire.set('viewerCount', this.viewerCount);
                    }
                },

                // ==============================================================
                // Cleanup
                // ==============================================================

                async disconnect() {
                    if (canvasEditor) { canvasEditor.destroy(); canvasEditor = null; }
                    if (captionEngine) { captionEngine.stop(); captionEngine = null; }
                    if (overlayRenderer) { overlayRenderer.destroy(); overlayRenderer = null; }
                    if (previewCompositor) { previewCompositor.stop(); previewCompositor.destroy(); previewCompositor = null; }
                    if (programCompositor) { programCompositor.stop(); programCompositor.destroy(); programCompositor = null; }
                    if (sourceManager) { sourceManager.destroy(); sourceManager = null; }
                    if (this.screenStream) { this.screenStream.getTracks().forEach(t => t.stop()); this.screenStream = null; }
                    if (room) { await room.disconnect(); room = null; }
                    this.connected = false;
                },

                destroy() { this.disconnect(); }
            };
        });
        </script>
        @endscript

    {{-- ============================================================ --}}
    {{-- POST-LIVE STATE (ended) --}}
    {{-- ============================================================ --}}
    @elseif ($event->status === 'ended')
        <div class="mx-auto max-w-4xl px-4 py-8">
            {{-- Event summary --}}
            <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="xl">{{ $event->title }}</flux:heading>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <flux:badge color="zinc" size="sm">{{ __('Ended') }}</flux:badge>
                            @if ($event->ended_at)
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $event->ended_at->format('M j, Y g:i A') }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('events.index')">
                        {{ __('Back to Events') }}
                    </flux:button>
                </div>
            </div>

            {{-- Stats --}}
            <div class="mb-6 grid gap-4 sm:grid-cols-3">
                {{-- Duration --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="clock" class="mx-auto mb-2 size-8 text-zinc-400" />
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">
                        @if ($event->started_at && $event->ended_at)
                            {{ $event->started_at->diffForHumans($event->ended_at, ['parts' => 2, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}
                        @else
                            --
                        @endif
                    </div>
                    <flux:text class="text-sm">{{ __('Duration') }}</flux:text>
                </div>

                {{-- Questions answered --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="chat-bubble-left-right" class="mx-auto mb-2 size-8 text-zinc-400" />
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->answeredQuestions->count() }}</div>
                    <flux:text class="text-sm">{{ __('Questions Answered') }}</flux:text>
                </div>

                {{-- Registrations --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="users" class="mx-auto mb-2 size-8 text-zinc-400" />
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->registrationCount }}</div>
                    <flux:text class="text-sm">{{ __('Registrations') }}</flux:text>
                </div>
            </div>

            {{-- Recording placeholder --}}
            <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center dark:border-zinc-600 dark:bg-zinc-900">
                <flux:icon name="film" class="mx-auto mb-3 size-10 text-zinc-400" />
                <flux:heading size="sm">{{ __('Recording') }}</flux:heading>
                @if ($event->recording_path)
                    <flux:text class="mt-1 text-sm">{{ __('Recording available.') }}</flux:text>
                    <div class="mt-3">
                        <flux:button size="sm" variant="primary" icon="arrow-down-tray" href="{{ $event->recording_path }}" target="_blank">
                            {{ __('Download Recording') }}
                        </flux:button>
                    </div>
                @else
                    <flux:text class="mt-1 text-sm">{{ __('Recording processing is not yet available. Check back later.') }}</flux:text>
                @endif
            </div>

            {{-- Answered questions review --}}
            @if ($this->answeredQuestions->isNotEmpty())
                <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-3">{{ __('Questions Asked') }}</flux:heading>
                    <div class="space-y-3">
                        @foreach ($this->answeredQuestions as $question)
                            <div class="flex items-start gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                                <flux:icon name="chat-bubble-left" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                <div>
                                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $question->viewer_name }}</span>
                                    <p class="text-sm text-zinc-800 dark:text-zinc-200">{{ $question->question }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

    {{-- ============================================================ --}}
    {{-- CANCELLED STATE --}}
    {{-- ============================================================ --}}
    @else
        <div class="mx-auto max-w-4xl px-4 py-8">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="x-circle" class="mx-auto mb-3 size-12 text-amber-500" />
                <flux:heading size="lg">{{ __('Event Cancelled') }}</flux:heading>
                <flux:text class="mt-2">{{ $event->title }}</flux:text>
                <div class="mt-4">
                    <flux:button variant="primary" icon="arrow-left" :href="route('events.index')">
                        {{ __('Back to Events') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</div>

