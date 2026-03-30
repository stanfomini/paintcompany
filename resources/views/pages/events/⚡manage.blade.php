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
    public string $overlayText = '';

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
    public function onBackstageMessage(array $event): void
    {
        unset($this->backstageMessages);
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

        // Auto-generate host token if event is already live (page refresh while streaming)
        if ($this->event->status === 'live') {
            $this->livekitToken = app(LiveKitService::class)->generateHostToken($this->event, auth()->user());
        }
    }

    public function goLive()
    {
        if (! in_array($this->event->status, ['draft', 'scheduled'])) {
            session()->flash('error', __('This event cannot go live from its current status.'));

            return;
        }

        $liveEventService = app(LiveEventService::class);
        $liveEventService->goLive($this->event);

        // Redirect to force a clean page load — Livewire's DOM morph can't handle
        // the full template switch from pre-live to live state (different Alpine components)
        return $this->redirect(route('events.manage', $this->event), navigate: false);
    }

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
    }

    public function dismissQuestion(int $questionId): void
    {
        $question = LiveEventQuestion::where('live_event_id', $this->event->id)->findOrFail($questionId);

        app(LiveEventService::class)->dismissQuestion($question);

        unset($this->pendingQuestions, $this->answeredQuestions);
    }

    public function addResource(): void
    {
        $this->validate([
            'newResourceTitle' => 'required|string|max:255',
            'newResourceUrl' => 'required|url|max:2048',
            'newResourceType' => 'required|in:link,document,video',
        ]);

        $maxSort = $this->event->resources()->max('sort_order') ?? 0;

        $this->event->resources()->create([
            'title' => $this->newResourceTitle,
            'url' => $this->newResourceUrl,
            'type' => $this->newResourceType,
            'sort_order' => $maxSort + 1,
        ]);

        $this->reset('newResourceTitle', 'newResourceUrl', 'newResourceType');
        $this->newResourceType = 'link';

        unset($this->resources);
    }

    public function removeResource(int $resourceId): void
    {
        LiveEventResource::where('live_event_id', $this->event->id)->where('id', $resourceId)->delete();

        unset($this->resources);
    }

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
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LiveEvent: Failed to refresh viewer count', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendChatMessage(): void
    {
        $this->validate([
            'chatMessage' => 'required|string|max:500',
        ]);

        if (! $this->event->chat_thread_id) {
            return;
        }

        $user = auth()->user();

        $message = ChatMessage::create([
            'thread_id' => $this->event->chat_thread_id,
            'user_id' => $user->id,
            'content' => $user->name.': '.$this->chatMessage,
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

        $this->chatMessage = '';
        unset($this->chatMessages);
    }

    /**
     * Echo handler: new chat message received via broadcast.
     */
    public function onChatMessage(array $event): void
    {
        unset($this->chatMessages);
    }

    /**
     * Echo handler: viewer count updated via broadcast.
     */
    public function onViewerCountUpdated(array $event): void
    {
        $this->viewerCount = $event['count'] ?? $this->viewerCount;
    }

    /**
     * Echo handler: presence channel reports who is here on initial join.
     */
    public function onPresenceHere(array $event): void
    {
        $this->refreshViewerCount();
    }

    /**
     * Echo handler: someone joined the presence channel.
     */
    public function onPresenceJoining(array $event): void
    {
        $this->refreshViewerCount();
    }

    /**
     * Echo handler: someone left the presence channel.
     */
    public function onPresenceLeaving(array $event): void
    {
        $this->refreshViewerCount();
    }

    /**
     * Echo handler: new question received from a viewer.
     */
    public function onQuestionReceived(array $event): void
    {
        unset($this->pendingQuestions);
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
    }

    public function removeGuest(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        app(LiveEventService::class)->removeGuest($guest);

        unset($this->guests);
    }

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

    public function updateScenes(array $scenes): void
    {
        $this->event->update(['scenes' => $scenes]);
        $this->event->refresh();
    }

    public function updateOverlays(array $overlays): void
    {
        $this->event->update(['overlays' => $overlays]);
        $this->event->refresh();
    }

    public function toggleFrameMode(): void
    {
        $this->event->update(['use_frame_mode' => ! $this->event->use_frame_mode]);
        $this->event->refresh();
    }

    public function saveAsTemplate(): void
    {
        $this->validate([
            'templateName' => 'required|string|max:255',
        ]);

        LiveEventSceneTemplate::create([
            'user_id' => auth()->id(),
            'name' => $this->templateName,
            'scenes' => $this->event->getScenesOrDefaults(),
            'overlays' => $this->event->overlays ?? [],
        ]);

        $this->reset('templateName');
        unset($this->sceneTemplates);
    }

    public function loadTemplate(int $templateId): void
    {
        $template = LiveEventSceneTemplate::where('user_id', auth()->id())->findOrFail($templateId);

        $this->event->update([
            'scenes' => $template->scenes,
            'overlays' => $template->overlays,
        ]);
        $this->event->refresh();
    }

    public function addTextOverlay(): void
    {
        $this->validate([
            'overlayText' => 'required|string|max:500',
        ]);

        $overlays = $this->event->overlays ?? [];
        $overlays[] = [
            'type' => 'text',
            'content' => $this->overlayText,
            'x' => 0.05,
            'y' => 0.02,
            'fontSize' => 28,
            'fontWeight' => '700',
            'color' => '#ffffff',
            'bg' => '#000000cc',
        ];

        $this->event->update(['overlays' => $overlays]);
        $this->event->refresh();
        $this->reset('overlayText');
    }

    public function removeOverlay(int $index): void
    {
        $overlays = $this->event->overlays ?? [];

        if (isset($overlays[$index])) {
            unset($overlays[$index]);
            $this->event->update(['overlays' => array_values($overlays)]);
            $this->event->refresh();
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

    public function sendBackstageMessage(): void
    {
        $this->validate([
            'backstageMessage' => 'required|string|max:500',
        ]);

        $service = app(LiveEventService::class);
        $thread = $service->ensureBackstageChatThread($this->event);
        $this->event->refresh();

        $user = auth()->user();

        $message = ChatMessage::create([
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'content' => $user->name.': '.$this->backstageMessage,
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

        $this->backstageMessage = '';
        unset($this->backstageMessages);
    }

    // ─── Live Production Controls ──────────────────────────────────

    #[Renderless]
    public function switchScene(int $index): void
    {
        $scenes = $this->event->getScenesOrDefaults();

        if (! isset($scenes[$index])) {
            return;
        }

        $this->event->update(['active_scene_index' => $index]);
    }

    public function bringGuestOn(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        app(LiveEventService::class)->markGuestLive($guest);

        $this->dispatch('guest-bring-on', identity: $guest->getLivekitIdentity(), name: $guest->name, guestId: $guest->id);

        unset($this->guests);
        $this->dispatchGuestSlotMap();
    }

    public function takeGuestOff(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        app(LiveEventService::class)->markGuestOffAir($guest);

        $this->dispatch('guest-take-off', identity: $guest->getLivekitIdentity(), guestId: $guest->id);

        unset($this->guests);
        $this->dispatchGuestSlotMap();
    }

    public function refreshGuestStatuses(): void
    {
        unset($this->guests);
        $this->dispatchGuestSlotMap();
    }

    private function dispatchGuestSlotMap(): void
    {
        $map = $this->guests->mapWithKeys(
            fn ($g) => [$g->getLivekitIdentity() => 'guest-' . $g->slot_number]
        )->toArray();

        $this->dispatch('update-guest-slot-map', map: $map);
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
                                <div class="mt-4" x-data="{ selectedTemplate: '' }">
                                    <div class="flex items-center gap-2">
                                        <flux:select x-model="selectedTemplate" size="sm" class="flex-1" placeholder="{{ __('Load template...') }}">
                                            @foreach ($this->sceneTemplates as $template)
                                                <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" x-on:click="if (selectedTemplate) $wire.loadTemplate(parseInt(selectedTemplate))">
                                            {{ __('Load') }}
                                        </flux:button>
                                    </div>
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

                        {{-- Overlay Editor --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="sm" class="mb-3">{{ __('Overlays') }}</flux:heading>

                            {{-- Current overlays --}}
                            @if (! empty($event->overlays))
                                <div class="mb-3 space-y-2">
                                    @foreach ($event->overlays as $index => $overlay)
                                        <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-900">
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300 truncate">
                                                <flux:badge size="sm" color="zinc" class="mr-1">{{ ucfirst($overlay['type'] ?? 'text') }}</flux:badge>
                                                {{ $overlay['content'] ?? '' }}
                                            </span>
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeOverlay({{ $index }})" />
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <flux:text class="mb-3 text-sm">{{ __('No overlays configured.') }}</flux:text>
                            @endif

                            {{-- Add text overlay --}}
                            <form wire:submit="addTextOverlay" class="flex items-center gap-2">
                                <flux:input wire:model="overlayText" placeholder="{{ __('Overlay text...') }}" size="sm" class="flex-1" />
                                <flux:button type="submit" size="sm" variant="primary" icon="plus" wire:loading.attr="disabled">
                                    {{ __('Add') }}
                                </flux:button>
                            </form>
                            @error('overlayText') <span class="mt-1 text-xs text-red-500">{{ $message }}</span> @enderror

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
            $overlays = $event->overlays ?? [];
            $connectedGuests = $this->guests->whereIn('status', ['connected', 'live']);
        @endphp
        <div
            x-data="livekitProducer"
            x-init="$nextTick(() => {
                scenes = @js($scenes);
                liveSceneIndex = @js($activeSceneIndex);
                previewSceneIndex = @js($activeSceneIndex);
                overlayConfigs = @js($overlays);
                @if ($livekitToken)
                    startStudio(@js($livekitToken), @js($livekitUrl), @js($event->id));
                @endif
            })"
            class="flex h-[calc(100vh-4rem)] flex-col lg:flex-row"
        >
            {{-- Left column: Monitors + Sources + Scenes + Controls --}}
            <div class="flex flex-1 flex-col overflow-hidden">
                {{-- Header bar --}}
                <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-900 px-4 py-2">
                    <div class="flex items-center gap-3">
                        <span class="relative flex size-3">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex size-3 rounded-full bg-red-500"></span>
                        </span>
                        <flux:heading size="sm" class="text-white">{{ $event->title }}</flux:heading>
                        <flux:badge color="red" size="sm">{{ __('LIVE') }}</flux:badge>
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
                    <div class="relative flex-1 overflow-hidden rounded-lg border-2 border-green-500/40 bg-black">
                        <canvas x-ref="previewCanvas" class="h-full w-full object-contain" width="1280" height="720"></canvas>
                        <span class="absolute top-2 left-2 rounded bg-green-600/90 px-2 py-0.5 text-[10px] font-bold text-white tracking-wider">PREVIEW</span>
                        <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px] text-zinc-300" x-text="scenes[previewSceneIndex]?.name ?? 'Solo'"></span>
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

                {{-- Guest Controls --}}
                @if ($this->guests->isNotEmpty())
                    <div class="flex items-center gap-2 overflow-x-auto border-t border-zinc-700 bg-zinc-800 px-3 py-2">
                        <span class="mr-1 shrink-0 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Guests') }}</span>
                        @foreach ($this->guests as $guest)
                            <div wire:key="live-guest-{{ $guest->id }}" class="flex shrink-0 items-center gap-1.5 rounded-lg bg-zinc-900 px-2.5 py-1.5">
                                <span class="text-xs font-medium text-zinc-300">{{ $guest->name }}</span>
                                <flux:badge size="sm" :color="match($guest->status) {
                                    'connected' => 'amber',
                                    'live' => 'green',
                                    'disconnected' => 'zinc',
                                    default => 'zinc',
                                }">
                                    {{ ucfirst($guest->status) }}
                                </flux:badge>
                                @if ($guest->isConnected())
                                    <flux:button size="xs" variant="primary" wire:click="bringGuestOn({{ $guest->id }})">
                                        {{ __('Bring On') }}
                                    </flux:button>
                                @elseif ($guest->isLive())
                                    <flux:button size="xs" variant="ghost" wire:click="takeGuestOff({{ $guest->id }})">
                                        {{ __('Take Off') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endforeach
                        <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="refreshGuestStatuses" class="shrink-0" title="{{ __('Refresh') }}" />
                    </div>
                @endif

                {{-- Controls bar --}}
                <div class="flex items-center justify-center gap-3 border-t border-zinc-700 bg-zinc-900 px-4 py-3">
                    {{-- Mic toggle --}}
                    <button type="button" @click="toggleAudio()"
                        class="flex size-11 items-center justify-center rounded-full transition-colors"
                        :class="audioEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                    >
                        <flux:icon name="microphone" class="size-5" />
                    </button>

                    {{-- Camera toggle --}}
                    <button type="button" @click="toggleVideo()"
                        class="flex size-11 items-center justify-center rounded-full transition-colors"
                        :class="videoEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                    >
                        <flux:icon name="video-camera" class="size-5" />
                    </button>

                    {{-- Screen share --}}
                    <button type="button" @click="toggleScreenShare()"
                        class="flex size-11 items-center justify-center rounded-full transition-colors"
                        :class="screenStream ? 'bg-purple-600 text-white' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'"
                    >
                        <flux:icon name="computer-desktop" class="size-5" />
                    </button>

                    <div class="mx-2 h-6 w-px bg-zinc-700"></div>

                    {{-- End Stream --}}
                    <flux:button wire:click="endStream"
                        wire:confirm="{{ __('Are you sure you want to end the stream?') }}"
                        variant="danger" size="sm" icon="x-mark">
                        {{ __('End Stream') }}
                    </flux:button>
                </div>
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

                        @if ($this->pendingQuestions->isEmpty())
                            <div class="rounded-lg bg-zinc-50 p-4 text-center dark:bg-zinc-900">
                                <flux:icon name="chat-bubble-left-right" class="mx-auto mb-2 size-6 text-zinc-400" />
                                <flux:text class="text-sm">{{ __('No questions yet.') }}</flux:text>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($this->pendingQuestions as $question)
                                    <div wire:key="q-{{ $question->id }}" class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
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
            let peerManager = null;
            let studioChannel = null;

            return {
                connected: false,
                audioEnabled: true,
                videoEnabled: true,
                viewerCount: 0,
                connectionError: null,
                sources: [],
                previewSceneIndex: 0,
                liveSceneIndex: 0,
                scenes: [],
                overlayConfigs: [],
                screenStream: null,

                init() {
                    this.$wire.on('livekit-disconnect', () => this.disconnect());

                    this.$wire.on('guest-bring-on', ({ identity, name, guestId }) => {
                        if (studioChannel) {
                            studioChannel.whisper('bring-on', { guestId });
                            console.log('[Studio] Bring-on whisper sent to guest:', guestId, name);
                        } else {
                            console.warn('[Studio] No studio channel — cannot signal guest');
                        }
                    });

                    this.$wire.on('guest-take-off', ({ identity, guestId }) => {
                        if (studioChannel) {
                            studioChannel.whisper('take-off', { guestId });
                        }
                        const slotKey = this._guestSlotKey(guestId);
                        if (sourceManager) sourceManager.removeSource(slotKey);
                        console.log('[Studio] Guest taken off:', slotKey);
                    });
                },

                // ==============================================================
                // Studio startup
                // ==============================================================

                async startStudio(livekitToken, livekitUrl, eventId) {
                    try {
                        this.connectionError = null;

                        // 1. Initialize SourceManager
                        const SourceManager = await window.loadSourceManager();
                        sourceManager = new SourceManager(this.$refs.sourceContainer);

                        // Update reactive sources array on changes
                        const syncSources = () => {
                            this.sources = sourceManager.getAllSources();
                        };
                        sourceManager.on('source-added', syncSources);
                        sourceManager.on('source-ready', syncSources);
                        sourceManager.on('source-removed', syncSources);
                        sourceManager.on('source-lost', syncSources);

                        // 2. Get local camera and register as 'host' source
                        const cameraStream = await navigator.mediaDevices.getUserMedia({
                            video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                            audio: { echoCancellation: true, noiseSuppression: true },
                        });
                        sourceManager.addSource('host', cameraStream, 'You (Host)');

                        // 3. Initialize dual compositors
                        const FrameCompositor = await window.loadFrameCompositor();

                        previewCompositor = new FrameCompositor(this.$refs.previewCanvas, sourceManager);
                        programCompositor = new FrameCompositor(this.$refs.programCanvas, sourceManager);

                        const scene = this.scenes[this.liveSceneIndex] ?? this.scenes[0];
                        previewCompositor.setScene(scene);
                        programCompositor.setScene(scene);

                        if (this.overlayConfigs.length > 0) {
                            programCompositor.setOverlays(this.overlayConfigs);
                            previewCompositor.setOverlays(this.overlayConfigs);
                        }

                        previewCompositor.start();
                        programCompositor.start();
                        console.log('[Studio] Compositors started');

                        // 4. Connect to LiveKit (broadcast output only)
                        const { Room, RoomEvent } = await window.loadLiveKit();
                        room = new Room({ adaptiveStream: true, dynacast: true });

                        room.on(RoomEvent.ParticipantConnected, () => this._updateViewerCount());
                        room.on(RoomEvent.ParticipantDisconnected, () => this._updateViewerCount());
                        room.on(RoomEvent.Disconnected, () => { this.connected = false; });

                        await room.connect(livekitUrl, livekitToken);

                        // 5. Publish PROGRAM canvas to LiveKit (ONE TIME)
                        const canvasStream = programCompositor.getOutputStream();
                        const canvasTrack = canvasStream.getVideoTracks()[0];
                        await room.localParticipant.publishTrack(canvasTrack, {
                            name: 'program-output',
                            simulcast: false,
                        });
                        console.log('[Broadcast] Program canvas published to LiveKit');

                        // 6. Publish host audio directly to LiveKit
                        const audioTrack = cameraStream.getAudioTracks()[0];
                        if (audioTrack) {
                            await room.localParticipant.publishTrack(audioTrack, {
                                name: 'host-mic',
                            });
                            console.log('[Broadcast] Host audio published to LiveKit');
                        }

                        // 7. Set up PeerManager for guest WebRTC connections
                        await this._setupPeerManager(eventId);

                        this.connected = true;
                        this._updateViewerCount();
                        console.log('[Studio] Studio fully initialized');

                    } catch (err) {
                        console.error('[Studio] Startup error:', err);
                        this.connectionError = 'Failed to start studio: ' + err.message;
                    }
                },

                // ==============================================================
                // PeerManager setup (guest WebRTC connections)
                // ==============================================================

                async _setupPeerManager(eventId) {
                    const PeerManager = await window.loadPeerManager();

                    // Use the global Echo instance (authenticated as the host via standard Laravel auth).
                    // The event-studio channel is registered in channels.php for internal users.
                    // Guests use a separate Echo instance with custom auth (EventGuestAuthController).
                    if (!window.Echo) {
                        console.warn('[Studio] Echo not available, guest connections disabled');
                        return;
                    }

                    studioChannel = window.Echo.join(`event-studio.${eventId}`)
                        .here((users) => {
                            console.log('[Studio] Studio channel subscribed. Users:', users);
                        })
                        .joining((user) => {
                            console.log('[Studio] User joined studio channel:', user);
                        })
                        .leaving((user) => {
                            console.log('[Studio] User left studio channel:', user);
                        })
                        .error((error) => {
                            console.error('[Studio] Studio channel subscription FAILED:', error);
                        });
                    peerManager = new PeerManager(studioChannel, 'host');

                    // When guest video arrives via WebRTC peer connection
                    peerManager.onRemoteStream(({ guestId, stream }) => {
                        const slotKey = this._guestSlotKey(guestId);
                        const guest = this._findGuest(guestId);
                        const name = guest?.name ?? guestId;

                        sourceManager.addSource(slotKey, stream, name);
                        console.log(`[Studio] Guest video registered: ${slotKey} (${name})`);

                        // Also publish guest audio to LiveKit for viewers
                        const audioTrack = stream.getAudioTracks()[0];
                        if (audioTrack && room) {
                            room.localParticipant.publishTrack(audioTrack, {
                                name: `${slotKey}-audio`,
                            }).then(() => {
                                console.log(`[Broadcast] Guest audio published: ${slotKey}`);
                            }).catch(err => {
                                console.warn(`[Broadcast] Failed to publish guest audio:`, err);
                            });
                        }
                    });

                    peerManager.onGuestReady(({ guestId, name }) => {
                        console.log(`[Studio] Guest ready in green room: ${name}`);
                        this.$wire.refreshGuestStatuses();
                    });

                    peerManager.onGuestDisconnect(({ guestId }) => {
                        const slotKey = this._guestSlotKey(guestId);
                        if (sourceManager) sourceManager.removeSource(slotKey);
                        this.$wire.refreshGuestStatuses();
                    });
                },

                // ==============================================================
                // Preview / Program / TAKE
                // ==============================================================

                previewScene(index) {
                    this.previewSceneIndex = index;
                    if (previewCompositor) {
                        previewCompositor.setScene(this.scenes[index]);
                    }
                },

                take() {
                    this.liveSceneIndex = this.previewSceneIndex;
                    if (programCompositor) {
                        programCompositor.setScene(this.scenes[this.liveSceneIndex]);
                    }
                    this.$wire.switchScene(this.liveSceneIndex);
                    console.log('[Studio] TAKE:', this.scenes[this.liveSceneIndex]?.name);
                },

                // ==============================================================
                // Controls
                // ==============================================================

                async toggleAudio() {
                    this.audioEnabled = !this.audioEnabled;
                    if (room) {
                        await room.localParticipant.setMicrophoneEnabled(this.audioEnabled);
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
                        this.screenStream.getTracks().forEach(t => t.stop());
                        this.screenStream = null;
                        if (sourceManager) sourceManager.removeSource('screen');
                        return;
                    }

                    try {
                        this.screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                        if (sourceManager) {
                            sourceManager.addSource('screen', this.screenStream, 'Screen Share');
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
                    // guestId from PeerManager is the livekit_identity string
                    // We check against the Livewire guests data for the name
                    return null;
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
                    if (peerManager) { peerManager.destroy(); peerManager = null; }
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

