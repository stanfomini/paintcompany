<?php

use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $roomId = '';

    public string $userName = '';

    public bool $isConnected = false;

    public bool $isAudioEnabled = true;

    public bool $isVideoEnabled = true;

    /**
     * @var array<int, array{peer_id: string, user_id: string, display_name: string}>
     */
    public array $participants = [];

    public function mount(?string $room = null): void
    {
        $this->roomId = $room ?? Str::uuid()->toString();
        $this->userName = auth()->user()?->name ?? 'Guest';
    }

    public function getWebSocketUrl(): string
    {
        $pythonUrl = config('python.service_url', 'http://127.0.0.1:8000');
        // Convert http to ws
        $wsUrl = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $pythonUrl);

        return $wsUrl.'/media/ws/video';
    }

    public function toggleAudio(): void
    {
        $this->isAudioEnabled = ! $this->isAudioEnabled;
        $this->dispatch('video-call-audio-changed', enabled: $this->isAudioEnabled);
    }

    public function toggleVideo(): void
    {
        $this->isVideoEnabled = ! $this->isVideoEnabled;
        $this->dispatch('video-call-video-changed', enabled: $this->isVideoEnabled);
    }
}; ?>

<div
    x-data="videoCall({
        roomId: @js($roomId),
        userId: @js(auth()->id() ?? 'guest-' . Str::random(8)),
        userName: @js($userName),
        wsUrl: @js($this->getWebSocketUrl()),
    })"
    x-init="init()"
    class="flex flex-col h-full bg-zinc-900"
>
    {{-- Header --}}
    <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-700">
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <div
                    class="w-3 h-3 rounded-full"
                    :class="connected ? 'bg-green-500' : 'bg-red-500'"
                ></div>
                <span class="font-medium text-white">
                    Video Call: {{ Str::limit($roomId, 8) }}
                </span>
            </div>
            <span class="px-2 py-1 rounded-full text-xs font-medium bg-zinc-700 text-zinc-300" x-text="Object.keys(peers).length + 1 + ' in call'"></span>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                class="p-2 rounded-lg bg-zinc-700 text-zinc-300 hover:bg-zinc-600"
                @click="copyRoomLink()"
                title="Copy room link"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
            </button>
            <button
                type="button"
                class="px-3 py-2 rounded-lg bg-red-500 text-white hover:bg-red-600 text-sm font-medium"
                @click="leaveCall()"
            >
                Leave Call
            </button>
        </div>
    </div>

    {{-- Video Grid --}}
    <div class="flex-1 p-4 overflow-hidden">
        <div
            class="grid gap-4 h-full"
            :class="{
                'grid-cols-1': Object.keys(peers).length === 0,
                'grid-cols-2': Object.keys(peers).length === 1,
                'grid-cols-2 grid-rows-2': Object.keys(peers).length >= 2 && Object.keys(peers).length <= 3,
                'grid-cols-3 grid-rows-2': Object.keys(peers).length >= 4,
            }"
        >
            {{-- Local Video --}}
            <div class="relative bg-zinc-800 rounded-xl overflow-hidden">
                <video
                    x-ref="localVideo"
                    autoplay
                    muted
                    playsinline
                    class="w-full h-full object-cover"
                ></video>
                <div class="absolute bottom-3 left-3 px-2 py-1 rounded bg-black/50 text-white text-sm">
                    You
                </div>
                <div
                    x-show="!videoEnabled"
                    class="absolute inset-0 flex items-center justify-center bg-zinc-800"
                >
                    <div class="w-20 h-20 rounded-full bg-zinc-600 flex items-center justify-center">
                        <span class="text-2xl font-bold text-white" x-text="userName.charAt(0).toUpperCase()"></span>
                    </div>
                </div>
            </div>

            {{-- Remote Videos --}}
            <template x-for="(peer, peerId) in peers" :key="peerId">
                <div class="relative bg-zinc-800 rounded-xl overflow-hidden">
                    <video
                        :id="'video-' + peerId"
                        autoplay
                        playsinline
                        class="w-full h-full object-cover"
                    ></video>
                    <div class="absolute bottom-3 left-3 px-2 py-1 rounded bg-black/50 text-white text-sm" x-text="peer.displayName || 'Participant'"></div>
                    <div
                        x-show="peer.connectionState !== 'connected'"
                        class="absolute inset-0 flex items-center justify-center bg-zinc-800/80"
                    >
                        <div class="text-center">
                            <div class="animate-spin w-8 h-8 border-2 border-white border-t-transparent rounded-full mx-auto mb-2"></div>
                            <span class="text-white text-sm" x-text="peer.connectionState === 'connecting' ? 'Connecting...' : 'Reconnecting...'"></span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Controls --}}
    <div class="px-4 py-4 border-t border-zinc-700">
        <div class="flex items-center justify-center gap-4">
            {{-- Mute Audio --}}
            <button
                type="button"
                @click="toggleAudio()"
                :class="audioEnabled ? 'bg-zinc-700 text-white' : 'bg-red-500 text-white'"
                class="w-14 h-14 rounded-full flex items-center justify-center transition-colors"
                title="Toggle microphone"
            >
                <svg x-show="audioEnabled" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                <svg x-show="!audioEnabled" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path></svg>
            </button>

            {{-- Toggle Video --}}
            <button
                type="button"
                @click="toggleVideo()"
                :class="videoEnabled ? 'bg-zinc-700 text-white' : 'bg-red-500 text-white'"
                class="w-14 h-14 rounded-full flex items-center justify-center transition-colors"
                title="Toggle camera"
            >
                <svg x-show="videoEnabled" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                <svg x-show="!videoEnabled" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>
            </button>

            {{-- End Call --}}
            <button
                type="button"
                @click="leaveCall()"
                class="w-14 h-14 rounded-full bg-red-500 text-white flex items-center justify-center hover:bg-red-600 transition-colors"
                title="End call"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M5 3a2 2 0 00-2 2v1c0 8.284 6.716 15 15 15h1a2 2 0 002-2v-3.28a1 1 0 00-.684-.948l-4.493-1.498a1 1 0 00-1.21.502l-1.13 2.257a11.042 11.042 0 01-5.516-5.517l2.257-1.128a1 1 0 00.502-1.21L9.228 3.683A1 1 0 008.279 3H5z"></path></svg>
            </button>
        </div>
    </div>
