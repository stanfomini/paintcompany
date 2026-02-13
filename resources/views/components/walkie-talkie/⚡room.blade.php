<?php

use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $roomId = '';

    public string $userName = '';

    public bool $isConnected = false;

    public bool $isMuted = false;

    public bool $isTransmitting = false;

    public bool $transcriptionEnabled = true;

    /**
     * @var array<int, array{user_id: string, user_name: string, is_speaking: bool, is_muted: bool}>
     */
    public array $participants = [];

    /**
     * @var array<int, array{id: string, user_id: string, user_name: string, text: string, timestamp: string}>
     */
    public array $messages = [];

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

        return $wsUrl.'/media/ws/audio';
    }

    public function toggleMute(): void
    {
        $this->isMuted = ! $this->isMuted;
        $this->dispatch('walkie-talkie-mute-changed', muted: $this->isMuted);
    }

    public function toggleTranscription(): void
    {
        $this->transcriptionEnabled = ! $this->transcriptionEnabled;
    }

    public function addMessage(string $userId, string $userName, string $text): void
    {
        $this->messages[] = [
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'user_name' => $userName,
            'text' => $text,
            'timestamp' => now()->format('H:i:s'),
        ];

        // Keep only last 50 messages
        if (count($this->messages) > 50) {
            array_shift($this->messages);
        }
    }

    public function clearMessages(): void
    {
        $this->messages = [];
    }
}; ?>

<div
    x-data="walkieTalkie({
        roomId: @js($roomId),
        userId: @js(auth()->id() ?? 'guest-' . Str::random(8)),
        userName: @js($userName),
        wsUrl: @js($this->getWebSocketUrl()),
        transcriptionEnabled: @js($transcriptionEnabled),
    })"
    x-init="init()"
    class="flex flex-col h-full"
