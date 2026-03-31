@props(['event', 'token' => null, 'url' => null, 'autoplay' => true])

@php
    $livekitUrl = $url ?? config('services.livekit.url', '');
    $viewerToken = $token;
    if (!$viewerToken && $event->status === 'live') {
        try {
            $viewerToken = app(\App\Services\LiveKitService::class)
                ->generateViewerToken($event, 'Viewer', auth()->id());
        } catch (\Throwable $e) {
            // Token generation failed — show offline state
        }
    }
@endphp

<div
    x-data="{
        status: '{{ $event->status === 'live' ? 'connecting' : 'offline' }}',
        volume: 1,
        muted: false,
        fullscreen: false,
        player: null,

        async init() {
            @if ($viewerToken && $event->status === 'live')
                const LivePlayer = await window.loadLivePlayer();
                this.player = new LivePlayer(this.$refs.video, {
                    onStatusChange: (s) => { this.status = s; },
                });
                try {
                    await this.player.connect(@js($livekitUrl), @js($viewerToken));
                } catch (err) {
                    console.error('[LivePlayer] Connection failed:', err);
                    this.status = 'error';
                }
            @endif
        },

        setVolume(v) {
            this.volume = v;
            this.muted = v == 0;
            if (this.player) this.player.setVolume(v);
        },

        toggleMute() {
            this.muted = !this.muted;
            if (this.player) this.player.setVolume(this.muted ? 0 : this.volume);
        },

        toggleFullscreen() {
            if (document.fullscreenElement) {
                document.exitFullscreen();
                this.fullscreen = false;
            } else {
                this.$refs.container.requestFullscreen();
                this.fullscreen = true;
            }
        },

        destroy() {
            if (this.player) this.player.disconnect();
        },
    }"
    x-ref="container"
    class="relative overflow-hidden rounded-lg bg-black"
    {{ $attributes }}
>
    {{-- Video element --}}
    <video
        x-ref="video"
        autoplay
        playsinline
        class="h-full w-full object-contain"
        :muted="muted"
    ></video>

    {{-- Offline / Connecting overlay --}}
    <div
        x-show="status === 'offline' || status === 'connecting' || status === 'error'"
        class="absolute inset-0 flex items-center justify-center bg-black/80"
    >
        <div class="text-center">
            <template x-if="status === 'connecting'">
                <div>
                    <div class="mx-auto mb-3 size-8 animate-spin rounded-full border-2 border-white/20 border-t-white"></div>
                    <p class="text-sm text-white/70">Connecting to stream...</p>
                </div>
            </template>
            <template x-if="status === 'offline'">
                <div>
                    <svg class="mx-auto mb-3 size-10 text-white/40" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                    <p class="text-sm text-white/50">Stream is offline</p>
                </div>
            </template>
            <template x-if="status === 'error'">
                <p class="text-sm text-red-400">Connection failed</p>
            </template>
        </div>
    </div>

    {{-- Controls bar --}}
    <div
        x-show="status === 'playing' || status === 'connected'"
        class="absolute inset-x-0 bottom-0 flex items-center gap-3 bg-gradient-to-t from-black/80 to-transparent px-4 py-3"
    >
        {{-- LIVE badge --}}
        <span class="rounded bg-red-600 px-2 py-0.5 text-xs font-bold uppercase text-white">Live</span>

        {{-- Volume --}}
        <button @click="toggleMute()" class="text-white/80 hover:text-white">
            <svg x-show="!muted" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
            <svg x-show="muted" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6 4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
        </button>
        <input
            type="range" min="0" max="1" step="0.05"
            :value="muted ? 0 : volume"
            @input="setVolume($event.target.value)"
            class="h-1 w-20 cursor-pointer appearance-none rounded-full bg-white/30 accent-white"
        />

        <div class="flex-1"></div>

        {{-- Fullscreen --}}
        <button @click="toggleFullscreen()" class="text-white/80 hover:text-white">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>
        </button>
    </div>
</div>