</div>

@script
<script>
Alpine.data('videoCall', (config) => ({
    // Config
    roomId: config.roomId,
    userId: config.userId,
    userName: config.userName,
    wsUrl: config.wsUrl,

    // State
    connected: false,
    peerId: null,
    audioEnabled: true,
    videoEnabled: true,

    // WebRTC
    ws: null,
    localStream: null,
    peers: {}, // { peerId: { connection, displayName, connectionState } }
    peerConnections: {}, // RTCPeerConnection instances

    // ICE servers (use public STUN servers)
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
    ],

    async init() {
        // Get local media first
        await this.startLocalMedia();

        // Connect to signaling server
        this.connect();

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => this.cleanup());
    },

    async startLocalMedia() {
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                video: true,
                audio: true,
            });

            this.$refs.localVideo.srcObject = this.localStream;
        } catch (e) {
            console.error('Failed to get local media:', e);
            // Try audio only
            try {
                this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                this.videoEnabled = false;
            } catch (e2) {
                console.error('Failed to get any media:', e2);
            }
        }
    },

    connect() {
        const url = `${this.wsUrl}?room_id=${this.roomId}&user_id=${this.userId}&user_name=${encodeURIComponent(this.userName)}`;

        this.ws = new WebSocket(url);

        this.ws.onopen = () => {
            console.log('WebSocket connected');
            this.connected = true;
        };

        this.ws.onclose = () => {
            console.log('WebSocket disconnected');
            this.connected = false;
            // Reconnect after delay
            setTimeout(() => {
                if (!this.connected) this.connect();
            }, 3000);
        };

        this.ws.onerror = (error) => {
            console.error('WebSocket error:', error);
        };

        this.ws.onmessage = async (event) => {
            const msg = JSON.parse(event.data);
            await this.handleSignalingMessage(msg);
        };
    },

    async handleSignalingMessage(msg) {
        console.log('Signaling message:', msg.type, msg);

        switch (msg.type) {
            case 'room_joined':
                this.peerId = msg.peer_id;
                // Create offers to all existing peers
                for (const peer of msg.existing_peers || []) {
                    await this.createPeerConnection(peer.peer_id, peer.display_name, true);
                }
                break;

            case 'peer_joined':
                // Wait for offer from new peer (they will initiate)
                this.peers[msg.peer_id] = {
                    displayName: msg.display_name,
                    connectionState: 'new',
                };
                break;

            case 'peer_left':
                this.removePeer(msg.peer_id);
                break;

            case 'offer':
                await this.handleOffer(msg.from_peer_id, msg.from_display_name, msg.sdp);
                break;

            case 'answer':
                await this.handleAnswer(msg.from_peer_id, msg.sdp);
                break;

            case 'ice_candidate':
                await this.handleIceCandidate(msg.from_peer_id, msg.candidate);
                break;

            case 'error':
                console.error('Server error:', msg.message);
                break;
        }
    },

    async createPeerConnection(peerId, displayName, createOffer = false) {
        const pc = new RTCPeerConnection({ iceServers: this.iceServers });

        this.peerConnections[peerId] = pc;
        this.peers[peerId] = {
            displayName: displayName,
            connectionState: 'connecting',
        };

        // Add local tracks
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                pc.addTrack(track, this.localStream);
            });
        }

        // Handle ICE candidates
        pc.onicecandidate = (event) => {
            if (event.candidate) {
                this.ws.send(JSON.stringify({
                    type: 'ice-candidate',
                    target_peer_id: peerId,
                    candidate: event.candidate,
                }));
            }
        };

        // Handle connection state changes
        pc.onconnectionstatechange = () => {
            if (this.peers[peerId]) {
                this.peers[peerId].connectionState = pc.connectionState;
            }
            if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
                this.removePeer(peerId);
            }
        };

        // Handle remote tracks
        pc.ontrack = (event) => {
            const videoEl = document.getElementById(`video-${peerId}`);
            if (videoEl && event.streams[0]) {
                videoEl.srcObject = event.streams[0];
            }
        };

        // Create and send offer if we're initiating
        if (createOffer) {
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            this.ws.send(JSON.stringify({
                type: 'offer',
                target_peer_id: peerId,
                sdp: offer.sdp,
            }));
        }

        return pc;
    },

    async handleOffer(peerId, displayName, sdp) {
        const pc = await this.createPeerConnection(peerId, displayName, false);

        await pc.setRemoteDescription({ type: 'offer', sdp });
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);

        this.ws.send(JSON.stringify({
            type: 'answer',
            target_peer_id: peerId,
            sdp: answer.sdp,
        }));
    },

    async handleAnswer(peerId, sdp) {
        const pc = this.peerConnections[peerId];
        if (pc) {
            await pc.setRemoteDescription({ type: 'answer', sdp });
        }
    },

    async handleIceCandidate(peerId, candidate) {
        const pc = this.peerConnections[peerId];
        if (pc && candidate) {
            await pc.addIceCandidate(new RTCIceCandidate(candidate));
        }
    },

    removePeer(peerId) {
        const pc = this.peerConnections[peerId];
        if (pc) {
            pc.close();
            delete this.peerConnections[peerId];
        }
        delete this.peers[peerId];
    },

    toggleAudio() {
        this.audioEnabled = !this.audioEnabled;
        if (this.localStream) {
            this.localStream.getAudioTracks().forEach(track => {
                track.enabled = this.audioEnabled;
            });
        }
    },

    toggleVideo() {
        this.videoEnabled = !this.videoEnabled;
        if (this.localStream) {
            this.localStream.getVideoTracks().forEach(track => {
                track.enabled = this.videoEnabled;
            });
        }
    },

    leaveCall() {
        this.cleanup();
        window.location.href = '/dashboard';
    },

    cleanup() {
        // Close all peer connections
        Object.values(this.peerConnections).forEach(pc => pc.close());
        this.peerConnections = {};
        this.peers = {};

        // Stop local media
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
            this.localStream = null;
        }

        // Close WebSocket
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
    },

    copyRoomLink() {
        const url = `${window.location.origin}/video-call/${this.roomId}`;
        navigator.clipboard.writeText(url);
    },
}));
</script>
@endscript