>
    {{-- Header --}}
    <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <div
                    class="w-3 h-3 rounded-full"
                    :class="connected ? 'bg-green-500' : 'bg-red-500'"
                ></div>
                <span class="font-medium text-zinc-900 dark:text-zinc-100">
                    {{ __('Room') }}: {{ Str::limit($roomId, 8) }}
                </span>
            </div>
            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300" x-text="participants.length + ' online'"></span>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                class="p-2 rounded-lg bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600"
                @click="copyRoomLink()"
                title="{{ __('Copy room link') }}"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
            </button>
            <button
                type="button"
                class="p-2 rounded-lg text-sm"
                :class="transcriptionEnabled ? 'bg-blue-500 text-white' : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300'"
                @click="transcriptionEnabled = !transcriptionEnabled; $wire.toggleTranscription()"
                title="{{ __('Toggle transcription') }}"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            </button>
        </div>
    </div>

    {{-- Main Content --}}
    <div class="flex flex-1 overflow-hidden">
        {{-- Participants Sidebar --}}
        <div class="w-48 border-r border-zinc-200 dark:border-zinc-700 overflow-y-auto">
            <div class="p-3">
                <h3 class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">
                    {{ __('Participants') }}
                </h3>
                <div class="space-y-2">
                    <template x-for="participant in participants" :key="participant.user_id">
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                            <div class="relative">
                                <div class="w-8 h-8 rounded-full bg-zinc-300 dark:bg-zinc-600 flex items-center justify-center">
                                    <span
                                        class="text-sm font-medium text-zinc-600 dark:text-zinc-300"
                                        x-text="participant.user_name.charAt(0).toUpperCase()"
                                    ></span>
                                </div>
                                <div
                                    x-show="participant.is_speaking"
                                    class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-green-500 rounded-full animate-pulse"
                                ></div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p
                                    class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate"
                                    x-text="participant.user_name"
                                ></p>
                                <p
                                    class="text-xs text-zinc-500"
                                    x-text="participant.is_muted ? 'Muted' : (participant.is_speaking ? 'Speaking...' : 'Online')"
                                ></p>
                            </div>
                            <svg
                                x-show="participant.is_muted"
                                class="w-4 h-4 text-zinc-400"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            ><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path></svg>
                        </div>
                    </template>
                    <div x-show="participants.length === 0" class="text-sm text-zinc-500 text-center py-4">
                        {{ __('No participants yet') }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Messages Area --}}
        <div class="flex-1 flex flex-col">
            <div class="flex-1 overflow-y-auto p-4 space-y-3" x-ref="messagesContainer">
                @foreach($messages as $message)
                    <div class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-zinc-300 dark:bg-zinc-600 flex items-center justify-center shrink-0">
                            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">
                                {{ substr($message['user_name'], 0, 1) }}
                            </span>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $message['user_name'] }}
                                </span>
                                <span class="text-xs text-zinc-500">
                                    {{ $message['timestamp'] }}
                                </span>
                            </div>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300 mt-0.5">
                                {{ $message['text'] }}
                            </p>
                        </div>
                    </div>
                @endforeach

                {{-- Live transcription messages from JS --}}
                <template x-for="msg in liveMessages" :key="msg.id">
                    <div class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-zinc-300 dark:bg-zinc-600 flex items-center justify-center shrink-0">
                            <span
                                class="text-sm font-medium text-zinc-600 dark:text-zinc-300"
                                x-text="msg.user_name.charAt(0).toUpperCase()"
                            ></span>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                    x-text="msg.user_name"
                                ></span>
                                <span class="text-xs text-zinc-500" x-text="msg.timestamp"></span>
                            </div>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300 mt-0.5" x-text="msg.text"></p>
                        </div>
                    </div>
                </template>

                <div x-show="messages.length === 0 && liveMessages.length === 0" class="text-center py-8 text-zinc-500">
                    <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                    <p>{{ __('Hold the talk button to transmit audio') }}</p>
                    <p class="text-xs mt-1">{{ __('Transcriptions will appear here when enabled') }}</p>
                </div>
            </div>

            {{-- Controls --}}
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-center gap-4">
                    {{-- Mute Button --}}
                    <button
                        type="button"
                        @click="toggleMute()"
                        :class="muted ? 'bg-red-500 text-white' : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300'"
                        class="rounded-full w-12 h-12 flex items-center justify-center transition-colors"
                    >
                        <svg x-show="!muted" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path></svg>
                        <svg x-show="muted" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path></svg>
                    </button>

                    {{-- Push-to-Talk Button --}}
                    <button
                        type="button"
                        @mousedown="startTransmission()"
                        @mouseup="endTransmission()"
                        @mouseleave="endTransmission()"
                        @touchstart.prevent="startTransmission()"
                        @touchend.prevent="endTransmission()"
                        :disabled="!connected || muted"
                        :class="{
                            'bg-red-500 scale-110': transmitting,
                            'bg-zinc-600 hover:bg-zinc-500': !transmitting && connected && !muted,
                            'bg-zinc-400 cursor-not-allowed': !connected || muted,
                        }"
                        class="w-20 h-20 rounded-full flex items-center justify-center text-white transition-all duration-150 focus:outline-none focus:ring-4 focus:ring-red-500/50"
                    >
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                    </button>

                    {{-- Settings Button --}}
                    <button
                        type="button"
                        @click="showSettings = !showSettings"
                        class="rounded-full w-12 h-12 flex items-center justify-center bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </button>
                </div>

                <div class="text-center mt-3">
                    <p
                        class="text-sm"
                        :class="transmitting ? 'text-red-500 font-medium' : 'text-zinc-500'"
                        x-text="transmitting ? 'Transmitting...' : (connected ? 'Hold to talk' : 'Connecting...')"
                    ></p>
                </div>
            </div>
        </div>
    </div>

    {{-- Audio Element for Playback --}}
    <audio x-ref="audioPlayer" class="hidden"></audio>

    {{-- Settings Modal --}}
    <div
        x-show="showSettings"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        @click.self="showSettings = false"
    >
        <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">{{ __('Audio Settings') }}</h3>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                        {{ __('Input Device') }}
                    </label>
                    <select
                        x-model="selectedInputDevice"
                        @change="changeInputDevice()"
                        class="w-full rounded-lg border-zinc-300 dark:border-zinc-600 dark:bg-zinc-700"
                    >
                        <template x-for="device in inputDevices" :key="device.deviceId">
                            <option :value="device.deviceId" x-text="device.label || 'Microphone'"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                        {{ __('Output Volume') }}
                    </label>
                    <input
                        type="range"
                        x-model="volume"
                        min="0"
                        max="1"
                        step="0.1"
                        class="w-full"
                    >
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    @click="showSettings = false"
                    class="px-4 py-2 rounded-lg bg-zinc-100 dark:bg-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600"
                >
                    {{ __('Close') }}
                </button>
            </div>
        </div>
    </div>
</div>

