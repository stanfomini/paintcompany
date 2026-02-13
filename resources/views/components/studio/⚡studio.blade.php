<?php

use App\Models\StreamSession;
use App\Models\CompanySetting;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.video-call', ['title' => 'Streaming Studio'])]
class extends Component {
    public ?StreamSession $session = null;
    public bool $walkaroundMode = false;

    // Destination form
    public string $destPlatform = 'youtube';
    public string $destRtmpUrl = '';
    public string $destStreamKey = '';

    // Overlay form
    public string $overlayType = 'text';
    public string $overlayContent = '';

    public function mount(?StreamSession $session = null): void
    {
        // Check for walkaround preset
        $this->walkaroundMode = request()->query('preset') === 'walkaround';

        if ($session && $session->exists) {
            // Ensure the user owns this session
            if ($session->user_id !== auth()->id()) {
                abort(403);
            }
            $this->session = $session;
        } else {
            // Create a new session
            $this->session = StreamSession::create([
                'user_id' => auth()->id(),
                'title' => $this->walkaroundMode ? 'Walkaround Stream' : null,
                'scenes' => StreamSession::defaultScenes(),
                'current_scene_index' => $this->walkaroundMode ? 3 : 0,
            ]);
        }

        // Set default RTMP URLs per platform
        $this->destRtmpUrl = 'rtmp://a.rtmp.youtube.com/live2';
    }

    public function updatedDestPlatform(): void
    {
        $this->destRtmpUrl = match ($this->destPlatform) {
            'youtube' => 'rtmp://a.rtmp.youtube.com/live2',
            'tiktok' => 'rtmp://push.rtmp.tiktok.com/live/',
            'x' => 'rtmp://live.x.com/rtmp/',
            'facebook' => 'rtmps://live-api-s.facebook.com:443/rtmp/',
            'twitch' => 'rtmp://live.twitch.tv/app/',
            default => '',
        };
    }

    public function addDestination(): void
    {
        $this->validate([
            'destRtmpUrl' => 'required|string',
            'destStreamKey' => 'required|string',
        ]);

        $destinations = $this->session->destinations ?? [];
        $destinations[] = [
            'id' => $this->destPlatform . '-' . Str::random(4),
            'platform' => $this->destPlatform,
            'rtmp_url' => $this->destRtmpUrl,
            'stream_key' => $this->destStreamKey,
            'status' => 'idle',
            'enabled' => true,
        ];

        $this->session->update(['destinations' => $destinations]);
        $this->destStreamKey = '';

        $this->dispatch('destinations-updated', destinations: $destinations);
    }

    public function removeDestination(string $id): void
    {
        $destinations = collect($this->session->destinations ?? [])
            ->reject(fn ($d) => $d['id'] === $id)
            ->values()
            ->all();

        $this->session->update(['destinations' => $destinations]);
        $this->dispatch('destinations-updated', destinations: $destinations);
    }

    public function toggleDestination(string $id): void
    {
        $destinations = collect($this->session->destinations ?? [])
            ->map(fn ($d) => $d['id'] === $id ? array_merge($d, ['enabled' => !$d['enabled']]) : $d)
            ->all();

        $this->session->update(['destinations' => $destinations]);
        $this->dispatch('destinations-updated', destinations: $destinations);
    }

    public function saveScenes(array $scenes): void
    {
        $this->session->update(['scenes' => $scenes]);
    }

    public function switchScene(int $index): void
    {
        $this->session->update(['current_scene_index' => $index]);
        $this->dispatch('scene-switched', index: $index);
    }

    public function goLive(): void
    {
        $destinations = collect($this->session->destinations ?? [])
            ->filter(fn ($d) => $d['enabled'])
            ->values()
            ->all();

        if (empty($destinations)) {
            $this->addError('destinations', 'Add at least one streaming destination before going live.');
            return;
        }

        $this->session->update([
            'status' => 'live',
            'started_at' => now(),
        ]);

        $this->dispatch('go-live', destinations: $destinations);
    }

    public function stopStream(): void
    {
        $this->session->update([
            'status' => 'ended',
            'ended_at' => now(),
            'duration_seconds' => $this->session->started_at
                ? now()->diffInSeconds($this->session->started_at)
                : null,
        ]);

        $this->dispatch('stream-stopped');
    }

    public function generateGuestLink(): string
    {
        return $this->session->generateGuestLink();
    }

    #[Computed]
    public function companyLogo(): ?string
    {
        $settings = CompanySetting::first();
        return $settings?->logo_path
            ? \Illuminate\Support\Facades\Storage::url($settings->logo_path)
            : null;
    }

    #[Computed]
    public function pythonWsUrl(): string
    {
        return app(\App\Services\PythonBridgeService::class)
            ->getStreamWebSocketUrl($this->session->room_id);
    }
};
?>

