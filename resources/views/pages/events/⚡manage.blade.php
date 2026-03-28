<?php

use App\Models\LiveEvent;
use App\Models\LiveEventQuestion;
use App\Models\LiveEventResource;
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

    public function boot(): void
    {
        if (auth()->user()?->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(LiveEvent $liveEvent): void
    {
        $this->event = $liveEvent->load(['questions', 'resources', 'registrations', 'serviceArea', 'service', 'host']);
        $this->livekitUrl = config('services.livekit.url') ?? '';
    }

    public function goLive(): void
    {
        if (! in_array($this->event->status, ['draft', 'scheduled'])) {
            session()->flash('error', __('This event cannot go live from its current status.'));

            return;
        }

        $liveEventService = app(LiveEventService::class);
        $liveEventService->goLive($this->event);

        $this->event->refresh();

        $liveKitService = app(LiveKitService::class);
        $token = $liveKitService->generateHostToken($this->event, auth()->user());

        $this->livekitToken = $token;

        $this->dispatch('livekit-connect', token: $token, url: $this->livekitUrl);
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

        $count = app(LiveKitService::class)->getParticipantCount($this->event->livekit_room_name);

        // Subtract 1 for the host
        $this->viewerCount = max(0, $count - 1);
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
                    <flux:button size="sm" variant="ghost" icon="pencil" :href="route('events.edit', $event)">
                        {{ __('Edit') }}
                    </flux:button>
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
                x-data="cameraPreview()"
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
    {{-- LIVE STATE --}}
    {{-- ============================================================ --}}
    @elseif ($event->status === 'live')
        <div
            x-data="livekitHost()"
            x-init="$nextTick(() => {
                // If we already have a token (page refresh while live), auto-connect
                @if ($livekitToken)
                    connect(@js($livekitToken), @js($livekitUrl));
                @endif
            })"
            wire:poll.10s="refreshViewerCount"
            class="flex h-[calc(100vh-4rem)] flex-col lg:flex-row"
        >
            {{-- Left column: Video + Controls --}}
            <div class="flex flex-1 flex-col overflow-hidden">
                {{-- Header bar --}}
                <div class="flex items-center justify-between border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center gap-3">
                        <span class="relative flex size-3">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex size-3 rounded-full bg-red-500"></span>
                        </span>
                        <flux:heading size="sm">{{ $event->title }}</flux:heading>
                        <flux:badge color="red" size="sm">{{ __('LIVE') }}</flux:badge>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="eye" variant="micro" />
                            <span x-text="viewerCount">{{ $viewerCount }}</span> {{ __('viewers') }}
                        </span>
                        @if ($event->started_at)
                            <span class="text-sm text-zinc-500 dark:text-zinc-400"
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
                    </div>
                </div>

                {{-- Video area --}}
                <div class="relative flex-1 bg-black">
                    <div x-ref="hostVideo" class="flex h-full w-full items-center justify-center">
                        {{-- LiveKit will attach video elements here --}}
                        <div x-show="!connected" class="flex flex-col items-center justify-center">
                            <div class="mb-4 size-20 animate-pulse rounded-full bg-zinc-700"></div>
                            <span class="text-sm text-zinc-400">{{ __('Connecting to stream...') }}</span>
                        </div>
                    </div>

                    {{-- Connection error --}}
                    <div x-show="connectionError" class="absolute inset-0 flex items-center justify-center bg-black/80">
                        <div class="text-center">
                            <flux:icon name="exclamation-triangle" class="mx-auto mb-2 size-8 text-red-400" />
                            <span class="text-sm text-red-400" x-text="connectionError"></span>
                        </div>
                    </div>
                </div>

                {{-- Controls bar --}}
                <div class="flex items-center justify-center gap-3 border-t border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    {{-- Toggle Audio --}}
                    <button
                        type="button"
                        @click="toggleAudio()"
                        class="flex size-12 items-center justify-center rounded-full transition-colors"
                        :class="audioEnabled ? 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                        :title="audioEnabled ? '{{ __('Mute') }}' : '{{ __('Unmute') }}'"
                    >
                        <template x-if="audioEnabled">
                            <flux:icon name="microphone" class="size-5" />
                        </template>
                        <template x-if="!audioEnabled">
                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" /></svg>
                        </template>
                    </button>

                    {{-- Toggle Video --}}
                    <button
                        type="button"
                        @click="toggleVideo()"
                        class="flex size-12 items-center justify-center rounded-full transition-colors"
                        :class="videoEnabled ? 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                        :title="videoEnabled ? '{{ __('Turn off camera') }}' : '{{ __('Turn on camera') }}'"
                    >
                        <template x-if="videoEnabled">
                            <flux:icon name="video-camera" class="size-5" />
                        </template>
                        <template x-if="!videoEnabled">
                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18" /></svg>
                        </template>
                    </button>

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

            {{-- Right column: Q&A + Resources --}}
            <div class="flex w-full flex-col border-l border-zinc-200 dark:border-zinc-700 lg:w-96">
                <div class="flex-1 overflow-y-auto">
                    {{-- Q&A Queue --}}
                    <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="mb-3 flex items-center justify-between">
                            <flux:heading size="sm">{{ __('Q&A Queue') }}</flux:heading>
                            @if ($this->pendingQuestions->isNotEmpty())
                                <flux:badge color="amber" size="sm">{{ $this->pendingQuestions->count() }} {{ __('pending') }}</flux:badge>
                            @endif
                        </div>

                        @if ($this->pendingQuestions->isEmpty())
                            <div class="rounded-lg bg-zinc-50 p-4 text-center dark:bg-zinc-900">
                                <flux:icon name="chat-bubble-left-right" class="mx-auto mb-2 size-6 text-zinc-400" />
                                <flux:text class="text-sm">{{ __('No questions yet. They will appear here as viewers ask them.') }}</flux:text>
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
                    </div>

                    {{-- Resource Manager --}}
                    <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-3">{{ __('Resources') }}</flux:heading>

                        {{-- Existing resources --}}
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

                        {{-- Add resource form --}}
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

                    {{-- Answered Questions (collapsed) --}}
                    <div class="p-4" x-data="{ showAnswered: false }">
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
            </div>
        </div>

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

<script>
    function cameraPreview() {
        return {
            stream: null,
            hasStream: false,
            audioLevel: 0,
            audioContext: null,
            analyser: null,
            animationFrame: null,
            cameraError: null,

            init() {
                this.startPreview();
            },

            async startPreview() {
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({
                        video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                        audio: { echoCancellation: true, noiseSuppression: true }
                    });
                    this.$refs.preview.srcObject = this.stream;
                    this.hasStream = true;
                    this.setupAudioMeter();
                } catch (err) {
                    console.error('Camera access error:', err);
                    this.cameraError = err.name === 'NotAllowedError'
                        ? 'Camera access denied. Please allow camera access in your browser settings.'
                        : 'Could not access camera: ' + err.message;
                }
            },

            setupAudioMeter() {
                try {
                    this.audioContext = new AudioContext();
                    const source = this.audioContext.createMediaStreamSource(this.stream);
                    this.analyser = this.audioContext.createAnalyser();
                    source.connect(this.analyser);
                    this.analyser.fftSize = 256;
                    this.monitorAudio();
                } catch (err) {
                    console.warn('Audio meter setup failed:', err);
                }
            },

            monitorAudio() {
                if (!this.analyser) return;
                const data = new Uint8Array(this.analyser.frequencyBinCount);
                this.analyser.getByteFrequencyData(data);
                this.audioLevel = Math.max(...data) / 255;
                this.animationFrame = requestAnimationFrame(() => this.monitorAudio());
            },

            destroy() {
                if (this.animationFrame) cancelAnimationFrame(this.animationFrame);
                if (this.stream) this.stream.getTracks().forEach(t => t.stop());
                if (this.audioContext) this.audioContext.close();
            }
        };
    }

    function livekitHost() {
        return {
            room: null,
            connected: false,
            audioEnabled: true,
            videoEnabled: true,
            viewerCount: 0,
            connectionError: null,

            init() {
                this.$wire.on('livekit-connect', ({ token, url }) => {
                    this.connect(token, url);
                });

                this.$wire.on('livekit-disconnect', () => {
                    this.disconnect();
                });
            },

            async connect(token, url) {
                try {
                    this.connectionError = null;
                    const { Room, RoomEvent, VideoPresets } = await window.loadLiveKit();
                    this.room = new Room({
                        adaptiveStream: true,
                        dynacast: true,
                        videoCaptureDefaults: {
                            resolution: VideoPresets.h720.resolution,
                        },
                    });

                    this.room.on(RoomEvent.ParticipantConnected, () => this.updateViewerCount());
                    this.room.on(RoomEvent.ParticipantDisconnected, () => this.updateViewerCount());
                    this.room.on(RoomEvent.Disconnected, () => {
                        this.connected = false;
                    });

                    await this.room.connect(url, token);
                    await this.room.localParticipant.setCameraEnabled(true);
                    await this.room.localParticipant.setMicrophoneEnabled(true);

                    // Attach local video tracks
                    const videoEl = this.$refs.hostVideo;
                    this.room.localParticipant.videoTrackPublications.forEach((pub) => {
                        if (pub.track) {
                            const el = pub.track.attach();
                            el.style.width = '100%';
                            el.style.height = '100%';
                            el.style.objectFit = 'cover';
                            videoEl.innerHTML = '';
                            videoEl.appendChild(el);
                        }
                    });

                    // Listen for new local tracks being published
                    this.room.localParticipant.on('localTrackPublished', (pub) => {
                        if (pub.track && pub.track.kind === 'video') {
                            const el = pub.track.attach();
                            el.style.width = '100%';
                            el.style.height = '100%';
                            el.style.objectFit = 'cover';
                            videoEl.innerHTML = '';
                            videoEl.appendChild(el);
                        }
                    });

                    this.connected = true;
                    this.updateViewerCount();
                } catch (err) {
                    console.error('LiveKit connection error:', err);
                    this.connectionError = 'Failed to connect: ' + err.message;
                }
            },

            updateViewerCount() {
                if (this.room) {
                    this.viewerCount = this.room.remoteParticipants.size;
                    this.$wire.set('viewerCount', this.viewerCount);
                }
            },

            async toggleAudio() {
                if (!this.room) return;
                this.audioEnabled = !this.audioEnabled;
                await this.room.localParticipant.setMicrophoneEnabled(this.audioEnabled);
            },

            async toggleVideo() {
                if (!this.room) return;
                this.videoEnabled = !this.videoEnabled;
                await this.room.localParticipant.setCameraEnabled(this.videoEnabled);
            },

            async disconnect() {
                if (this.room) {
                    await this.room.disconnect();
                    this.room = null;
                    this.connected = false;
                }
            },

            destroy() {
                this.disconnect();
            }
        };
    }
</script>