@script
<script>
Alpine.data('walkieTalkie', (config) => ({
    // Config
    roomId: config.roomId,
    userId: config.userId,
    userName: config.userName,
    wsUrl: config.wsUrl,
    transcriptionEnabled: config.transcriptionEnabled,

    // State
    connected: false,
    transmitting: false,
    muted: false,
    showSettings: false,
    volume: 1,

    // Participants & Messages
    participants: [],
    liveMessages: [],

    // Audio
    ws: null,
    mediaStream: null,
    mediaRecorder: null,
    audioChunks: [],
    audioContext: null,
    inputDevices: [],
    selectedInputDevice: '',

    async init() {
        // Get available audio devices
        await this.getAudioDevices();

        // Connect to WebSocket
        this.connect();

        // Listen for mute changes from Livewire
        Livewire.on('walkie-talkie-mute-changed', (data) => {
            this.muted = data.muted;
            if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                this.ws.send(JSON.stringify({ type: this.muted ? 'mute' : 'unmute' }));
            }
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => this.disconnect());
    },

    async getAudioDevices() {
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            this.inputDevices = devices.filter(d => d.kind === 'audioinput');
            if (this.inputDevices.length > 0) {
                this.selectedInputDevice = this.inputDevices[0].deviceId;
            }
        } catch (e) {
            console.error('Failed to get audio devices:', e);
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
            // Reconnect after 3 seconds
            setTimeout(() => this.connect(), 3000);
        };

        this.ws.onerror = (error) => {
            console.error('WebSocket error:', error);
        };

        this.ws.onmessage = async (event) => {
            if (event.data instanceof Blob) {
                // Audio data
                await this.playAudio(event.data);
            } else {
                // JSON message
                const msg = JSON.parse(event.data);
                this.handleMessage(msg);
            }
        };
    },

    disconnect() {
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
        if (this.mediaStream) {
            this.mediaStream.getTracks().forEach(track => track.stop());
            this.mediaStream = null;
        }
    },

    handleMessage(msg) {
        switch (msg.type) {
            case 'joined':
                this.participants = msg.participants || [];
                break;

            case 'participant_joined':
                if (!this.participants.find(p => p.user_id === msg.user_id)) {
                    this.participants.push({
                        user_id: msg.user_id,
                        user_name: msg.user_name,
                        is_speaking: false,
                        is_muted: false,
                    });
                }
                break;

            case 'participant_left':
                this.participants = this.participants.filter(p => p.user_id !== msg.user_id);
                break;

            case 'speaking_started':
                const speakingUser = this.participants.find(p => p.user_id === msg.user_id);
                if (speakingUser) {
                    speakingUser.is_speaking = true;
                }
                break;

            case 'speaking_ended':
                const endedUser = this.participants.find(p => p.user_id === msg.user_id);
                if (endedUser) {
                    endedUser.is_speaking = false;
                }
                break;

            case 'transcription':
                this.addLiveMessage(msg.user_id, msg.user_name || 'Unknown', msg.text);
                break;

            case 'muted':
            case 'unmuted':
                const mutedUser = this.participants.find(p => p.user_id === msg.user_id);
                if (mutedUser) {
                    mutedUser.is_muted = msg.type === 'muted';
                }
                break;

            case 'error':
                console.error('Server error:', msg.message);
                break;
        }
    },

    addLiveMessage(userId, userName, text) {
        const now = new Date();
        this.liveMessages.push({
            id: Date.now().toString(),
            user_id: userId,
            user_name: userName,
            text: text,
            timestamp: now.toLocaleTimeString('en-US', { hour12: false }),
        });

        // Keep only last 50 messages
        if (this.liveMessages.length > 50) {
            this.liveMessages.shift();
        }

        // Scroll to bottom
        this.$nextTick(() => {
            const container = this.$refs.messagesContainer;
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        });
    },

    async startTransmission() {
        if (!this.connected || this.muted || this.transmitting) return;

        this.transmitting = true;

        try {
            // Get microphone access
            this.mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    deviceId: this.selectedInputDevice ? { exact: this.selectedInputDevice } : undefined,
                    echoCancellation: true,
                    noiseSuppression: true,
                }
            });

            // Notify server we're starting
            this.ws.send(JSON.stringify({ type: 'start_transmission' }));

            // Create MediaRecorder for chunked audio
            this.mediaRecorder = new MediaRecorder(this.mediaStream, {
                mimeType: 'audio/webm;codecs=opus',
            });

            this.audioChunks = [];

            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                    // Send audio chunk immediately
                    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                        this.ws.send(event.data);
                    }
                }
            };

            // Start recording with 100ms chunks for low latency
            this.mediaRecorder.start(100);

        } catch (e) {
            console.error('Failed to start transmission:', e);
            this.transmitting = false;
        }
    },

    endTransmission() {
        if (!this.transmitting) return;

        this.transmitting = false;

        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }

        if (this.mediaStream) {
            this.mediaStream.getTracks().forEach(track => track.stop());
            this.mediaStream = null;
        }

        // Notify server we're done
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({ type: 'end_transmission' }));
        }
    },

    async playAudio(blob) {
        if (this.muted) return;

        try {
            const audioUrl = URL.createObjectURL(blob);
            const audio = this.$refs.audioPlayer;
            audio.src = audioUrl;
            audio.volume = this.volume;
            await audio.play();
            URL.revokeObjectURL(audioUrl);
        } catch (e) {
            console.error('Failed to play audio:', e);
        }
    },

    toggleMute() {
        this.muted = !this.muted;
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({ type: this.muted ? 'mute' : 'unmute' }));
        }
    },

    async changeInputDevice() {
        // Will take effect on next transmission
    },

    copyRoomLink() {
        const url = `${window.location.origin}/walkie-talkie/${this.roomId}`;
        navigator.clipboard.writeText(url);
        // Could add a toast notification here
    },
}));
</script>
@endscript
