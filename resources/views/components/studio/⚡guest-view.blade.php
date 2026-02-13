<?php

use App\Models\StreamSession;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.video-call', ['title' => 'Join Stream'])]
class extends Component {
    public ?StreamSession $session = null;
    public string $guestName = '';
    public bool $joined = false;

    public function mount(string $token): void
    {
        $this->session = StreamSession::where('room_id', $token)->firstOrFail();

        if ($this->session->isEnded()) {
            abort(410, 'This streaming session has ended.');
        }
    }

    public function joinSession(): void
    {
        $this->validate([
            'guestName' => 'required|string|min:2|max:50',
        ]);

        $this->joined = true;
        $this->dispatch('guest-joined', name: $this->guestName, sessionId: $this->session->id);
    }
};
?>

<div x-data="guestView()" class="flex flex-col h-screen bg-zinc-900 text-white">
    @if(!$joined)
        {{-- Pre-join screen --}}
        <div class="flex-1 flex items-center justify-center">
            <div class="max-w-md w-full space-y-6 p-6">
                <div class="text-center">
                    <h1 class="text-2xl font-bold">{{ __('Join Stream') }}</h1>
                    <p class="text-zinc-400 mt-2">{{ $session->title ?? __('Streaming Session') }}</p>
                    <p class="text-zinc-500 text-sm mt-1">Hosted by {{ $session->user->name }}</p>
                </div>

                {{-- Camera preview --}}
                <div class="relative aspect-video bg-zinc-800 rounded-xl overflow-hidden">
                    <video x-ref="previewVideo" autoplay muted playsinline class="w-full h-full object-cover"></video>
                    <div class="absolute inset-0 flex items-center justify-center" x-show="!cameraReady">
                        <p class="text-zinc-500 text-sm">{{ __('Starting camera...') }}</p>
                    </div>
                </div>

                {{-- Name input --}}
                <div>
                    <label class="block text-sm text-zinc-400 mb-1">{{ __('Your Name') }}</label>
                    <input
                        type="text"
                        wire:model="guestName"
                        placeholder="{{ __('Enter your name') }}"
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2.5 text-white placeholder-zinc-500 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        @keydown.enter="$wire.joinSession()"
                    />
                    @error('guestName')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Controls --}}
                <div class="flex items-center justify-center gap-4">
                    <button
                        @click="toggleMic()"
                        :class="micEnabled ? 'bg-zinc-700' : 'bg-red-600'"
                        class="p-3 rounded-full hover:opacity-80 transition-opacity"
                    >
                        <svg x-show="micEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-14 0m7 0V3m0 8v4m-4 1a4 4 0 008 0"/></svg>
                        <svg x-show="!micEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"/></svg>
                    </button>

                    <button
                        @click="toggleCamera()"
                        :class="cameraEnabled ? 'bg-zinc-700' : 'bg-red-600'"
                        class="p-3 rounded-full hover:opacity-80 transition-opacity"
                    >
                        <svg x-show="cameraEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <svg x-show="!cameraEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    </button>
                </div>

                {{-- Join button --}}
                <button
                    wire:click="joinSession"
                    class="w-full py-3 bg-indigo-600 hover:bg-indigo-500 rounded-lg font-semibold text-sm transition-colors"
                    :disabled="!cameraReady"
                >
                    {{ __('Join Stream') }}
                </button>
            </div>
        </div>
    @else
        {{-- In-session view --}}
        <div class="flex-1 flex flex-col" x-init="connectToHost()">
            {{-- Header --}}
            <div class="h-12 bg-zinc-900 border-b border-zinc-700 flex items-center px-4 gap-3 shrink-0">
                <div :class="connected ? 'bg-green-500' : 'bg-yellow-500 animate-pulse'" class="w-2.5 h-2.5 rounded-full"></div>
                <span class="text-sm font-medium" x-text="connected ? 'Connected to {{ $session->title ?? 'Stream' }}' : 'Connecting...'"></span>
                <div class="flex-1"></div>
                <span class="text-zinc-500 text-xs">{{ $guestName }}</span>
            </div>

            {{-- Video --}}
            <div class="flex-1 flex items-center justify-center bg-black p-4">
                <div class="relative w-full max-w-3xl aspect-video">
                    <video x-ref="localVideo" autoplay muted playsinline class="w-full h-full rounded-lg object-cover bg-zinc-800"></video>
                    <div class="absolute bottom-3 left-3 bg-black/60 px-3 py-1 rounded-full text-xs text-zinc-300">
                        {{ $guestName }}
                    </div>
                </div>
            </div>

            {{-- Controls --}}
            <div class="h-16 bg-zinc-900 border-t border-zinc-700 flex items-center justify-center gap-4 shrink-0">
                <button @click="toggleMic()" :class="micEnabled ? 'bg-zinc-700' : 'bg-red-600'" class="p-3 rounded-full hover:opacity-80 transition-opacity">
                    <svg x-show="micEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-14 0m7 0V3m0 8v4m-4 1a4 4 0 008 0"/></svg>
                    <svg x-show="!micEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"/></svg>
                </button>

                <button @click="toggleCamera()" :class="cameraEnabled ? 'bg-zinc-700' : 'bg-red-600'" class="p-3 rounded-full hover:opacity-80 transition-opacity">
                    <svg x-show="cameraEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <svg x-show="!cameraEnabled" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                </button>

                <button @click="leaveSession()" class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-lg text-sm font-medium">
                    {{ __('Leave') }}
                </button>
            </div>
        </div>
    @endif
