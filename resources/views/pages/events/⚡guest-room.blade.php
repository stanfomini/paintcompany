<?php

use App\Events\BackstageChatMessageSent;
use App\Models\ChatMessage;
use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Services\LiveEventService;
use App\Services\LiveKitService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.video-call')] class extends Component
{
    public ?LiveEventGuest $guest = null;

    public ?LiveEvent $event = null;

    public string $livekitToken = '';

    public string $livekitUrl = '';

    public string $guestToken = '';

    public string $backstageMessage = '';

    public bool $eventEnded = false;

    public function mount(string $token): void
    {
        $this->guestToken = $token;

        $guest = LiveEventGuest::where('token', $token)->first();

        if (! $guest) {
            abort(404, 'Invalid guest link.');
        }

        $this->guest = $guest;
        $this->event = $guest->liveEvent;

        if (in_array($this->event->status, ['ended', 'cancelled'])) {
            $this->eventEnded = true;

            return;
        }

        // Mark guest as connected
        $service = app(LiveEventService::class);
        $service->markGuestConnected($guest);

        // Ensure backstage chat thread exists
        $service->ensureBackstageChatThread($this->event);
        $this->event->refresh();

        // Generate LiveKit token for this guest
        $this->livekitUrl = (string) config('services.livekit.url', '');

        try {
            $this->livekitToken = app(LiveKitService::class)->generateGuestToken(
                $this->event,
                $this->guest
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Guest LiveKit token error', [
                'guest_id' => $this->guest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendBackstageMessage(): void
    {
        $this->validate([
            'backstageMessage' => ['required', 'string', 'max:500'],
        ]);

        if (! $this->event?->backstage_chat_thread_id) {
            return;
        }

        $message = ChatMessage::create([
            'thread_id' => $this->event->backstage_chat_thread_id,
            'content' => $this->guest->name.': '.$this->backstageMessage,
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => false,
        ]);

        try {
            broadcast(new BackstageChatMessageSent($message, $this->event->id));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Guest backstage chat broadcast failed', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->backstageMessage = '';
        unset($this->backstageMessages);
    }

    #[Computed]
    public function backstageMessages()
    {
        if (! $this->event?->backstage_chat_thread_id) {
            return collect();
        }

        return ChatMessage::where('thread_id', $this->event->backstage_chat_thread_id)
            ->latest()
            ->take(50)
            ->get()
            ->reverse()
            ->values();
    }
};
?>

<div class="flex flex-col h-screen bg-zinc-900 text-white">

    {{-- Event ended state --}}
    @if ($eventEnded)
        <div class="flex-1 flex items-center justify-center p-6">
            <div class="text-center max-w-sm">
                <div class="w-16 h-16 rounded-full bg-zinc-700 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </div>
                <h1 class="text-xl font-bold mb-2">Event Has Ended</h1>
                <p class="text-zinc-400 text-sm">Thanks for joining! The stream has ended.</p>
            </div>
        </div>
    @else
        <div
            x-data="livekitGuest({
                livekitToken: @js($livekitToken),
                livekitUrl: @js($livekitUrl),
                guestId: @js($guest->id),
                guestIdentity: @js($guest->getLivekitIdentity()),
                guestName: @js($guest->name),
                guestToken: @js($guestToken),
                eventId: @js($event->id),
                backstageThreadId: @js($event->backstage_chat_thread_id),
            })"
            x-init="init()"
            class="flex flex-col h-screen"
        >
            {{-- Top Bar: Event Info --}}
            <div class="flex-shrink-0 px-4 py-3 bg-zinc-800 border-b border-zinc-700">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <h1 class="text-sm font-semibold truncate">{{ $event->title }}</h1>
                        @if ($event->host)
                            <p class="text-xs text-zinc-400">Host: {{ $event->host->name }}</p>
                        @endif
                    </div>
                    <div class="flex-shrink-0 flex items-center gap-2">
                        <div
                            class="w-2 h-2 rounded-full"
                            :class="{
                                'bg-green-500': peerConnected || viewerConnected,
                                'bg-yellow-500 animate-pulse': !peerConnected && !viewerConnected
                            }"
                        ></div>
                        <span class="text-xs text-zinc-400" x-text="peerConnected ? 'Live to host' : (viewerConnected ? 'Connected' : 'Connecting...')"></span>
                    </div>
                </div>

                {{-- Guest instructions --}}
                @if ($guest->instructions)
                    <div class="mt-2 px-3 py-2 rounded-lg bg-zinc-700/50 border border-zinc-600">
                        <p class="text-xs text-zinc-300"><span class="font-medium text-amber-400">Instructions:</span> {{ $guest->instructions }}</p>
                    </div>
                @endif
            </div>

            {{-- Status Banner --}}
            <div
                class="flex-shrink-0 px-4 py-3 border-b transition-colors duration-300"
                :class="{
                    'bg-emerald-900/40 border-emerald-800': guestStatus === 'waiting',
                    'bg-amber-900/40 border-amber-800': guestStatus === 'called',
                    'bg-red-900/40 border-red-800': guestStatus === 'live',
                    'bg-zinc-800 border-zinc-700': guestStatus === 'finished',
                }"
            >
                {{-- Waiting --}}
                <template x-if="guestStatus === 'waiting'">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-emerald-300">You're in the green room</p>
                            <p class="text-xs text-emerald-400/70">The host will bring you on when ready.</p>
                        </div>
                    </div>
                </template>

                {{-- Called On --}}
                <template x-if="guestStatus === 'called'">
                    <div>
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-full bg-amber-500/20 flex items-center justify-center flex-shrink-0 animate-pulse">
                                <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-amber-300">The host is ready for you!</p>
                                <p class="text-xs text-amber-400/70">Tap Go Live when you're ready to be broadcast.</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                @click="goLive()"
                                class="flex-1 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold text-sm transition-colors flex items-center justify-center gap-2"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                Go Live
                            </button>
                            <button
                                type="button"
                                @click="notReady()"
                                class="px-4 py-2.5 rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-300 text-sm transition-colors"
                            >
                                Not Ready
                            </button>
                        </div>
                    </div>
                </template>

                {{-- Live --}}
                <template x-if="guestStatus === 'live'">
                    <div class="flex items-center gap-3">
                        <div class="relative flex-shrink-0">
                            <div class="w-8 h-8 rounded-full bg-red-500/20 flex items-center justify-center">
                                <div class="w-3 h-3 rounded-full bg-red-500 animate-pulse"></div>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-red-300">You're LIVE!</p>
                            <p class="text-xs text-red-400/70">Your camera is being broadcast to viewers.</p>
                        </div>
                    </div>
                </template>

                {{-- Finished --}}
                <template x-if="guestStatus === 'finished'">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-zinc-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-zinc-300">Thanks for joining!</p>
                            <p class="text-xs text-zinc-500">The stream has ended.</p>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Main Content: Stream Preview + Camera --}}
            <div class="flex-1 min-h-0 flex flex-col overflow-hidden">

                {{-- Stream Preview (host's broadcast) — small when camera is the focus --}}
                <div class="relative bg-black shrink-0" style="height: 25vh; min-height: 120px;">
                    <div x-ref="streamPreview" class="w-full h-full"></div>
                    <div x-show="!hostTrackAttached" class="absolute inset-0 flex items-center justify-center bg-zinc-800">
                        <div class="text-center">
                            <svg class="w-8 h-8 text-zinc-600 mx-auto mb-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                            <p class="text-[10px] text-zinc-500">Waiting for host stream...</p>
                        </div>
                    </div>
                    <div class="absolute top-1 left-1 px-1.5 py-0.5 rounded bg-black/60 text-[10px] text-zinc-400">Stream Preview</div>
                </div>

                {{-- Camera Preview + Controls --}}
                <div class="relative bg-zinc-800 flex-1 min-h-0 flex flex-col">
                    {{-- Camera Feed --}}
                    <div class="flex-1 min-h-0 relative">
                        <video
                            x-ref="localPreview"
                            autoplay
                            muted
                            playsinline
                            class="w-full h-full object-cover"
                            :class="{ 'hidden': !cameraEnabled }"
                            style="transform: scaleX(-1);"
                        ></video>
                        <div x-show="!cameraEnabled" class="absolute inset-0 flex items-center justify-center">
                            <div class="w-16 h-16 rounded-full bg-zinc-700 flex items-center justify-center">
                                <span class="text-xl font-bold">{{ substr($guest->name, 0, 1) }}</span>
                            </div>
                        </div>

                        {{-- Audio Level Indicator --}}
                        <div class="absolute top-2 right-2 flex flex-col-reverse gap-0.5" x-show="micEnabled">
                            <template x-for="i in 5" :key="i">
                                <div
                                    class="w-1.5 rounded-full transition-all duration-75"
                                    :class="audioLevel >= (i * 0.2) ? 'bg-green-400 h-2.5' : 'bg-zinc-600 h-1.5'"
                                ></div>
                            </template>
                        </div>

                        <div class="absolute top-2 left-2 px-2 py-1 rounded bg-black/60 text-xs text-zinc-300">Your Camera</div>
                    </div>

                    {{-- Camera Controls Bar --}}
                    <div class="flex-shrink-0 px-4 py-3 bg-zinc-900/80 border-t border-zinc-700 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            {{-- Mic Toggle --}}
                            <button
                                type="button"
                                @click="toggleMic()"
                                class="w-10 h-10 rounded-full flex items-center justify-center transition-colors"
                                :class="micEnabled ? 'bg-zinc-700 hover:bg-zinc-600 text-white' : 'bg-red-600 hover:bg-red-700 text-white'"
                            >
                                <template x-if="micEnabled">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" /></svg>
                                </template>
                                <template x-if="!micEnabled">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" /><line x1="3" y1="3" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                                </template>
                            </button>

                            {{-- Camera Toggle --}}
                            <button
                                type="button"
                                @click="toggleCamera()"
                                class="w-10 h-10 rounded-full flex items-center justify-center transition-colors"
                                :class="cameraEnabled ? 'bg-zinc-700 hover:bg-zinc-600 text-white' : 'bg-red-600 hover:bg-red-700 text-white'"
                            >
                                <template x-if="cameraEnabled">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                </template>
                                <template x-if="!cameraEnabled">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /><line x1="3" y1="3" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                                </template>
                            </button>
                        </div>

                        {{-- Device Switcher --}}
                        <div class="relative" x-data="{ showDevices: false }">
                            <button
                                type="button"
                                @click="showDevices = !showDevices"
                                class="w-10 h-10 rounded-full bg-zinc-700 hover:bg-zinc-600 flex items-center justify-center text-zinc-300 transition-colors"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            </button>

                            {{-- Device dropdown --}}
                            <div
                                x-show="showDevices"
                                @click.outside="showDevices = false"
                                x-transition
                                class="absolute bottom-12 right-0 w-64 bg-zinc-800 border border-zinc-600 rounded-lg shadow-xl p-3 space-y-3 z-50"
                            >
                                {{-- Cameras --}}
                                <div>
                                    <label class="block text-xs font-medium text-zinc-400 mb-1">Camera</label>
                                    <select
                                        @change="switchCamera($event.target.value); showDevices = false"
                                        class="w-full bg-zinc-700 border border-zinc-600 text-sm text-white rounded-md px-2 py-1.5"
                                    >
                                        <template x-for="cam in cameras" :key="cam.id">
                                            <option :value="cam.id" :selected="cam.id === activeCameraId" x-text="cam.label"></option>
                                        </template>
                                    </select>
                                </div>

                                {{-- Microphones --}}
                                <div>
                                    <label class="block text-xs font-medium text-zinc-400 mb-1">Microphone</label>
                                    <select
                                        @change="switchMic($event.target.value); showDevices = false"
                                        class="w-full bg-zinc-700 border border-zinc-600 text-sm text-white rounded-md px-2 py-1.5"
                                    >
                                        <template x-for="mic in microphones" :key="mic.id">
                                            <option :value="mic.id" :selected="mic.id === activeMicId" x-text="mic.label"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Backstage Chat --}}
                <div class="flex-shrink-0 bg-zinc-800 border-t border-zinc-700" style="height: 20vh; min-height: 100px;">
                    <div class="flex flex-col h-full">
                        <div class="px-4 py-2 border-b border-zinc-700 flex items-center gap-2">
                            <svg class="w-4 h-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" /></svg>
                            <span class="text-xs font-medium text-zinc-400">Backstage Chat</span>
                        </div>

                        {{-- Messages --}}
                        <div
                            x-ref="chatScroll"
                            class="flex-1 overflow-y-auto px-4 py-2 space-y-2"
                            x-effect="$nextTick(() => { if ($refs.chatScroll) $refs.chatScroll.scrollTop = $refs.chatScroll.scrollHeight })"
                        >
                            @forelse ($this->backstageMessages as $msg)
                                <div class="text-xs">
                                    <span class="text-zinc-300">{{ $msg->content }}</span>
                                    <span class="text-zinc-600 ml-1">{{ $msg->created_at->format('g:ia') }}</span>
                                </div>
                            @empty
                                <div class="text-xs text-zinc-600 italic">No messages yet. Chat with the producer here.</div>
                            @endforelse
                        </div>

                        {{-- Send message --}}
                        <div class="flex-shrink-0 px-3 py-2 border-t border-zinc-700">
                            <form wire:submit="sendBackstageMessage" class="flex gap-2">
                                <input
                                    type="text"
                                    wire:model="backstageMessage"
                                    placeholder="Message the producer..."
                                    maxlength="500"
                                    class="flex-1 bg-zinc-700 border border-zinc-600 text-sm text-white rounded-lg px-3 py-2 placeholder-zinc-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                />
                                <button
                                    type="submit"
                                    class="px-3 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm font-medium transition-colors"
                                >
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @script
        <script>
        Alpine.data('livekitGuest', (config) => {
            let localStream = null;
            let peerConnection = null;   // GuestPeerConnection instance
            let viewerRoom = null;       // LiveKit room (subscribe-only, for seeing broadcast)
            let studioChannel = null;    // Echo presence channel for WebRTC signaling
            let audioContext = null;
            let analyser = null;
            let animationFrame = null;
            let echoInstance = null;

            return {
                guestStatus: 'waiting',   // 'waiting' | 'called' | 'live' | 'finished'
                cameraEnabled: true,
                micEnabled: true,
                audioLevel: 0,
                cameras: [],
                microphones: [],
                activeCameraId: '',
                activeMicId: '',
                hostTrackAttached: false,
                peerConnected: false,     // WebRTC connection to host established
                viewerConnected: false,   // LiveKit viewer connection

                async init() {
                    // Start local camera preview immediately
                    await this.startLocalPreview();

                    // Join Reverb channel for WebRTC signaling + bring-on/take-off signals
                    this.setupStudioChannel();

                    // Set up backstage chat via Echo/Reverb
                    this.setupBackstageChat();

                    // Connect to LiveKit as viewer (subscribe-only) to see broadcast output
                    if (config.livekitToken && config.livekitUrl) {
                        this.connectAsViewer();
                    }
                },

                // ==============================================================
                // Local camera preview (unchanged)
                // ==============================================================

                async startLocalPreview() {
                    try {
                        localStream = await navigator.mediaDevices.getUserMedia({
                            video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                            audio: { echoCancellation: true, noiseSuppression: true },
                        });

                        if (this.$refs.localPreview) {
                            this.$refs.localPreview.srcObject = localStream;
                        }

                        this.cameraEnabled = true;
                        this.micEnabled = true;

                        this.setupAudioMeter();
                        await this.loadDevices();
                    } catch (err) {
                        console.error('[GuestRoom] Camera access error:', err);
                    }
                },

                setupAudioMeter() {
                    try {
                        audioContext = new AudioContext();
                        const source = audioContext.createMediaStreamSource(localStream);
                        analyser = audioContext.createAnalyser();
                        source.connect(analyser);
                        analyser.fftSize = 256;
                        this.monitorAudio();
                    } catch (err) {
                        console.warn('[GuestRoom] Audio meter setup failed:', err);
                    }
                },

                monitorAudio() {
                    if (!analyser) return;
                    const data = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    this.audioLevel = Math.max(...data) / 255;
                    animationFrame = requestAnimationFrame(() => this.monitorAudio());
                },

                // ==============================================================
                // Device management (unchanged)
                // ==============================================================

                async loadDevices() {
                    try {
                        const devices = await navigator.mediaDevices.enumerateDevices();
                        this.cameras = devices
                            .filter(d => d.kind === 'videoinput')
                            .map(d => ({ id: d.deviceId, label: d.label || `Camera ${d.deviceId.slice(0, 4)}` }));
                        this.microphones = devices
                            .filter(d => d.kind === 'audioinput')
                            .map(d => ({ id: d.deviceId, label: d.label || `Mic ${d.deviceId.slice(0, 4)}` }));

                        if (localStream) {
                            const videoTrack = localStream.getVideoTracks()[0];
                            const audioTrack = localStream.getAudioTracks()[0];
                            if (videoTrack) this.activeCameraId = videoTrack.getSettings().deviceId || '';
                            if (audioTrack) this.activeMicId = audioTrack.getSettings().deviceId || '';
                        }
                    } catch (err) {
                        console.warn('[GuestRoom] Failed to enumerate devices:', err);
                    }
                },

                async switchCamera(deviceId) {
                    if (!deviceId || deviceId === this.activeCameraId) return;
                    try {
                        if (localStream) {
                            localStream.getVideoTracks().forEach(t => t.stop());
                        }

                        const newStream = await navigator.mediaDevices.getUserMedia({
                            video: { deviceId: { exact: deviceId } },
                        });

                        const newTrack = newStream.getVideoTracks()[0];
                        if (localStream) {
                            const oldTrack = localStream.getVideoTracks()[0];
                            if (oldTrack) localStream.removeTrack(oldTrack);
                            localStream.addTrack(newTrack);
                        }

                        if (this.$refs.localPreview) {
                            this.$refs.localPreview.srcObject = localStream;
                        }

                        this.activeCameraId = deviceId;
                    } catch (err) {
                        console.error('[GuestRoom] Failed to switch camera:', err);
                    }
                },

                async switchMic(deviceId) {
                    if (!deviceId || deviceId === this.activeMicId) return;
                    try {
                        if (localStream) {
                            localStream.getAudioTracks().forEach(t => t.stop());
                        }

                        const newStream = await navigator.mediaDevices.getUserMedia({
                            audio: { deviceId: { exact: deviceId }, echoCancellation: true, noiseSuppression: true },
                        });

                        const newTrack = newStream.getAudioTracks()[0];
                        if (localStream) {
                            const oldTrack = localStream.getAudioTracks()[0];
                            if (oldTrack) localStream.removeTrack(oldTrack);
                            localStream.addTrack(newTrack);
                        }

                        if (audioContext) {
                            audioContext.close();
                        }
                        this.setupAudioMeter();

                        this.activeMicId = deviceId;
                    } catch (err) {
                        console.error('[GuestRoom] Failed to switch mic:', err);
                    }
                },

                toggleMic() {
                    if (localStream) {
                        localStream.getAudioTracks().forEach(t => {
                            t.enabled = !t.enabled;
                        });
                        this.micEnabled = !this.micEnabled;
                    }
                },

                toggleCamera() {
                    if (localStream) {
                        localStream.getVideoTracks().forEach(t => {
                            t.enabled = !t.enabled;
                        });
                        this.cameraEnabled = !this.cameraEnabled;
                    }
                },

                // ==============================================================
                // Studio channel (Reverb) — WebRTC signaling + host commands
                // ==============================================================

                setupStudioChannel() {
                    if (!config.eventId) return;

                    try {
                        const EchoLib = window.EchoLib;
                        if (!EchoLib) {
                            console.warn('[GuestRoom] Echo not available, peer connections disabled');
                            return;
                        }

                        // Reuse existing echoInstance if backstage chat already created one,
                        // otherwise create a new one
                        if (!echoInstance) {
                            echoInstance = new EchoLib({
                                broadcaster: 'reverb',
                                key: document.querySelector('meta[name="reverb-key"]')?.content,
                                wsHost: document.querySelector('meta[name="reverb-host"]')?.content,
                                wsPort: document.querySelector('meta[name="reverb-port"]')?.content || 443,
                                wssPort: document.querySelector('meta[name="reverb-port"]')?.content || 443,
                                forceTLS: (document.querySelector('meta[name="reverb-scheme"]')?.content || 'https') === 'https',
                                enabledTransports: ['ws', 'wss'],
                                authEndpoint: '/broadcasting/event-guest-auth',
                                auth: {
                                    headers: {
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                    },
                                    params: {
                                        guest_token: config.guestToken,
                                    },
                                },
                            });
                        }

                        studioChannel = echoInstance.join(`event-studio.${config.eventId}`)
                            .here((users) => {
                                console.log('[GuestRoom] Studio channel subscribed. Users:', users);
                            })
                            .joining((user) => {
                                console.log('[GuestRoom] User joined studio channel:', user);
                            })
                            .leaving((user) => {
                                console.log('[GuestRoom] User left studio channel:', user);
                            })
                            .error((error) => {
                                console.error('[GuestRoom] Studio channel subscription FAILED:', error);
                            });

                        // Listen for host commands via whispers
                        studioChannel.listenForWhisper('bring-on', (data) => {
                            console.log('[GuestRoom] Received bring-on whisper:', data);
                            if (data.guestId && data.guestId !== config.guestId) return;
                            this.guestStatus = 'called';
                            if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
                            console.log('[GuestRoom] Host is calling you on!');
                        });

                        studioChannel.listenForWhisper('take-off', (data) => {
                            console.log('[GuestRoom] Received take-off whisper:', data);
                            if (data.guestId && data.guestId !== config.guestId) return;
                            this.disconnectPeer();
                            this.guestStatus = 'waiting';
                            console.log('[GuestRoom] Host took you off air');
                        });

                        studioChannel.listenForWhisper('end-event', () => {
                            this.guestStatus = 'finished';
                        });

                        console.log('[GuestRoom] Studio channel join requested');
                    } catch (err) {
                        console.warn('[GuestRoom] Studio channel setup failed:', err);
                    }
                },

                // ==============================================================
                // LiveKit viewer connection (subscribe-only)
                // ==============================================================

                async connectAsViewer() {
                    try {
                        const { Room, RoomEvent } = await window.loadLiveKit();
                        viewerRoom = new Room({
                            adaptiveStream: true,
                            dynacast: true,
                        });

                        // Subscribe to host's published tracks (stream preview)
                        viewerRoom.on(RoomEvent.TrackSubscribed, (track, pub, participant) => {
                            if (!participant.identity.startsWith('host-') && !participant.identity.startsWith('coproducer-')) {
                                return;
                            }

                            if (track.kind === 'video') {
                                const el = track.attach();
                                el.autoplay = true;
                                el.playsInline = true;
                                el.style.width = '100%';
                                el.style.height = '100%';
                                el.style.objectFit = 'cover';
                                if (this.$refs.streamPreview) {
                                    this.$refs.streamPreview.innerHTML = '';
                                    this.$refs.streamPreview.appendChild(el);
                                }
                                this.hostTrackAttached = true;
                            } else if (track.kind === 'audio') {
                                const el = track.attach();
                                el.style.display = 'none';
                                el.id = 'host-audio-preview';
                                document.body.appendChild(el);
                            }
                        });

                        viewerRoom.on(RoomEvent.TrackUnsubscribed, (track) => {
                            track.detach().forEach(el => el.remove());
                            if (this.$refs.streamPreview && this.$refs.streamPreview.children.length === 0) {
                                this.hostTrackAttached = false;
                            }
                        });

                        viewerRoom.on(RoomEvent.Disconnected, () => {
                            this.viewerConnected = false;
                            this.guestStatus = 'finished';
                        });

                        // Connect as subscriber only (canPublish: false in token)
                        await viewerRoom.connect(config.livekitUrl, config.livekitToken);
                        this.viewerConnected = true;
                        console.log('[GuestRoom] Connected to LiveKit as viewer');
                    } catch (err) {
                        console.error('[GuestRoom] LiveKit viewer connection error:', err);
                    }
                },

                // ==============================================================
                // Go Live / Not Ready — WebRTC peer connection to host
                // ==============================================================

                async goLive() {
                    if (!studioChannel || !localStream) {
                        console.warn('[GuestRoom] Cannot go live: no studio channel or local stream');
                        return;
                    }

                    try {
                        const GuestPeerConnection = await window.loadGuestPeerConnection();
                        peerConnection = new GuestPeerConnection(studioChannel, config.guestIdentity);

                        peerConnection.onConnected(() => {
                            this.peerConnected = true;
                            this.guestStatus = 'live';
                            console.log('[GuestRoom] Connected to host via WebRTC');

                            // Mute host audio preview to prevent echo
                            const hostAudio = document.getElementById('host-audio-preview');
                            if (hostAudio) hostAudio.muted = true;
                        });

                        peerConnection.onDisconnected(() => {
                            this.peerConnected = false;
                            if (this.guestStatus === 'live') {
                                this.guestStatus = 'waiting';
                            }

                            // Unmute host audio preview
                            const hostAudio = document.getElementById('host-audio-preview');
                            if (hostAudio) hostAudio.muted = false;
                        });

                        await peerConnection.connect(localStream);

                        // Signal ready to host
                        studioChannel.whisper('guest-ready', {
                            guestId: config.guestIdentity,
                            name: config.guestName || 'Guest',
                        });
                    } catch (err) {
                        console.error('[GuestRoom] Failed to go live:', err);
                    }
                },

                async notReady() {
                    this.disconnectPeer();
                    this.guestStatus = 'waiting';

                    // Signal not-ready to host
                    if (studioChannel) {
                        studioChannel.whisper('guest-not-ready', {
                            guestId: config.guestIdentity,
                        });
                    }
                },

                disconnectPeer() {
                    if (peerConnection) {
                        peerConnection.disconnect();
                        peerConnection = null;
                    }
                    this.peerConnected = false;

                    // Unmute host audio preview
                    const hostAudio = document.getElementById('host-audio-preview');
                    if (hostAudio) hostAudio.muted = false;
                },

                // ==============================================================
                // Backstage chat (unchanged)
                // ==============================================================

                setupBackstageChat() {
                    if (!config.backstageThreadId || !config.eventId) return;

                    try {
                        const EchoLib = window.EchoLib;
                        if (!EchoLib) return;

                        // Reuse existing echoInstance or create one
                        if (!echoInstance) {
                            echoInstance = new EchoLib({
                                broadcaster: 'reverb',
                                key: document.querySelector('meta[name="reverb-key"]')?.content,
                                wsHost: document.querySelector('meta[name="reverb-host"]')?.content,
                                wsPort: document.querySelector('meta[name="reverb-port"]')?.content || 443,
                                wssPort: document.querySelector('meta[name="reverb-port"]')?.content || 443,
                                forceTLS: (document.querySelector('meta[name="reverb-scheme"]')?.content || 'https') === 'https',
                                enabledTransports: ['ws', 'wss'],
                                authEndpoint: '/broadcasting/event-guest-auth',
                                auth: {
                                    headers: {
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                    },
                                    params: {
                                        guest_token: config.guestToken,
                                    },
                                },
                            });
                        }

                        echoInstance.private(`event-backstage.${config.eventId}`)
                            .listen('.backstage.message', (e) => {
                                this.$wire.$refresh();
                            });
                    } catch (err) {
                        console.warn('[GuestRoom] Backstage chat setup failed:', err);
                    }
                },

                // ==============================================================
                // Cleanup
                // ==============================================================

                destroy() {
                    if (peerConnection) { peerConnection.disconnect(); peerConnection = null; }
                    if (animationFrame) cancelAnimationFrame(animationFrame);
                    if (localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
                    if (audioContext) { audioContext.close(); audioContext = null; }
                    if (viewerRoom) { viewerRoom.disconnect(); viewerRoom = null; }
                    if (studioChannel) { studioChannel = null; }
                    if (echoInstance) { echoInstance.disconnect(); echoInstance = null; }

                    const hostAudio = document.getElementById('host-audio-preview');
                    if (hostAudio) hostAudio.muted = false;
                },
            };
        });
        </script>
        @endscript
    @endif
</div>