<div x-data="studioEngine()" x-init="init()" class="flex h-screen overflow-hidden text-white" x-cloak
     @beforeunmount.window="destroy()">
    {{-- LEFT: Scene Panel --}}
    <div class="w-64 bg-zinc-900 border-r border-zinc-700 flex flex-col shrink-0" x-show="!walkaroundMode">
        <div class="p-3 border-b border-zinc-700">
            <h2 class="text-sm font-semibold text-zinc-300 uppercase tracking-wider">{{ __('Scenes') }}</h2>
        </div>
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
            <template x-for="(scene, index) in scenes" :key="index">
                <button
                    @click="switchScene(index)"
                    :class="currentSceneIndex === index
                        ? 'bg-indigo-600 text-white'
                        : 'bg-zinc-800 text-zinc-300 hover:bg-zinc-700'"
                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
                >
                    <span class="w-5 h-5 rounded bg-zinc-600 flex items-center justify-center text-xs font-mono" x-text="index + 1"></span>
                    <span x-text="scene.name"></span>
                </button>
            </template>
        </div>
        <div class="p-2 border-t border-zinc-700">
            <button @click="addScene()" class="w-full px-3 py-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-400 text-sm flex items-center justify-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __('Add Scene') }}
            </button>
        </div>
    </div>

    {{-- CENTER: Preview + Overlays --}}
    <div class="flex-1 flex flex-col min-w-0">
        {{-- Top bar --}}
        <div class="h-12 bg-zinc-900 border-b border-zinc-700 flex items-center px-4 gap-3 shrink-0">
            <div class="flex items-center gap-2">
                <div :class="isLive ? 'bg-red-500 animate-pulse' : 'bg-zinc-600'" class="w-2.5 h-2.5 rounded-full"></div>
                <span class="text-sm font-medium" x-text="isLive ? 'LIVE' : (cameraReady ? 'Preview' : 'Starting camera...')"></span>
            </div>
            <div class="text-zinc-500 text-xs" x-show="isLive" x-text="elapsedTime"></div>
            <div class="flex-1"></div>
            {{-- Stream health indicators --}}
            <div class="flex items-center gap-3 text-xs" x-show="isLive">
                <span class="text-zinc-500"><span x-text="fps"></span> fps</span>
                <span class="text-zinc-500"><span x-text="(streamBitrate / 1000).toFixed(0)"></span> kbps</span>
                <span :class="droppedFrames > 10 ? 'text-red-400' : 'text-zinc-500'">
                    <span x-text="droppedFrames"></span> dropped
                </span>
                <div :class="wsConnected ? 'bg-green-500' : 'bg-red-500'" class="w-2 h-2 rounded-full" :title="wsConnected ? 'Connected' : 'Disconnected'"></div>
            </div>
            {{-- Recording indicator --}}
            <button @click="toggleLocalRecording()" class="flex items-center gap-1 text-xs px-2 py-1 rounded"
                    :class="isRecordingLocally ? 'bg-red-600/20 text-red-400' : 'bg-zinc-800 text-zinc-500 hover:text-zinc-300'">
                <div :class="isRecordingLocally ? 'bg-red-500 animate-pulse' : 'bg-zinc-600'" class="w-2 h-2 rounded-full"></div>
                <span x-text="isRecordingLocally ? 'REC' : 'Record'"></span>
            </button>
        </div>

        {{-- Canvas preview --}}
        <div class="flex-1 flex items-center justify-center bg-black p-4">
            <div class="relative w-full max-w-5xl" style="aspect-ratio: 16/9;">
                <canvas x-ref="outputCanvas" width="1280" height="720" class="w-full h-full rounded-lg bg-zinc-900"></canvas>

                {{-- Overlay editing layer (shown during preview, hidden during live) --}}
                <div x-ref="overlayContainer" class="absolute inset-0 pointer-events-none" x-show="!isLive">
                    <template x-for="overlay in activeOverlays" :key="overlay.id">
                        <div
                            :style="`position: absolute; left: ${overlay.x * 100}%; top: ${overlay.y * 100}%; width: ${overlay.w * 100}%; height: ${overlay.h * 100}%; pointer-events: auto; cursor: move;`"
                            @mousedown="startDragOverlay($event, overlay)"
                            class="border border-dashed border-indigo-400/50 hover:border-indigo-400 rounded group"
                            x-show="overlay.visible"
                        >
                            {{-- Resize handle --}}
                            <div class="absolute -bottom-1 -right-1 w-3 h-3 bg-indigo-500 rounded-sm cursor-se-resize opacity-0 group-hover:opacity-100"
                                 @mousedown.stop="startResizeOverlay($event, overlay)"></div>

                            <template x-if="overlay.type === 'text'">
                                <div class="p-2 text-white text-sm font-bold drop-shadow-lg" x-text="overlay.content" :style="overlay.style || ''"></div>
                            </template>
                            <template x-if="overlay.type === 'image'">
                                <img :src="overlay.content" class="w-full h-full object-contain" />
                            </template>
                            <template x-if="overlay.type === 'html'">
                                <div class="w-full h-full flex items-center justify-center text-xs text-indigo-300 bg-indigo-500/10">
                                    <span x-text="'HTML: ' + (overlay.label || 'Component')"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Bottom toolbar --}}
        <div class="h-16 bg-zinc-900 border-t border-zinc-700 flex items-center justify-center gap-4 px-4 shrink-0">
            {{-- Mic toggle --}}
            <button @click="toggleMic()" :class="micEnabled ? 'bg-zinc-700' : 'bg-red-600'" class="p-3 rounded-full hover:opacity-80 transition-opacity" title="Toggle Microphone">
                <svg x-show="micEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-14 0m7 0V3m0 8v4m-4 1a4 4 0 008 0"/></svg>
                <svg x-show="!micEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"/></svg>
            </button>

            {{-- Camera toggle --}}
            <button @click="toggleCamera()" :class="cameraEnabled ? 'bg-zinc-700' : 'bg-red-600'" class="p-3 rounded-full hover:opacity-80 transition-opacity" title="Toggle Camera">
                <svg x-show="cameraEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                <svg x-show="!cameraEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </button>

            {{-- Go Live / Stop --}}
            <template x-if="!isLive">
                <button @click="goLive()" class="px-6 py-2.5 bg-red-600 hover:bg-red-500 rounded-lg font-semibold text-sm flex items-center gap-2 transition-colors">
                    <div class="w-2 h-2 rounded-full bg-white"></div>
                    {{ __('Go Live') }}
                </button>
            </template>
            <template x-if="isLive">
                <button @click="stopStream()" class="px-6 py-2.5 bg-zinc-700 hover:bg-zinc-600 rounded-lg font-semibold text-sm flex items-center gap-2 transition-colors border border-red-500">
                    <div class="w-2 h-2 rounded bg-red-500"></div>
                    {{ __('Stop Stream') }}
                </button>
            </template>

            {{-- Scene hotkeys --}}
            <div class="ml-6 flex items-center gap-1 text-zinc-500 text-xs" x-show="!walkaroundMode">
                <template x-for="(scene, index) in scenes" :key="'hotkey-'+index">
                    <kbd @click="switchScene(index)" :class="currentSceneIndex === index ? 'bg-indigo-600 text-white border-indigo-500' : 'bg-zinc-800 border-zinc-600'" class="px-2 py-1 border rounded text-xs cursor-pointer hover:bg-zinc-700" x-text="index + 1"></kbd>
                </template>
            </div>
        </div>
    </div>

    {{-- RIGHT: Controls Panel --}}
    <div class="w-80 bg-zinc-900 border-l border-zinc-700 flex flex-col shrink-0 overflow-y-auto" x-show="!walkaroundMode">
        {{-- Destinations --}}
        <div class="border-b border-zinc-700">
            <div class="p-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-zinc-300 uppercase tracking-wider">{{ __('Destinations') }}</h3>
                <button @click="showDestModal = true" class="text-indigo-400 hover:text-indigo-300 text-xs font-medium">+ Add</button>
            </div>
            <div class="px-3 pb-3 space-y-2">
                @if($this->session?->destinations)
                    @foreach($this->session->destinations as $dest)
                        <div class="flex items-center gap-2 bg-zinc-800 rounded-lg p-2" wire:key="dest-{{ $dest['id'] }}">
                            <div class="w-6 h-6 rounded flex items-center justify-center text-xs font-bold
                                {{ match($dest['platform']) {
                                    'youtube' => 'bg-red-600',
                                    'tiktok' => 'bg-zinc-800 border border-zinc-600',
                                    'x' => 'bg-zinc-800 border border-zinc-600',
                                    'twitch' => 'bg-purple-600',
                                    'facebook' => 'bg-blue-600',
                                    default => 'bg-zinc-700',
                                } }}">
                                {{ strtoupper(substr($dest['platform'], 0, 2)) }}
                            </div>
                            <span class="text-sm text-zinc-300 flex-1 capitalize">{{ $dest['platform'] }}</span>
                            <button wire:click="toggleDestination('{{ $dest['id'] }}')" class="text-xs {{ $dest['enabled'] ? 'text-green-400' : 'text-zinc-500' }}">
                                {{ $dest['enabled'] ? 'ON' : 'OFF' }}
                            </button>
                            <button wire:click="removeDestination('{{ $dest['id'] }}')" class="text-zinc-500 hover:text-red-400 text-xs">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    @endforeach
                @else
                    <p class="text-zinc-500 text-xs">{{ __('No destinations configured.') }}</p>
                @endif
                @error('destinations')
                    <p class="text-red-400 text-xs">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Overlays --}}
        <div class="border-b border-zinc-700">
            <div class="p-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-zinc-300 uppercase tracking-wider">{{ __('Overlays') }}</h3>
                <button @click="showOverlayModal = true" class="text-indigo-400 hover:text-indigo-300 text-xs font-medium">+ Add</button>
            </div>
            <div class="px-3 pb-3 space-y-2">
                <template x-for="overlay in overlays" :key="overlay.id">
                    <div class="flex items-center gap-2 bg-zinc-800 rounded-lg p-2">
                        <span class="text-xs text-zinc-500 uppercase w-8" x-text="overlay.type"></span>
                        <span class="text-sm text-zinc-300 flex-1 truncate" x-text="overlay.label || overlay.content?.substring(0, 20) || 'Overlay'"></span>
                        <button @click="overlay.visible = !overlay.visible; updateOverlayCache(overlay)" :class="overlay.visible ? 'text-green-400' : 'text-zinc-500'" class="text-xs">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                        <button @click="recaptureHtmlOverlay(overlay)" x-show="overlay.type === 'html'" class="text-indigo-400 hover:text-indigo-300" title="Re-capture">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </button>
                        <button @click="removeOverlay(overlay.id)" class="text-zinc-500 hover:text-red-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>
                <template x-if="overlays.length === 0">
                    <p class="text-zinc-500 text-xs">No overlays added.</p>
                </template>
            </div>
        </div>

        {{-- Participants --}}
        <div class="border-b border-zinc-700">
            <div class="p-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-zinc-300 uppercase tracking-wider">{{ __('Participants') }}</h3>
                <button @click="copyGuestLink()" class="text-indigo-400 hover:text-indigo-300 text-xs font-medium">{{ __('Invite') }}</button>
            </div>
            <div class="px-3 pb-3 space-y-2">
                <div class="flex items-center gap-2 bg-zinc-800 rounded-lg p-2">
                    <div class="w-6 h-6 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold">
                        {{ substr(auth()->user()->name, 0, 1) }}
                    </div>
                    <span class="text-sm text-zinc-300">{{ auth()->user()->name }}</span>
                    <span class="text-xs text-indigo-400 ml-auto">{{ __('Host') }}</span>
                </div>
                <template x-for="participant in remoteParticipants" :key="participant.id">
                    <div class="flex items-center gap-2 bg-zinc-800 rounded-lg p-2">
                        <div class="w-6 h-6 rounded-full bg-green-600 flex items-center justify-center text-xs font-bold" x-text="participant.name.charAt(0)"></div>
                        <span class="text-sm text-zinc-300" x-text="participant.name"></span>
                        <span class="text-xs text-zinc-500 ml-auto">Guest</span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Audio Mixer --}}
        <div>
            <div class="p-3">
                <h3 class="text-sm font-semibold text-zinc-300 uppercase tracking-wider">{{ __('Audio') }}</h3>
            </div>
            <div class="px-3 pb-3 space-y-3">
                {{-- Mic level meter --}}
                <div class="flex items-center gap-3">
                    <span class="text-xs text-zinc-400 w-12">{{ __('Mic') }}</span>
                    <div class="flex-1 flex items-center gap-2">
                        <input type="range" min="0" max="100" x-model="micVolume" @input="updateMicGain()" class="flex-1 accent-indigo-500 h-1" />
                        <div class="w-16 h-2 bg-zinc-800 rounded overflow-hidden">
                            <div class="h-full bg-green-500 transition-all duration-75" :style="`width: ${micLevel}%`"></div>
                        </div>
                    </div>
                    <span class="text-xs text-zinc-500 w-8 text-right" x-text="micVolume + '%'"></span>
                </div>
                <template x-for="participant in remoteParticipants" :key="'audio-'+participant.id">
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-zinc-400 w-12 truncate" x-text="participant.name"></span>
                        <input type="range" min="0" max="100" :value="participant.volume || 100" @input="setParticipantVolume(participant.id, $event.target.value)" class="flex-1 accent-indigo-500 h-1" />
                        <span class="text-xs text-zinc-500 w-8 text-right" x-text="(participant.volume || 100) + '%'"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Walkaround mode: simplified right panel --}}
    <div class="w-72 bg-zinc-900 border-l border-zinc-700 flex flex-col shrink-0 p-4 gap-4" x-show="walkaroundMode">
        <h3 class="text-lg font-semibold">{{ __('Walkaround Mode') }}</h3>
        <p class="text-sm text-zinc-400">{{ __('Simple one-click streaming with your camera and logo.') }}</p>

        {{-- Quick destination add --}}
        <div class="space-y-3">
            <h4 class="text-sm font-semibold text-zinc-300">{{ __('Stream To') }}</h4>
            @if($this->session?->destinations)
                @foreach($this->session->destinations as $dest)
                    <div class="flex items-center gap-2 bg-zinc-800 rounded-lg p-2" wire:key="wa-dest-{{ $dest['id'] }}">
                        <span class="text-sm text-zinc-300 capitalize">{{ $dest['platform'] }}</span>
                        <button wire:click="removeDestination('{{ $dest['id'] }}')" class="text-zinc-500 hover:text-red-400 ml-auto">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                @endforeach
            @endif
            <button @click="showDestModal = true" class="w-full px-3 py-2 bg-zinc-800 hover:bg-zinc-700 rounded-lg text-sm text-zinc-400 flex items-center justify-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __('Add Destination') }}
            </button>
        </div>
    </div>

    {{-- Destination Modal --}}
    <div x-show="showDestModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @keydown.escape.window="showDestModal = false">
        <div class="bg-zinc-800 rounded-xl shadow-2xl w-full max-w-md p-6 space-y-4" @click.outside="showDestModal = false">
            <h3 class="text-lg font-semibold">{{ __('Add Streaming Destination') }}</h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm text-zinc-400 mb-1">{{ __('Platform') }}</label>
                    <select wire:model.live="destPlatform" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white">
                        <option value="youtube">{{ __('YouTube') }}</option>
                        <option value="tiktok">{{ __('TikTok') }}</option>
                        <option value="x">{{ __('X / Twitter') }}</option>
                        <option value="twitch">{{ __('Twitch') }}</option>
                        <option value="facebook">{{ __('Facebook') }}</option>
                        <option value="custom">{{ __('Custom RTMP') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-zinc-400 mb-1">{{ __('RTMP URL') }}</label>
                    <input type="text" wire:model="destRtmpUrl" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white" />
                </div>
                <div>
                    <label class="block text-sm text-zinc-400 mb-1">{{ __('Stream Key') }}</label>
                    <input type="password" wire:model="destStreamKey" placeholder="{{ __('Paste your stream key') }}" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white" />
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button @click="showDestModal = false" class="px-4 py-2 text-sm text-zinc-400 hover:text-white">{{ __('Cancel') }}</button>
                <button wire:click="addDestination" @click="showDestModal = false" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm font-medium">{{ __('Add') }}</button>
            </div>
        </div>
    </div>

    {{-- Overlay Modal (supports Text, Image, and HTML) --}}
    <div x-show="showOverlayModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @keydown.escape.window="showOverlayModal = false">
        <div class="bg-zinc-800 rounded-xl shadow-2xl w-full max-w-lg p-6 space-y-4" @click.outside="showOverlayModal = false">
            <h3 class="text-lg font-semibold">{{ __('Add Overlay') }}</h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm text-zinc-400 mb-1">Type</label>
                    <select x-model="overlayModalType" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white">
                        <option value="text">Text</option>
                        <option value="image">Image URL</option>
                        <option value="html">HTML / Component</option>
                    </select>
                </div>

                {{-- Text overlay --}}
                <div x-show="overlayModalType === 'text'" class="space-y-2">
                    <label class="block text-sm text-zinc-400 mb-1">Text Content</label>
                    <input type="text" x-model="overlayModalContent" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white" placeholder="Enter text..." />
                    <div class="flex gap-2">
                        <label class="text-xs text-zinc-400">Font Size</label>
                        <input type="number" x-model.number="overlayFontSize" min="12" max="120" class="w-16 bg-zinc-700 border-zinc-600 rounded text-sm px-2 py-1 text-white" />
                        <label class="text-xs text-zinc-400 ml-2">Color</label>
                        <input type="color" x-model="overlayFontColor" class="w-8 h-8 bg-zinc-700 border-zinc-600 rounded cursor-pointer" />
                    </div>
                </div>

                {{-- Image overlay --}}
                <div x-show="overlayModalType === 'image'">
                    <label class="block text-sm text-zinc-400 mb-1">Image URL</label>
                    <input type="text" x-model="overlayModalContent" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white" placeholder="https://..." />
                </div>

                {{-- HTML overlay --}}
                <div x-show="overlayModalType === 'html'" class="space-y-2">
                    <label class="block text-sm text-zinc-400 mb-1">HTML Content</label>
                    <textarea x-model="overlayModalContent" rows="6" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white font-mono" placeholder="<div style='padding: 16px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 8px;'>&#10;  <h2 style='color: white; margin: 0;'>Your Title</h2>&#10;  <p style='color: rgba(255,255,255,0.8); margin: 4px 0 0;'>Subtitle text</p>&#10;</div>"></textarea>
                    <div>
                        <label class="block text-sm text-zinc-400 mb-1">Label (for sidebar)</label>
                        <input type="text" x-model="overlayModalLabel" class="w-full bg-zinc-700 border-zinc-600 rounded-lg text-sm px-3 py-2 text-white" placeholder="e.g. Lower Third, Logo Banner..." />
                    </div>
                    <div class="flex gap-2">
                        <label class="text-xs text-zinc-400">Width (px)</label>
                        <input type="number" x-model.number="overlayHtmlWidth" min="100" max="1280" class="w-20 bg-zinc-700 border-zinc-600 rounded text-sm px-2 py-1 text-white" />
                        <label class="text-xs text-zinc-400 ml-2">Height (px)</label>
                        <input type="number" x-model.number="overlayHtmlHeight" min="50" max="720" class="w-20 bg-zinc-700 border-zinc-600 rounded text-sm px-2 py-1 text-white" />
                    </div>
                    <p class="text-xs text-zinc-500">Use any HTML/CSS. The element is captured as an image via html2canvas and composited onto the stream canvas. Re-capture manually when content changes.</p>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button @click="showOverlayModal = false" class="px-4 py-2 text-sm text-zinc-400 hover:text-white">{{ __('Cancel') }}</button>
                <button @click="addOverlayFromModal(); showOverlayModal = false" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm font-medium">{{ __('Add') }}</button>
            </div>
        </div>
    </div>

    {{-- Hidden video elements for source feeds --}}
    <video x-ref="localVideo" autoplay muted playsinline class="hidden"></video>
    <template x-for="participant in remoteParticipants" :key="'video-'+participant.id">
        <video :id="'remoteVideo-'+participant.id" autoplay playsinline class="hidden"></video>
    </template>

    {{-- Hidden container for html2canvas rendering --}}
    <div x-ref="htmlRenderContainer" class="fixed -left-[9999px] -top-[9999px]" style="z-index: -1;"></div>

    {{-- Guest link copied toast --}}
    <div x-show="showCopiedToast" x-transition.opacity class="fixed bottom-24 left-1/2 -translate-x-1/2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm shadow-lg z-50">
        {{ __('Guest invite link copied to clipboard!') }}
    </div>
</div>

@script
<script>
Alpine.data('studioEngine', () => ({
    // State
    scenes: @json($this->session->scenes ?? []),
    currentSceneIndex: @json($this->session->current_scene_index ?? 0),
    overlays: @json($this->session->overlays ?? []),
    walkaroundMode: @json($walkaroundMode),
    isLive: @json($this->session->status === 'live'),
    companyLogo: @json($this->companyLogo),
    pythonWsUrl: @json($this->pythonWsUrl),
    sessionRoomId: @json($this->session->room_id),
    sessionId: @json($this->session->id),
    userId: {{ auth()->id() }},
    userName: @json(auth()->user()->name),

    // Media state
    localStream: null,
    cameraEnabled: true,
    micEnabled: true,
    micVolume: 80,
    cameraReady: false,

    // Canvas
    canvas: null,
    ctx: null,
    animFrameId: null,

    // Streaming
    mediaRecorder: null,
    ws: null,
    wsConnected: false,
    streamBitrate: 0,
    lastBytesSent: 0,
    lastBytesTime: 0,
    elapsedTime: '00:00:00',
    streamStartTime: null,

    // Stream health
    fps: 0,
    frameCount: 0,
    lastFpsTime: 0,
    droppedFrames: 0,

    // Local recording
    isRecordingLocally: false,
    localRecorder: null,
    localRecordingChunks: [],

    // Modals
    showDestModal: false,
    showOverlayModal: false,
    overlayModalType: 'text',
    overlayModalContent: '',
    overlayModalLabel: '',
    overlayFontSize: 32,
    overlayFontColor: '#ffffff',
    overlayHtmlWidth: 400,
    overlayHtmlHeight: 120,
    showCopiedToast: false,

    // Participants & WebRTC
    remoteParticipants: [],
    peerConnections: {},
    remoteStreams: {},
    echoChannel: null,

    // Overlays
    overlayCaches: {},
    isDragging: false,
    dragOverlay: null,
    dragStartX: 0,
    dragStartY: 0,

    // Audio mixer
    audioCtx: null,
    mixedDestination: null,
    micGainNode: null,
    micAnalyser: null,
    micLevel: 0,
    participantGainNodes: {},

    async init() {
        this.canvas = this.$refs.outputCanvas;
        this.ctx = this.canvas.getContext('2d', { alpha: false });

        // Initialize Web Audio API mixer
        this.initAudioMixer();

        // Start camera
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                audio: true,
            });

            // Set video element source and wait for it to be ready
            const videoEl = this.$refs.localVideo;
            videoEl.srcObject = this.localStream;
            await videoEl.play();
            this.cameraReady = true;

            // Connect local mic to audio mixer
            this.connectLocalAudioToMixer();
        } catch (e) {
            console.error('Failed to get camera:', e);
            // Still allow the page to work without camera
            this.cameraReady = false;
        }

        // If walkaround mode and company logo exists, add logo overlay
        if (this.walkaroundMode && this.companyLogo) {
            this.addOverlay('image', this.companyLogo, { x: 0.02, y: 0.02, w: 0.15, h: 0.1 });
        }

        // Start canvas render loop
        this.lastFpsTime = performance.now();
        this.renderFrame();

        // Join Echo presence channel for WebRTC signaling
        this.joinPresenceChannel();

        // Listen for Livewire events
        this.$wire.on('destinations-updated', () => {});
        this.$wire.on('scene-switched', ({ index }) => { this.currentSceneIndex = index; });
        this.$wire.on('go-live', ({ destinations }) => { this.startStreaming(destinations); });
        this.$wire.on('stream-stopped', () => { this.stopStreaming(); });

        // Keyboard shortcuts for scene switching
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
            const num = parseInt(e.key);
            if (num >= 1 && num <= this.scenes.length) {
                this.switchScene(num - 1);
            }
        });

        // Elapsed time ticker
        setInterval(() => {
            if (this.isLive && this.streamStartTime) {
                const elapsed = Math.floor((Date.now() - this.streamStartTime) / 1000);
                const h = String(Math.floor(elapsed / 3600)).padStart(2, '0');
                const m = String(Math.floor((elapsed % 3600) / 60)).padStart(2, '0');
                const s = String(elapsed % 60).padStart(2, '0');
                this.elapsedTime = `${h}:${m}:${s}`;
            }
        }, 1000);

        // Mic level meter
        this.startMicLevelMeter();
    },

    // =============================================
    // Web Audio API Mixer
    // =============================================
    initAudioMixer() {
        this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        this.mixedDestination = this.audioCtx.createMediaStreamDestination();
    },

    connectLocalAudioToMixer() {
        if (!this.localStream || !this.audioCtx) return;

        const source = this.audioCtx.createMediaStreamSource(this.localStream);
        this.micGainNode = this.audioCtx.createGain();
        this.micGainNode.gain.value = this.micVolume / 100;

        // Analyser for level meter
        this.micAnalyser = this.audioCtx.createAnalyser();
        this.micAnalyser.fftSize = 256;

        source.connect(this.micGainNode);
        this.micGainNode.connect(this.mixedDestination);
        this.micGainNode.connect(this.micAnalyser);
    },

    connectRemoteAudioToMixer(participantId, stream) {
        if (!this.audioCtx) return;

        const source = this.audioCtx.createMediaStreamSource(stream);
        const gainNode = this.audioCtx.createGain();
        gainNode.gain.value = 1.0;

        source.connect(gainNode);
        gainNode.connect(this.mixedDestination);

        this.participantGainNodes[participantId] = gainNode;
    },

    updateMicGain() {
        if (this.micGainNode) {
            this.micGainNode.gain.value = this.micVolume / 100;
        }
    },

    startMicLevelMeter() {
        const updateLevel = () => {
            if (this.micAnalyser) {
                const data = new Uint8Array(this.micAnalyser.frequencyBinCount);
                this.micAnalyser.getByteFrequencyData(data);
                const avg = data.reduce((a, b) => a + b, 0) / data.length;
                this.micLevel = Math.min(100, Math.round(avg / 128 * 100));
            }
            requestAnimationFrame(updateLevel);
        };
        updateLevel();
    },

    // =============================================
    // Canvas Render Loop
    // =============================================
    renderFrame() {
        if (!this.ctx) return;

        const W = this.canvas.width;
        const H = this.canvas.height;

        // 1. Clear canvas
        this.ctx.fillStyle = '#000';
        this.ctx.fillRect(0, 0, W, H);

        // 2. Get current scene layout
        const scene = this.scenes[this.currentSceneIndex];
        if (!scene) {
            this.animFrameId = requestAnimationFrame(() => this.renderFrame());
            return;
        }

        // 3. Draw each source slot
        for (const slot of (scene.slots || [])) {
            let videoEl = null;

            if (slot.source === 'local') {
                videoEl = this.$refs.localVideo;
            } else if (slot.source?.startsWith('remote-')) {
                // Look up by participant index
                const idx = parseInt(slot.source.split('-')[1]) - 1;
                if (this.remoteParticipants[idx]) {
                    videoEl = document.getElementById('remoteVideo-' + this.remoteParticipants[idx].id);
                }
            }

            if (videoEl && videoEl.readyState >= 2 && videoEl.videoWidth > 0) {
                const dx = slot.x * W;
                const dy = slot.y * H;
                const dw = slot.w * W;
                const dh = slot.h * H;

                // Cover-fit: crop to fill the slot
                const videoAspect = videoEl.videoWidth / videoEl.videoHeight;
                const slotAspect = dw / dh;

                let sx = 0, sy = 0, sw = videoEl.videoWidth, sh = videoEl.videoHeight;
                if (videoAspect > slotAspect) {
                    sw = videoEl.videoHeight * slotAspect;
                    sx = (videoEl.videoWidth - sw) / 2;
                } else {
                    sh = videoEl.videoWidth / slotAspect;
                    sy = (videoEl.videoHeight - sh) / 2;
                }

                this.ctx.drawImage(videoEl, sx, sy, sw, sh, dx, dy, dw, dh);
            }
        }

        // 4. Draw cached overlays
        const activeOverlays = this.getActiveOverlays();
        for (const overlay of activeOverlays) {
            if (!overlay.visible) continue;
            const cached = this.overlayCaches[overlay.id];
            if (cached) {
                this.ctx.drawImage(cached, overlay.x * W, overlay.y * H, overlay.w * W, overlay.h * H);
            }
        }

        // 5. FPS counter
        this.frameCount++;
        const now = performance.now();
        if (now - this.lastFpsTime >= 1000) {
            this.fps = this.frameCount;
            this.frameCount = 0;
            this.lastFpsTime = now;
        }

        // 6. Schedule next frame
        this.animFrameId = requestAnimationFrame(() => this.renderFrame());
    },

    getActiveOverlays() {
        const scene = this.scenes[this.currentSceneIndex];
        if (!scene) return this.overlays;
        return [...this.overlays, ...(scene.overlays || [])];
    },

    get activeOverlays() {
        return this.getActiveOverlays();
    },

    // =============================================
    // WebRTC via Echo Presence Channel
    // =============================================
    joinPresenceChannel() {
        if (!window.Echo) return;

        this.echoChannel = window.Echo.join(`studio.${this.sessionId}`)
            .here((users) => {
                // Users already in the channel (excluding self)
                users.forEach(u => {
                    if (u.id !== this.userId) {
                        this.addRemoteParticipant(u);
                    }
                });
            })
            .joining((user) => {
                if (user.id !== this.userId) {
                    this.addRemoteParticipant(user);
                    // As host, send an offer to the new participant
                    this.createPeerConnection(user.id, true);
                }
            })
            .leaving((user) => {
                this.removeRemoteParticipant(user.id);
            })
            .listenForWhisper('webrtc-offer', (data) => {
                this.handleWebRTCOffer(data);
            })
            .listenForWhisper('webrtc-answer', (data) => {
                this.handleWebRTCAnswer(data);
            })
            .listenForWhisper('webrtc-ice', (data) => {
                this.handleWebRTCIce(data);
            });
    },

    addRemoteParticipant(user) {
        if (!this.remoteParticipants.find(p => p.id === user.id)) {
            this.remoteParticipants.push({
                id: user.id,
                name: user.name,
                volume: 100,
            });
        }
    },

    removeRemoteParticipant(userId) {
        this.remoteParticipants = this.remoteParticipants.filter(p => p.id !== userId);
        if (this.peerConnections[userId]) {
            this.peerConnections[userId].close();
            delete this.peerConnections[userId];
        }
        delete this.remoteStreams[userId];
        delete this.participantGainNodes[userId];
    },

    async createPeerConnection(remoteUserId, isInitiator) {
        const pc = new RTCPeerConnection({
            iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
        });
        this.peerConnections[remoteUserId] = pc;

        // Add local tracks to the connection
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                pc.addTrack(track, this.localStream);
            });
        }

        // Handle incoming remote tracks
        pc.ontrack = (event) => {
            const stream = event.streams[0];
            this.remoteStreams[remoteUserId] = stream;

            // Attach to hidden video element for canvas rendering
            this.$nextTick(() => {
                const videoEl = document.getElementById('remoteVideo-' + remoteUserId);
                if (videoEl) {
                    videoEl.srcObject = stream;
                    videoEl.play().catch(() => {});
                }
            });

            // Connect audio to mixer
            this.connectRemoteAudioToMixer(remoteUserId, stream);
        };

        // Handle ICE candidates
        pc.onicecandidate = (event) => {
            if (event.candidate && this.echoChannel) {
                this.echoChannel.whisper('webrtc-ice', {
                    from: this.userId,
                    to: remoteUserId,
                    candidate: event.candidate,
                });
            }
        };

        // If we're the initiator, create and send an offer
        if (isInitiator) {
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            this.echoChannel.whisper('webrtc-offer', {
                from: this.userId,
                to: remoteUserId,
                sdp: pc.localDescription,
            });
        }
    },

    async handleWebRTCOffer(data) {
        if (data.to !== this.userId) return;

        await this.createPeerConnection(data.from, false);
        const pc = this.peerConnections[data.from];

        await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);

        this.echoChannel.whisper('webrtc-answer', {
            from: this.userId,
            to: data.from,
            sdp: pc.localDescription,
        });
    },

    async handleWebRTCAnswer(data) {
        if (data.to !== this.userId) return;
        const pc = this.peerConnections[data.from];
        if (pc) {
            await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
        }
    },

    async handleWebRTCIce(data) {
        if (data.to !== this.userId) return;
        const pc = this.peerConnections[data.from];
        if (pc && data.candidate) {
            await pc.addIceCandidate(new RTCIceCandidate(data.candidate));
        }
    },

    // =============================================
    // Scene Management
    // =============================================
    switchScene(index) {
        this.currentSceneIndex = index;
        this.$wire.switchScene(index);
    },

    addScene() {
        const newScene = {
            name: 'Scene ' + (this.scenes.length + 1),
            layout: 'solo',
            slots: [{ source: 'local', x: 0, y: 0, w: 1, h: 1 }],
            overlays: [],
        };
        this.scenes.push(newScene);
        this.$wire.saveScenes(this.scenes);
    },

    // =============================================
    // Overlay Management (Text, Image, HTML via html2canvas)
    // =============================================
    addOverlayFromModal() {
        if (this.overlayModalType === 'html') {
            this.addHtmlOverlay(this.overlayModalContent, this.overlayModalLabel);
        } else {
            this.addOverlay(this.overlayModalType, this.overlayModalContent);
        }
        this.overlayModalContent = '';
        this.overlayModalLabel = '';
    },

    addOverlay(type, content, position = {}) {
        const overlay = {
            id: 'overlay-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4),
            type,
            content,
            label: null,
            x: position.x ?? 0.05,
            y: position.y ?? 0.85,
            w: position.w ?? 0.3,
            h: position.h ?? 0.1,
            visible: true,
            style: type === 'text' ? `font-size: ${this.overlayFontSize}px; color: ${this.overlayFontColor};` : '',
        };
        this.overlays.push(overlay);
        this.updateOverlayCache(overlay);
    },

    async addHtmlOverlay(htmlContent, label) {
        const overlay = {
            id: 'overlay-html-' + Date.now(),
            type: 'html',
            content: htmlContent,
            label: label || 'HTML Overlay',
            x: 0.05,
            y: 0.75,
            w: this.overlayHtmlWidth / 1280,
            h: this.overlayHtmlHeight / 720,
            visible: true,
            renderWidth: this.overlayHtmlWidth,
            renderHeight: this.overlayHtmlHeight,
        };
        this.overlays.push(overlay);
        await this.captureHtmlOverlay(overlay);
    },

    async captureHtmlOverlay(overlay) {
        if (!window.html2canvas || overlay.type !== 'html') return;

        // Create a temporary DOM element with the HTML content
        const container = this.$refs.htmlRenderContainer;
        const wrapper = document.createElement('div');
        wrapper.style.width = (overlay.renderWidth || 400) + 'px';
        wrapper.style.height = (overlay.renderHeight || 120) + 'px';
        wrapper.style.position = 'absolute';
        wrapper.style.overflow = 'hidden';
        wrapper.innerHTML = overlay.content;
        container.appendChild(wrapper);

        try {
            // Wait for images/fonts to load
            await new Promise(r => setTimeout(r, 100));

            const capturedCanvas = await window.html2canvas(wrapper, {
                backgroundColor: null, // Transparent background
                scale: 2, // High-res capture
                useCORS: true,
                logging: false,
            });

            const bitmap = await createImageBitmap(capturedCanvas);
            this.overlayCaches[overlay.id] = bitmap;
        } catch (e) {
            console.error('html2canvas capture failed:', e);
        } finally {
            container.removeChild(wrapper);
        }
    },

    async recaptureHtmlOverlay(overlay) {
        if (overlay.type === 'html') {
            await this.captureHtmlOverlay(overlay);
        }
    },

    removeOverlay(id) {
        this.overlays = this.overlays.filter(o => o.id !== id);
        delete this.overlayCaches[id];
    },

    async updateOverlayCache(overlay) {
        if (overlay.type === 'image') {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = async () => {
                try {
                    const bitmap = await createImageBitmap(img);
                    this.overlayCaches[overlay.id] = bitmap;
                } catch (e) {
                    console.error('Failed to create bitmap from image:', e);
                }
            };
            img.onerror = () => console.error('Failed to load overlay image:', overlay.content);
            img.src = overlay.content;
        } else if (overlay.type === 'text') {
            // Render text to an offscreen canvas
            const fontSize = parseInt(overlay.style?.match(/font-size:\s*(\d+)/)?.[1] || this.overlayFontSize);
            const color = overlay.style?.match(/color:\s*(#[0-9a-fA-F]+)/)?.[1] || this.overlayFontColor;
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = 800;
            tempCanvas.height = Math.max(80, fontSize * 2);
            const tempCtx = tempCanvas.getContext('2d');
            tempCtx.clearRect(0, 0, tempCanvas.width, tempCanvas.height);

            // Text with shadow for readability
            tempCtx.font = `bold ${fontSize}px sans-serif`;
            tempCtx.shadowColor = 'rgba(0,0,0,0.9)';
            tempCtx.shadowBlur = 6;
            tempCtx.shadowOffsetX = 2;
            tempCtx.shadowOffsetY = 2;
            tempCtx.fillStyle = color;
            tempCtx.fillText(overlay.content, 10, fontSize + 10);

            try {
                const bitmap = await createImageBitmap(tempCanvas);
                this.overlayCaches[overlay.id] = bitmap;
            } catch (e) {
                console.error('Failed to cache text overlay:', e);
            }
        } else if (overlay.type === 'html') {
            await this.captureHtmlOverlay(overlay);
        }
    },

    startDragOverlay(event, overlay) {
        if (this.isLive) return;
        this.isDragging = true;
        this.dragOverlay = overlay;
        const containerRect = this.$refs.overlayContainer.getBoundingClientRect();
        this.dragStartX = event.clientX - (overlay.x * containerRect.width);
        this.dragStartY = event.clientY - (overlay.y * containerRect.height);

        const onMove = (e) => {
            if (!this.isDragging) return;
            const x = (e.clientX - this.dragStartX) / containerRect.width;
            const y = (e.clientY - this.dragStartY) / containerRect.height;
            this.dragOverlay.x = Math.max(0, Math.min(1 - this.dragOverlay.w, x));
            this.dragOverlay.y = Math.max(0, Math.min(1 - this.dragOverlay.h, y));
        };

        const onUp = () => {
            this.isDragging = false;
            this.dragOverlay = null;
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    },

    startResizeOverlay(event, overlay) {
        if (this.isLive) return;
        const containerRect = this.$refs.overlayContainer.getBoundingClientRect();
        const startW = overlay.w;
        const startH = overlay.h;
        const startX = event.clientX;
        const startY = event.clientY;

        const onMove = (e) => {
            const dw = (e.clientX - startX) / containerRect.width;
            const dh = (e.clientY - startY) / containerRect.height;
            overlay.w = Math.max(0.05, Math.min(1 - overlay.x, startW + dw));
            overlay.h = Math.max(0.03, Math.min(1 - overlay.y, startH + dh));
        };

        const onUp = () => {
            // Re-cache if html overlay was resized
            if (overlay.type === 'html') {
                overlay.renderWidth = Math.round(overlay.w * 1280);
                overlay.renderHeight = Math.round(overlay.h * 720);
                this.captureHtmlOverlay(overlay);
            }
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    },

    // =============================================
    // Media Controls
    // =============================================
    toggleMic() {
        this.micEnabled = !this.micEnabled;
        if (this.localStream) {
            this.localStream.getAudioTracks().forEach(t => t.enabled = this.micEnabled);
        }
    },

    toggleCamera() {
        this.cameraEnabled = !this.cameraEnabled;
        if (this.localStream) {
            this.localStream.getVideoTracks().forEach(t => t.enabled = this.cameraEnabled);
        }
    },

    // =============================================
    // RTMP Streaming
    // =============================================
    async goLive() {
        this.$wire.goLive();
    },

    async startStreaming(destinations) {
        this.isLive = true;
        this.streamStartTime = Date.now();
        this.droppedFrames = 0;

        // Create composite stream from canvas (video)
        const compositeStream = this.canvas.captureStream(30);

        // Use the mixed audio from Web Audio API instead of raw mic
        if (this.mixedDestination) {
            this.mixedDestination.stream.getAudioTracks().forEach(t => {
                compositeStream.addTrack(t);
            });
        } else if (this.localStream) {
            // Fallback: use raw mic audio
            this.localStream.getAudioTracks().forEach(t => compositeStream.addTrack(t));
        }

        // Determine codec - prefer H.264 for RTMP passthrough
        const h264Supported = MediaRecorder.isTypeSupported('video/webm;codecs=h264,opus');
        const h264Only = MediaRecorder.isTypeSupported('video/webm;codecs=h264');
        const mimeType = h264Supported ? 'video/webm;codecs=h264,opus'
                       : h264Only ? 'video/webm;codecs=h264'
                       : 'video/webm';

        // Start MediaRecorder
        this.mediaRecorder = new MediaRecorder(compositeStream, {
            mimeType,
            videoBitsPerSecond: 2_500_000,
            audioBitsPerSecond: 128_000,
        });

        // Connect WebSocket to Python RTMP relay
        this.ws = new WebSocket(this.pythonWsUrl);
        this.ws.binaryType = 'arraybuffer';

        this.ws.onopen = () => {
            this.wsConnected = true;
            console.log('RTMP relay WebSocket connected');
        };

        this.ws.onclose = () => {
            this.wsConnected = false;
            console.log('RTMP relay WebSocket closed');
        };

        this.ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data.type === 'stats') {
                    const now = Date.now();
                    if (this.lastBytesTime > 0) {
                        const deltaBytes = data.bytes_received - this.lastBytesSent;
                        const deltaTime = (now - this.lastBytesTime) / 1000;
                        this.streamBitrate = deltaTime > 0 ? (deltaBytes * 8) / deltaTime : 0;
                    }
                    this.lastBytesSent = data.bytes_received;
                    this.lastBytesTime = now;
                } else if (data.type === 'error') {
                    console.error('RTMP relay error:', data.message);
                }
            } catch (e) {
                // Ignore non-JSON messages
            }
        };

        this.ws.onerror = (e) => {
            this.wsConnected = false;
            console.error('WebSocket error:', e);
        };

        this.mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0 && this.ws?.readyState === WebSocket.OPEN) {
                this.ws.send(e.data);
            } else if (e.data.size > 0 && this.ws?.readyState !== WebSocket.OPEN) {
                this.droppedFrames++;
            }
        };

        this.mediaRecorder.start(1000); // 1-second chunks
    },

    async stopStream() {
        this.$wire.stopStream();
    },

    stopStreaming() {
        this.isLive = false;

        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
            this.mediaRecorder = null;
        }

        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }

        this.wsConnected = false;
        this.streamStartTime = null;
        this.elapsedTime = '00:00:00';
        this.streamBitrate = 0;
        this.lastBytesSent = 0;
        this.lastBytesTime = 0;
    },

    // =============================================
    // Local Recording
    // =============================================
    toggleLocalRecording() {
        if (this.isRecordingLocally) {
            this.stopLocalRecording();
        } else {
            this.startLocalRecording();
        }
    },

    startLocalRecording() {
        const compositeStream = this.canvas.captureStream(30);
        if (this.mixedDestination) {
            this.mixedDestination.stream.getAudioTracks().forEach(t => compositeStream.addTrack(t));
        } else if (this.localStream) {
            this.localStream.getAudioTracks().forEach(t => compositeStream.addTrack(t));
        }

        const mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')
            ? 'video/webm;codecs=vp9,opus' : 'video/webm';

        this.localRecordingChunks = [];
        this.localRecorder = new MediaRecorder(compositeStream, {
            mimeType,
            videoBitsPerSecond: 4_000_000,
        });

        this.localRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) this.localRecordingChunks.push(e.data);
        };

        this.localRecorder.onstop = () => {
            const blob = new Blob(this.localRecordingChunks, { type: mimeType });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `studio-recording-${new Date().toISOString().slice(0,19)}.webm`;
            a.click();
            URL.revokeObjectURL(url);
            this.localRecordingChunks = [];
        };

        this.localRecorder.start(1000);
        this.isRecordingLocally = true;
    },

    stopLocalRecording() {
        if (this.localRecorder && this.localRecorder.state !== 'inactive') {
            this.localRecorder.stop();
        }
        this.isRecordingLocally = false;
    },

    // =============================================
    // Guest Participants
    // =============================================
    async copyGuestLink() {
        const link = await this.$wire.generateGuestLink();
        await navigator.clipboard.writeText(link);
        this.showCopiedToast = true;
        setTimeout(() => this.showCopiedToast = false, 3000);
    },

    setParticipantVolume(participantId, volume) {
        const p = this.remoteParticipants.find(p => p.id === participantId);
        if (p) p.volume = parseInt(volume);

        // Update gain node
        const gainNode = this.participantGainNodes[participantId];
        if (gainNode) {
            gainNode.gain.value = parseInt(volume) / 100;
        }
    },

    // =============================================
    // Cleanup
    // =============================================
    destroy() {
        if (this.animFrameId) cancelAnimationFrame(this.animFrameId);
        if (this.localStream) this.localStream.getTracks().forEach(t => t.stop());
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') this.mediaRecorder.stop();
        if (this.localRecorder && this.localRecorder.state !== 'inactive') this.localRecorder.stop();
        if (this.ws) this.ws.close();
        if (this.audioCtx) this.audioCtx.close();
        if (this.echoChannel) window.Echo?.leave(`studio.${this.sessionId}`);
        Object.values(this.peerConnections).forEach(pc => pc.close());
    },
}));
</script>
@endscript