</div>

@script
<script>
Alpine.data('guestView', () => ({
    localStream: null,
    cameraEnabled: true,
    micEnabled: true,
    cameraReady: false,
    connected: false,
    sessionId: @json($session->id),
    userId: {{ auth()->id() ?? 0 }},
    userName: @json($guestName ?: 'Guest'),
    echoChannel: null,
    peerConnections: {},

    async init() {
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: true,
            });

            const previewEl = this.$refs.previewVideo;
            if (previewEl) {
                previewEl.srcObject = this.localStream;
                await previewEl.play();
            }
            this.cameraReady = true;
        } catch (e) {
            console.error('Failed to get camera:', e);
        }
    },

    connectToHost() {
        // Move stream to the in-session video element
        if (this.localStream && this.$refs.localVideo) {
            this.$refs.localVideo.srcObject = this.localStream;
            this.$refs.localVideo.play().catch(() => {});
        }

        // Join the Echo presence channel for WebRTC signaling
        if (!window.Echo) {
            console.warn('Echo not available - WebRTC signaling disabled');
            return;
        }

        // Use a guest-compatible ID
        const guestId = this.userId || ('guest-' + Date.now());

        this.echoChannel = window.Echo.join(`studio.${this.sessionId}`)
            .here((users) => {
                this.connected = true;
            })
            .joining((user) => {
                // Another user joined
            })
            .leaving((user) => {
                if (this.peerConnections[user.id]) {
                    this.peerConnections[user.id].close();
                    delete this.peerConnections[user.id];
                }
            })
            .listenForWhisper('webrtc-offer', async (data) => {
                if (data.to !== guestId) return;

                const pc = new RTCPeerConnection({
                    iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
                });
                this.peerConnections[data.from] = pc;

                // Add local tracks
                if (this.localStream) {
                    this.localStream.getTracks().forEach(track => {
                        pc.addTrack(track, this.localStream);
                    });
                }

                pc.onicecandidate = (event) => {
                    if (event.candidate) {
                        this.echoChannel.whisper('webrtc-ice', {
                            from: guestId,
                            to: data.from,
                            candidate: event.candidate,
                        });
                    }
                };

                await pc.setRemoteDescription(new RTCSessionDescription(data.sdp));
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);

                this.echoChannel.whisper('webrtc-answer', {
                    from: guestId,
                    to: data.from,
                    sdp: pc.localDescription,
                });
            })
            .listenForWhisper('webrtc-ice', async (data) => {
                if (data.to !== guestId) return;
                const pc = this.peerConnections[data.from];
                if (pc && data.candidate) {
                    await pc.addIceCandidate(new RTCIceCandidate(data.candidate));
                }
            });
    },

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

    leaveSession() {
        if (this.localStream) {
            this.localStream.getTracks().forEach(t => t.stop());
        }
        if (this.echoChannel) {
            window.Echo?.leave(`studio.${this.sessionId}`);
        }
        Object.values(this.peerConnections).forEach(pc => pc.close());
        window.location.href = '/';
    },
}));
</script>
@endscript
