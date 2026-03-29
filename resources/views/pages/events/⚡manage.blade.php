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

    public function switchScene(int $index): void
    {
        $scenes = $this->event->getScenesOrDefaults();

        if (! isset($scenes[$index])) {
            return;
        }

        $this->event->update(['active_scene_index' => $index]);
        $this->event->refresh();

        $this->dispatch('scene-switched', scene: $scenes[$index], index: $index);
    }

    public function bringGuestOn(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        app(LiveEventService::class)->markGuestLive($guest);

        $this->dispatch('guest-bring-on', identity: $guest->getLivekitIdentity(), name: $guest->name, guestId: $guest->id);

        unset($this->guests);
    }

    public function takeGuestOff(int $guestId): void
    {
        $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

        app(LiveEventService::class)->markGuestOffAir($guest);

        $this->dispatch('guest-take-off', identity: $guest->getLivekitIdentity(), guestId: $guest->id);

        unset($this->guests);
    }

    public function refreshGuestStatuses(): void
    {
        unset($this->guests);
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
            $activeScene = $scenes[$activeSceneIndex] ?? $scenes[0] ?? null;
            $overlays = $event->overlays ?? [];
            $liveGuests = $this->guests->where('status', 'live');
            $connectedGuests = $this->guests->whereIn('status', ['connected', 'live']);
            // Map livekit_identity → slot source key (e.g., "guest-abc123" → "guest-1")
            $guestSlotMap = $this->guests->mapWithKeys(fn ($g) => [$g->livekit_identity => 'guest-' . $g->slot_number])->toArray();
        @endphp
        <div
            x-data="livekitProducer"
            x-init="$nextTick(() => {
                activeSceneIndex = @js($activeSceneIndex);
                scenes = @js($scenes);
                overlayConfigs = @js($overlays);
                frameModeEnabled = @js($event->use_frame_mode);
                guestSlotMap = @js($guestSlotMap);
                @if ($livekitToken)
                    connect(@js($livekitToken), @js($livekitUrl));
                @endif
            })"
            class="flex h-[calc(100vh-4rem)] flex-col lg:flex-row"
        >
            {{-- Left column: Video + Feeds + Scenes + Controls --}}
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
                {{-- PROGRAM MONITOR — what viewers see --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="relative bg-black" style="aspect-ratio: 16/9; max-height: 45vh;">
                    {{-- Canvas compositor output --}}
                    <canvas
                        x-ref="compositorCanvas"
                        x-show="frameModeEnabled"
                        wire:ignore
                        class="h-full w-full object-contain"
                        width="1280"
                        height="720"
                    ></canvas>

                    {{-- Raw camera view (when frame mode is off) --}}
                    <div x-ref="hostVideo" x-show="!frameModeEnabled" wire:ignore class="flex h-full w-full items-center justify-center">
                        <div x-show="!connected" class="flex flex-col items-center justify-center">
                            <div class="mb-4 size-16 animate-pulse rounded-full bg-zinc-700"></div>
                            <span class="text-sm text-zinc-400">{{ __('Connecting...') }}</span>
                        </div>
                    </div>

                    {{-- Connection error --}}
                    <div x-show="connectionError" x-cloak class="absolute inset-0 flex items-center justify-center bg-black/80">
                        <flux:icon name="exclamation-triangle" class="mx-auto mb-2 size-8 text-red-400" />
                        <span class="text-sm text-red-400" x-text="connectionError"></span>
                    </div>

                    {{-- Badges --}}
                    <div class="absolute top-2 left-2 flex items-center gap-2">
                        <span class="rounded bg-red-600/90 px-2 py-0.5 text-[10px] font-bold text-white tracking-wider">PROGRAM</span>
                        <span x-show="frameModeEnabled" x-cloak class="rounded bg-purple-600/80 px-2 py-0.5 text-[10px] font-medium text-white">FRAME</span>
                    </div>
                    <div class="absolute top-2 right-2">
                        <span class="rounded bg-black/60 px-2 py-0.5 text-[10px] text-zinc-300" x-text="scenes[activeSceneIndex]?.name ?? 'Solo'"></span>
                    </div>
                </div>

                {{-- ═══════════════════════════════════════════ --}}
                {{-- SOURCE MONITORS — all available feeds --}}
                {{-- ═══════════════════════════════════════════ --}}
                <div class="border-t border-zinc-700 bg-zinc-950 px-3 py-2 overflow-x-auto" wire:ignore>
                    <div class="flex items-center gap-1 mb-1.5">
                        <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Sources') }}</span>
                    </div>
                    <div class="flex items-start gap-2">
                        {{-- Host camera source --}}
                        <div class="shrink-0 w-40">
                            <div
                                x-ref="hostThumb"
                                class="relative overflow-hidden rounded border-2 bg-zinc-800 transition-colors"
                                style="aspect-ratio: 16/9;"
                                :class="isFeedInScene('host') ? 'border-green-500 shadow-[0_0_8px_rgba(34,197,94,0.3)]' : 'border-zinc-700'"
                            >
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-[10px] text-zinc-600">{{ __('Camera') }}</span>
                                </div>
                            </div>
                            <div class="mt-1 flex items-center justify-between px-0.5">
                                <span class="text-[10px] font-semibold text-zinc-300">{{ __('You (Host)') }}</span>
                                <span x-show="isFeedInScene('host')" x-cloak class="text-[8px] font-bold text-green-400 uppercase">Live</span>
                            </div>
                        </div>

                        {{-- Guest feed sources --}}
                        <template x-for="(feed, slotKey) in guestFeeds" :key="slotKey">
                            <div class="shrink-0 w-40">
                                <div
                                    class="relative overflow-hidden rounded border-2 bg-zinc-800 transition-colors"
                                    style="aspect-ratio: 16/9;"
                                    :class="isFeedInScene(slotKey) ? 'border-green-500 shadow-[0_0_8px_rgba(34,197,94,0.3)]' : 'border-zinc-700'"
                                >
                                    <video
                                        autoplay muted playsinline
                                        class="absolute inset-0 w-full h-full object-cover"
                                        x-effect="
                                            const s = guestFeeds[slotKey]?.stream;
                                            if (s && $el.srcObject !== s) {
                                                $el.srcObject = s;
                                                $el.play().catch(() => {});
                                            }
                                        "
                                    ></video>
                                </div>
                                <div class="mt-1 flex items-center justify-between px-0.5">
                                    <span class="text-[10px] font-semibold text-zinc-300" x-text="feed.name"></span>
                                    <span x-show="isFeedInScene(slotKey)" x-cloak class="text-[8px] font-bold text-green-400 uppercase">Live</span>
                                </div>
                            </div>
                        </template>

                        {{-- Screen share source --}}
                        <div x-show="screenStream" x-cloak class="shrink-0 w-40">
                            <div
                                class="relative overflow-hidden rounded border-2 bg-zinc-800 transition-colors"
                                style="aspect-ratio: 16/9;"
                                :class="isFeedInScene('screen') ? 'border-green-500 shadow-[0_0_8px_rgba(34,197,94,0.3)]' : 'border-zinc-700'"
                            >
                                <video x-ref="screenThumb" autoplay muted playsinline class="absolute inset-0 w-full h-full object-cover"></video>
                            </div>
                            <div class="mt-1 flex items-center justify-between px-0.5">
                                <span class="text-[10px] font-semibold text-purple-300">{{ __('Screen Share') }}</span>
                                <span x-show="isFeedInScene('screen')" x-cloak class="text-[8px] font-bold text-green-400 uppercase">Live</span>
                            </div>
                        </div>

                        {{-- Empty slot placeholder when no guests --}}
                        <div x-show="Object.keys(guestFeeds).length === 0" class="shrink-0 w-40">
                            <div class="relative overflow-hidden rounded border-2 border-dashed border-zinc-700 bg-zinc-900" style="aspect-ratio: 16/9;">
                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                    <svg class="w-5 h-5 text-zinc-700 mb-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                                    <span class="text-[9px] text-zinc-600">{{ __('No guests connected') }}</span>
                                </div>
                            </div>
                            <span class="mt-1 block text-[10px] text-zinc-600 px-0.5">{{ __('Invite guests above') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Scene Switcher Bar --}}
                <div class="flex items-center gap-1.5 border-t border-zinc-700 bg-zinc-900 px-3 py-2">
                    <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Scenes') }}</span>
                    @foreach ($scenes as $index => $scene)
                        <button
                            type="button"
                            wire:click="switchScene({{ $index }})"
                            class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                            :class="activeSceneIndex === {{ $index }}
                                ? 'bg-blue-600 text-white'
                                : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'"
                        >
                            {{ $scene['name'] }}
                        </button>
                    @endforeach
                </div>

                {{-- Guest Controls (below scene switcher) --}}
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

                {{-- Overlay Quick Controls --}}
                @if (! empty($overlays))
                    <div class="flex items-center gap-2 overflow-x-auto border-t border-zinc-700 bg-zinc-800 px-3 py-1.5">
                        <span class="mr-1 shrink-0 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Overlays') }}</span>
                        @foreach ($overlays as $idx => $overlay)
                            <div class="flex shrink-0 items-center gap-1 rounded bg-zinc-900 px-2 py-1">
                                <span class="max-w-[120px] truncate text-[11px] text-zinc-400">{{ $overlay['content'] ?? $overlay['type'] }}</span>
                                <button
                                    type="button"
                                    @click="toggleOverlayVisibility({{ $idx }})"
                                    class="rounded p-0.5 transition-colors"
                                    :class="(overlayVisible[{{ $idx }}] ?? true) ? 'text-green-400 hover:text-green-300' : 'text-zinc-600 hover:text-zinc-500'"
                                >
                                    <flux:icon name="eye" variant="micro" class="size-3.5" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Controls bar --}}
                <div class="flex items-center justify-center gap-3 border-t border-zinc-700 bg-zinc-900 px-4 py-3">
                    {{-- Toggle Audio --}}
                    <div class="relative">
                        <button
                            type="button"
                            @click="toggleAudio()"
                            class="flex size-11 items-center justify-center rounded-full transition-colors"
                            :class="audioEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                            :title="audioEnabled ? '{{ __('Mute') }}' : '{{ __('Unmute') }}'"
                        >
                            <template x-if="audioEnabled">
                                <flux:icon name="microphone" class="size-5" />
                            </template>
                            <template x-if="!audioEnabled">
                                <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" /></svg>
                            </template>
                        </button>
                        <span
                            x-show="audioEnabled && connected"
                            x-transition
                            class="absolute -top-0.5 -right-0.5 flex size-3"
                        >
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex size-3 rounded-full bg-green-500"></span>
                        </span>
                    </div>

                    {{-- Toggle Video --}}
                    <button
                        type="button"
                        @click="toggleVideo()"
                        class="flex size-11 items-center justify-center rounded-full transition-colors"
                        :class="videoEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                        :title="videoEnabled ? '{{ __('Turn off camera') }}' : '{{ __('Turn on camera') }}'"
                    >
                        <template x-if="videoEnabled">
                            <flux:icon name="video-camera" class="size-5" />
                        </template>
                        <template x-if="!videoEnabled">
                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18" /></svg>
                        </template>
                    </button>

                    {{-- Screen Share --}}
                    <button
                        type="button"
                        @click="toggleScreenShare()"
                        class="flex size-11 items-center justify-center rounded-full transition-colors"
                        :class="screenStream ? 'bg-purple-600 text-white hover:bg-purple-700' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'"
                        :title="screenStream ? '{{ __('Stop sharing') }}' : '{{ __('Share screen') }}'"
                    >
                        <flux:icon name="computer-desktop" class="size-5" />
                    </button>

                    {{-- Frame Mode Toggle --}}
                    <button
                        type="button"
                        @click="toggleFrameMode()"
                        class="flex size-11 items-center justify-center rounded-full transition-colors"
                        :class="frameModeEnabled ? 'bg-purple-600 text-white hover:bg-purple-700' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'"
                        :title="frameModeEnabled ? '{{ __('Switch to camera') }}' : '{{ __('Switch to frame mode') }}'"
                    >
                        <flux:icon name="photo" class="size-5" />
                    </button>

                    {{-- Device Switcher --}}
                    <div class="relative">
                        <button
                            type="button"
                            @click="showDeviceMenu = !showDeviceMenu"
                            class="flex size-11 items-center justify-center rounded-full bg-zinc-700 text-zinc-300 hover:bg-zinc-600 transition-colors"
                            title="{{ __('Switch devices') }}"
                        >
                            <flux:icon name="cog-6-tooth" class="size-5" />
                        </button>
                        <div
                            x-show="showDeviceMenu"
                            x-cloak
                            @click.outside="showDeviceMenu = false"
                            x-transition
                            class="absolute bottom-14 right-0 w-64 bg-zinc-800 border border-zinc-600 rounded-lg shadow-xl p-3 space-y-3 z-50"
                        >
                            <div>
                                <label class="block text-xs font-medium text-zinc-400 mb-1">{{ __('Camera') }}</label>
                                <select
                                    @change="switchCamera($event.target.value)"
                                    class="w-full bg-zinc-700 border border-zinc-600 text-sm text-white rounded-md px-2 py-1.5"
                                >
                                    <template x-for="cam in cameras" :key="cam.id">
                                        <option :value="cam.id" :selected="cam.id === activeCameraId" x-text="cam.label"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-zinc-400 mb-1">{{ __('Microphone') }}</label>
                                <select
                                    @change="switchMicrophone($event.target.value)"
                                    class="w-full bg-zinc-700 border border-zinc-600 text-sm text-white rounded-md px-2 py-1.5"
                                >
                                    <template x-for="mic in microphones" :key="mic.id">
                                        <option :value="mic.id" :selected="mic.id === activeMicId" x-text="mic.label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mx-2 h-6 w-px bg-zinc-700"></div>

                    {{-- End Stream --}}
                    <flux:button
                        wire:click="endStream"
                        wire:confirm="{{ __('Are you sure you want to end the stream? All viewers will be disconnected.') }}"
                        wire:loading.attr="disabled"
                        variant="danger"
                        size="sm"
                        icon="x-mark"
                    >
                        <span wire:loading.remove wire:target="endStream">{{ __('End Stream') }}</span>
                        <span wire:loading wire:target="endStream">{{ __('Ending...') }}</span>
                    </flux:button>
                </div>
            </div>

            {{-- Right column: Tabbed sidebar --}}
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
            let compositor = null;
            let hostVideoEl = null;

            return {
                connected: false,
                audioEnabled: true,
                videoEnabled: true,
                viewerCount: 0,
                connectionError: null,
                cameras: [],
                microphones: [],
                activeCameraId: '',
                activeMicId: '',
                showDeviceMenu: false,
                guestFeeds: {},
                guestSlotMap: {},
                activeSceneIndex: 0,
                scenes: [],
                overlayConfigs: [],
                overlayVisible: {},
                frameModeEnabled: false,
                screenStream: null,

                init() {
                    this.$wire.on('livekit-disconnect', () => {
                        this.disconnect();
                    });

                    this.$wire.on('scene-switched', ({ scene, index }) => {
                        this.activeSceneIndex = index;
                        if (compositor) {
                            compositor.setScene(scene);
                        }
                    });

                    // Re-sync overlays when they change via Livewire
                    this.$wire.$watch('event.overlays', (newOverlays) => {
                        if (newOverlays) {
                            this.overlayConfigs = newOverlays;
                            if (compositor) {
                                compositor.setOverlays(newOverlays);
                            }
                            // Reset visibility tracking
                            this.overlayVisible = {};
                            newOverlays.forEach((_, idx) => {
                                this.overlayVisible[idx] = true;
                            });
                        }
                    });

                    this.$wire.on('guest-bring-on', ({ identity, name, guestId }) => {
                        if (room) {
                            const encoder = new TextEncoder();
                            const data = encoder.encode(JSON.stringify({
                                type: 'bring-on',
                                guestId: guestId,
                                identity: identity,
                            }));
                            room.localParticipant.publishData(data, { reliable: true });
                        }
                    });

                    this.$wire.on('guest-take-off', ({ identity, guestId }) => {
                        if (room) {
                            const encoder = new TextEncoder();
                            const data = encoder.encode(JSON.stringify({
                                type: 'take-off',
                                guestId: guestId,
                                identity: identity,
                            }));
                            room.localParticipant.publishData(data, { reliable: true });
                        }
                    });

                    // Initialize overlay visibility state
                    this.overlayConfigs.forEach((overlay, idx) => {
                        this.overlayVisible[idx] = overlay.visible !== false;
                    });
                },

                async connect(token, url) {
                    try {
                        this.connectionError = null;
                        const { Room, RoomEvent, VideoPresets, Track } = await window.loadLiveKit();
                        room = new Room({
                            adaptiveStream: true,
                            dynacast: true,
                            videoCaptureDefaults: {
                                resolution: VideoPresets.h720.resolution,
                            },
                        });

                        room.on(RoomEvent.ParticipantConnected, () => this.updateViewerCount());
                        room.on(RoomEvent.ParticipantDisconnected, (participant) => {
                            this.updateViewerCount();
                            const identity = participant.identity;
                            if (identity && identity.startsWith('guest-')) {
                                const slotKey = this.guestSlotMap[identity] || identity;
                                const { [slotKey]: _, ...rest } = this.guestFeeds;
                                this.guestFeeds = rest;
                                if (compositor) {
                                    compositor.removeFeedSource(slotKey);
                                }
                            }
                        });
                        room.on(RoomEvent.Disconnected, () => {
                            this.connected = false;
                        });

                        // Handle remote tracks (guest video feeds)
                        room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
                            const identity = participant.identity;
                            if (!identity || !identity.startsWith('guest-')) return;

                            if (track.kind === 'video') {
                                const el = track.attach();
                                el.autoplay = true;
                                el.muted = true;
                                el.playsInline = true;
                                el.style.width = '100%';
                                el.style.height = '100%';
                                el.style.objectFit = 'cover';

                                // Map identity to slot source key (e.g., "guest-abc123" → "guest-1")
                                const slotKey = this.guestSlotMap[identity] || identity;

                                // Store the MediaStream — cloneNode does NOT copy streams
                                const stream = track.mediaStream || el.srcObject;

                                // Reassign entire object for Alpine reactivity
                                this.guestFeeds = {
                                    ...this.guestFeeds,
                                    [slotKey]: {
                                        element: el,
                                        stream: stream,
                                        identity: identity,
                                        name: participant.name || identity,
                                    },
                                };

                                // Register with compositor using the slot key that matches scene definitions
                                if (compositor) {
                                    compositor.setFeedSource(slotKey, el);
                                }
                            }

                            if (track.kind === 'audio') {
                                const audioEl = track.attach();
                                audioEl.style.display = 'none';
                                document.body.appendChild(audioEl);
                            }
                        });

                        room.on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
                            const identity = participant.identity;
                            if (!identity || !identity.startsWith('guest-')) return;

                            track.detach().forEach(el => el.remove());

                            if (track.kind === 'video') {
                                const slotKey = this.guestSlotMap[identity] || identity;
                                const { [slotKey]: _, ...rest } = this.guestFeeds;
                                this.guestFeeds = rest;
                                if (compositor) {
                                    compositor.removeFeedSource(slotKey);
                                }
                            }
                        });

                        // Handle data messages from guests
                        room.on(RoomEvent.DataReceived, (payload, participant) => {
                            try {
                                const decoder = new TextDecoder();
                                const msg = JSON.parse(decoder.decode(payload));
                                if (msg.type === 'going-live' || msg.type === 'not-ready') {
                                    this.$wire.refreshGuestStatuses();
                                }
                            } catch (e) {
                                // Ignore malformed messages
                            }
                        });

                        await room.connect(url, token);
                        await room.localParticipant.setCameraEnabled(true);
                        await room.localParticipant.setMicrophoneEnabled(true);

                        const videoEl = this.$refs.hostVideo;
                        let videoAttached = false;

                        const attachVideo = (track) => {
                            if (videoAttached) return;
                            videoAttached = true;
                            const el = track.attach();
                            el.autoplay = true;
                            el.muted = true;
                            el.playsInline = true;
                            el.style.width = '100%';
                            el.style.height = '100%';
                            el.style.objectFit = 'cover';
                            el.style.transform = 'scaleX(-1)';
                            videoEl.innerHTML = '';
                            videoEl.appendChild(el);
                            el.play().catch(() => {});

                            hostVideoEl = el;

                            // Feed host video to compositor
                            if (compositor) {
                                compositor.setFeedSource('host', el);
                            }

                            // Clone for thumbnail
                            this.$nextTick(() => {
                                const thumbContainer = this.$refs.hostThumb;
                                if (thumbContainer) {
                                    const thumbEl = el.cloneNode(true);
                                    thumbEl.style.transform = 'scaleX(-1)';
                                    thumbEl.style.objectFit = 'cover';
                                    thumbEl.muted = true;
                                    thumbContainer.innerHTML = '';
                                    thumbContainer.appendChild(thumbEl);
                                    thumbEl.play().catch(() => {});
                                }
                            });
                        };

                        room.localParticipant.on('localTrackPublished', (pub) => {
                            if (pub.track && pub.track.kind === 'video') {
                                attachVideo(pub.track);
                            }
                        });

                        room.localParticipant.on('localTrackUnpublished', () => {
                            videoAttached = false;
                        });

                        room.localParticipant.videoTrackPublications.forEach((pub) => {
                            if (pub.track) attachVideo(pub.track);
                        });

                        this.connected = true;
                        this.updateViewerCount();
                        await this.loadDevices();

                        // Initialize FrameCompositor if available and frame mode is on
                        if (this.frameModeEnabled) {
                            this.initCompositor();
                        }
                    } catch (err) {
                        console.error('LiveKit connection error:', err);
                        this.connectionError = 'Failed to connect: ' + err.message;
                    }
                },

                async initCompositor() {
                    try {
                        const FrameCompositor = await window.loadFrameCompositor();
                        const canvas = this.$refs.compositorCanvas;
                        if (!canvas) {
                            console.warn('Compositor canvas element not found');
                            return;
                        }

                        compositor = new FrameCompositor(canvas);

                        // Load active scene
                        const scene = this.scenes[this.activeSceneIndex];
                        if (scene) {
                            compositor.setScene(scene);
                        }

                        // Load overlays
                        if (this.overlayConfigs && this.overlayConfigs.length > 0) {
                            compositor.setOverlays(this.overlayConfigs);
                        }

                        // Register host feed — need to get the raw camera stream
                        // even though we might not be publishing it to LiveKit
                        if (hostVideoEl) {
                            compositor.setFeedSource('host', hostVideoEl);
                        } else if (room) {
                            // Try to get host video from local participant's tracks
                            room.localParticipant.videoTrackPublications.forEach((pub) => {
                                if (pub.track) {
                                    const el = pub.track.attach();
                                    el.muted = true;
                                    hostVideoEl = el;
                                    compositor.setFeedSource('host', el);
                                }
                            });
                        }

                        // Register existing guest feeds (already mapped to slot keys)
                        Object.entries(this.guestFeeds).forEach(([slotKey, feed]) => {
                            if (feed.element) {
                                compositor.setFeedSource(slotKey, feed.element);
                            }
                        });

                        // Register screen share if active
                        if (this.screenStream) {
                            const screenEl = document.createElement('video');
                            screenEl.srcObject = this.screenStream;
                            screenEl.autoplay = true;
                            screenEl.muted = true;
                            screenEl.playsInline = true;
                            await screenEl.play();
                            compositor.setFeedSource('screen', screenEl);
                        }

                        compositor.start();
                        console.log('FrameCompositor initialized and running');
                    } catch (e) {
                        console.warn('FrameCompositor not available:', e.message);
                    }
                },

                updateViewerCount() {
                    if (room) {
                        this.viewerCount = room.remoteParticipants.size;
                        this.$wire.set('viewerCount', this.viewerCount);
                    }
                },

                async toggleAudio() {
                    if (!room) return;
                    this.audioEnabled = !this.audioEnabled;
                    await room.localParticipant.setMicrophoneEnabled(this.audioEnabled);
                },

                async toggleVideo() {
                    if (!room) return;
                    this.videoEnabled = !this.videoEnabled;
                    await room.localParticipant.setCameraEnabled(this.videoEnabled);
                },

                async loadDevices() {
                    try {
                        const devices = await navigator.mediaDevices.enumerateDevices();
                        this.cameras = devices
                            .filter(d => d.kind === 'videoinput')
                            .map(d => ({ id: d.deviceId, label: d.label || 'Camera ' + d.deviceId.slice(0, 4) }));
                        this.microphones = devices
                            .filter(d => d.kind === 'audioinput')
                            .map(d => ({ id: d.deviceId, label: d.label || 'Mic ' + d.deviceId.slice(0, 4) }));

                        if (room) {
                            room.localParticipant.videoTrackPublications.forEach(pub => {
                                if (pub.track) {
                                    const settings = pub.track.mediaStreamTrack?.getSettings();
                                    if (settings?.deviceId) this.activeCameraId = settings.deviceId;
                                }
                            });
                            room.localParticipant.audioTrackPublications.forEach(pub => {
                                if (pub.track) {
                                    const settings = pub.track.mediaStreamTrack?.getSettings();
                                    if (settings?.deviceId) this.activeMicId = settings.deviceId;
                                }
                            });
                        }
                    } catch (err) {
                        console.warn('Failed to enumerate devices:', err);
                    }
                },

                async switchCamera(deviceId) {
                    if (!room || deviceId === this.activeCameraId) return;
                    try {
                        await room.switchActiveDevice('videoinput', deviceId);
                        this.activeCameraId = deviceId;
                        this.showDeviceMenu = false;
                    } catch (err) {
                        console.error('Failed to switch camera:', err);
                    }
                },

                async switchMicrophone(deviceId) {
                    if (!room || deviceId === this.activeMicId) return;
                    try {
                        await room.switchActiveDevice('audioinput', deviceId);
                        this.activeMicId = deviceId;
                        this.showDeviceMenu = false;
                    } catch (err) {
                        console.error('Failed to switch microphone:', err);
                    }
                },

                async toggleScreenShare() {
                    if (this.screenStream) {
                        // Stop screen sharing
                        this.screenStream.getTracks().forEach(t => t.stop());
                        this.screenStream = null;
                        if (compositor) {
                            compositor.removeFeedSource('screen');
                        }
                        return;
                    }

                    try {
                        this.screenStream = await navigator.mediaDevices.getDisplayMedia({
                            video: true,
                            audio: false,
                        });

                        // Create a video element for the screen share
                        const screenVideoEl = document.createElement('video');
                        screenVideoEl.srcObject = this.screenStream;
                        screenVideoEl.autoplay = true;
                        screenVideoEl.muted = true;
                        screenVideoEl.playsInline = true;
                        await screenVideoEl.play();

                        // Feed to compositor
                        if (compositor) {
                            compositor.setFeedSource('screen', screenVideoEl);
                        }

                        // Set thumbnail
                        this.$nextTick(() => {
                            const thumbEl = this.$refs.screenThumb;
                            if (thumbEl) {
                                thumbEl.srcObject = this.screenStream;
                            }
                        });

                        // Detect when user stops sharing via browser UI
                        this.screenStream.getVideoTracks()[0].addEventListener('ended', () => {
                            this.screenStream = null;
                            if (compositor) {
                                compositor.removeFeedSource('screen');
                            }
                        });
                    } catch (err) {
                        console.warn('Screen share cancelled or failed:', err.message);
                    }
                },

                async toggleFrameMode() {
                    this.frameModeEnabled = !this.frameModeEnabled;
                    this.$wire.toggleFrameMode();

                    if (this.frameModeEnabled) {
                        // Start compositor if not already running
                        if (!compositor) {
                            await this.initCompositor();
                        }
                        // Short delay to let compositor render a few frames before publishing
                        await new Promise(r => setTimeout(r, 200));
                        // Replace published video track with canvas stream
                        await this.publishCanvasStream();
                    } else {
                        // Switch back to raw camera first, then clean up compositor
                        await this.publishRawCamera();
                        if (compositor) {
                            compositor.stop();
                            compositor.destroy();
                            compositor = null;
                        }
                    }
                },

                async publishCanvasStream() {
                    if (!room || !compositor) return;
                    try {
                        const canvasStream = compositor.getOutputStream();
                        const canvasTrack = canvasStream.getVideoTracks()[0];
                        if (!canvasTrack) {
                            console.warn('No video track from canvas stream');
                            return;
                        }

                        // Disable camera (stops publishing it) but keep the local track for compositor
                        await room.localParticipant.setCameraEnabled(false);

                        // Publish the canvas track as a custom video source
                        const { LocalVideoTrack } = await window.loadLiveKit();
                        const localTrack = new LocalVideoTrack(canvasTrack, 'composited-frame', false);
                        await room.localParticipant.publishTrack(localTrack, {
                            simulcast: false,
                            source: 'camera',
                        });

                        console.log('Canvas stream published to LiveKit');
                    } catch (err) {
                        console.error('Failed to publish canvas stream:', err);
                        // Fallback: if LocalVideoTrack isn't available, try raw track
                        try {
                            const canvasStream = compositor.getOutputStream();
                            const canvasTrack = canvasStream.getVideoTracks()[0];
                            if (canvasTrack) {
                                await room.localParticipant.setCameraEnabled(false);
                                await room.localParticipant.publishTrack(canvasTrack);
                            }
                        } catch (fallbackErr) {
                            console.error('Fallback canvas publish also failed:', fallbackErr);
                        }
                    }
                },

                async publishRawCamera() {
                    if (!room) return;
                    try {
                        // Unpublish all video tracks (canvas track)
                        const pubs = Array.from(room.localParticipant.videoTrackPublications.values());
                        for (const pub of pubs) {
                            if (pub.track) {
                                await room.localParticipant.unpublishTrack(pub.track, true);
                            }
                        }

                        // Re-enable camera
                        await room.localParticipant.setCameraEnabled(true);
                        console.log('Raw camera republished to LiveKit');
                    } catch (err) {
                        console.error('Failed to switch to raw camera:', err);
                    }
                },

                toggleOverlayVisibility(index) {
                    const newState = this.overlayVisible[index] === false;
                    this.overlayVisible[index] = newState;
                    if (compositor) {
                        compositor.toggleOverlay(index, newState);
                    }
                },

                isFeedInScene(slotKey) {
                    const scene = this.scenes[this.activeSceneIndex];
                    if (!scene || !scene.slots) return false;
                    // Check if this slot key matches any slot source in the active scene
                    return scene.slots.some(slot => slot.source === slotKey);
                },

                async disconnect() {
                    if (compositor) {
                        compositor.stop();
                        compositor.destroy();
                        compositor = null;
                    }
                    if (this.screenStream) {
                        this.screenStream.getTracks().forEach(t => t.stop());
                        this.screenStream = null;
                    }
                    if (room) {
                        await room.disconnect();
                        room = null;
                        this.connected = false;
                    }
                },

                destroy() {
                    this.disconnect();
                }
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

