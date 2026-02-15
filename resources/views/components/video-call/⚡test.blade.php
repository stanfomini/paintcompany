<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.video-call')] class extends Component {
    public string $roomId = '';

    public string $userName = '';

    public int $userId;

    public function mount(?string $room = null): void
    {
        $this->roomId = $room ?? 'test';

        $user = auth()->user()
            ?? auth('customer')->user()
            ?? auth('crew')->user()
            ?? auth('agent')->user();

        $this->userName = $user->name ?? 'Guest';
        $this->userId = $user->id ?? 0;
    }
};
?>

<div
    x-data="videoCallTest({
        roomId: @js($roomId),
        userId: @js($userId),
        userName: @js($userName),
    })"
    x-init="init()"
    class="flex flex-col h-screen"
>
    {{-- PRE-CALL LOBBY --}}
    <template x-if="screen === 'lobby'">
        <div class="flex-1 flex items-center justify-center bg-zinc-900">
            <div class="max-w-md w-full mx-4">
                <div class="text-center mb-8">
                    <h1 class="text-2xl font-bold text-white mb-2">{{ __('Video Call') }}</h1>
                    <p class="text-zinc-400 text-sm">Room: <span class="font-mono text-zinc-300" x-text="roomId"></span></p>
                    <div class="mt-3 flex items-center justify-center gap-2">
                        <div
                            class="w-2 h-2 rounded-full"
                            :class="echoConnected ? 'bg-green-500' : 'bg-yellow-500 animate-pulse'"
                        ></div>
                        <span class="text-xs text-zinc-400" x-text="echoConnected ? lobbyPeerCount + ' already in call' : 'Connecting...'"></span>
                    </div>
                </div>

                {{-- Video Preview --}}
                <div class="relative bg-zinc-800 rounded-xl overflow-hidden aspect-video mb-6">
                    <video
                        x-ref="previewVideo"
                        autoplay
                        muted
                        playsinline
                        class="w-full h-full object-cover"
                    ></video>
                    <div x-show="!previewStream" class="absolute inset-0 flex items-center justify-center">
                        <div class="w-16 h-16 rounded-full bg-zinc-700 flex items-center justify-center">
                            <span class="text-xl font-bold text-white" x-text="userName.charAt(0).toUpperCase()"></span>
                        </div>
                    </div>
                    <div class="absolute bottom-3 left-3 px-2 py-1 rounded bg-black/60 text-white text-xs" x-text="userName"></div>
                </div>

                {{-- Join Options --}}
                <div class="space-y-3">
                    <button
                        type="button"
                        @click="joinCall('presenter')"
                        :disabled="!echoConnected || (lobbyVideoCount >= maxVideoParticipants)"
                        class="w-full py-3 px-4 rounded-xl font-medium text-white transition-colors flex items-center justify-center gap-3 disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="lobbyVideoCount >= maxVideoParticipants ? 'bg-zinc-700' : 'bg-blue-600 hover:bg-blue-700'"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                        <span x-text="lobbyVideoCount >= maxVideoParticipants ? 'Camera slots full (' + maxVideoParticipants + '/' + maxVideoParticipants + ')' : 'Join with Camera'"></span>
                    </button>

                    <button
                        type="button"
                        @click="joinCall('viewer')"
                        :disabled="!echoConnected"
                        class="w-full py-3 px-4 rounded-xl font-medium text-white bg-zinc-700 hover:bg-zinc-600 transition-colors flex items-center justify-center gap-3 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                        {{ __('Join Audio Only') }}
                    </button>
                </div>

                <div class="mt-4 flex items-center justify-center">
                    <button
                        type="button"
                        @click="copyRoomLink()"
                        class="text-xs text-zinc-500 hover:text-zinc-300 flex items-center gap-1 transition-colors"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                        <span x-text="linkCopied ? 'Copied!' : 'Copy invite link'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- IN-CALL SCREEN --}}
    <template x-if="screen === 'call'">
        <div class="flex flex-col h-screen">
            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 bg-zinc-900 border-b border-zinc-700">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <div
                            class="w-3 h-3 rounded-full"
                            :class="connected ? 'bg-green-500 animate-pulse' : 'bg-red-500'"
                        ></div>
                        <span class="font-medium text-white text-sm sm:text-base">{{ __('Video Call') }}</span>
                    </div>
                    <span class="px-2 py-1 rounded-full text-xs font-medium bg-zinc-700 text-zinc-300" x-text="participantCount + ' in call'"></span>
                    <span class="px-2 py-1 rounded-full text-xs font-medium" :class="videoEnabled ? 'bg-blue-500/20 text-blue-300' : 'bg-zinc-600 text-zinc-300'" x-text="videoEnabled ? 'Camera On' : 'Audio Only'"></span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="p-2 rounded-lg bg-zinc-700 text-zinc-300 hover:bg-zinc-600 transition-colors"
                        @click="copyRoomLink()"
                        title="Copy room link"
                    >
                        <template x-if="!linkCopied">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                        </template>
                        <template x-if="linkCopied">
                            <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        </template>
                    </button>
                </div>
            </div>

            {{-- Status Bar --}}
            <div class="px-4 py-2 bg-zinc-800 text-xs text-zinc-400 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <span>Room: <span class="text-zinc-300 font-mono" x-text="roomId"></span></span>
                    <span>Echo: <span :class="echoConnected ? 'text-green-400' : 'text-red-400'" x-text="echoConnected ? 'Connected' : 'Waiting...'"></span></span>
                </div>
                <div x-show="error" class="text-red-400" x-text="error"></div>
            </div>

            {{-- Video Area — Grid mode or Focus/Spotlight mode --}}
            <div class="flex-1 bg-zinc-900 p-2 sm:p-4 overflow-hidden">

                {{-- GRID MODE --}}
                <template x-if="!focusedPeerId">
                    <div
                        class="grid gap-2 sm:gap-4 h-full"
                        :class="{
                            'grid-cols-1': tileCount <= 1,
                            'grid-cols-1 sm:grid-cols-2': tileCount === 2,
                            'grid-cols-2': tileCount >= 3,
                        }"
                    >
                        {{-- Local Video --}}
                        <div class="relative bg-zinc-800 rounded-xl overflow-hidden min-h-[200px] cursor-pointer" @click="toggleFocus('self')">
                            <video
                                x-ref="localVideo"
                                x-init="if (localStream) { $el.srcObject = localStream; $el.play().catch(() => {}) }"
                                autoplay
                                muted
                                playsinline
                                class="w-full h-full object-cover"
                                :class="{ 'hidden': !videoEnabled }"
                            ></video>

                            <div
                                x-show="!videoEnabled"
                                class="absolute inset-0 flex items-center justify-center bg-zinc-800"
                            >
                                <div class="w-20 h-20 rounded-full bg-zinc-600 flex items-center justify-center">
                                    <span class="text-2xl font-bold text-white" x-text="userName.charAt(0).toUpperCase()"></span>
                                </div>
                            </div>

                            <div class="absolute bottom-3 left-3 px-2 py-1 rounded bg-black/60 text-white text-sm font-medium">
                                You (<span x-text="userName"></span>)
                            </div>

                            <div class="absolute top-3 right-3 flex flex-col gap-0.5" x-show="audioEnabled">
                                <template x-for="i in 5" :key="i">
                                    <div
                                        class="w-1 rounded-full transition-all duration-100"
                                        :class="audioLevel >= (i * 20) ? 'bg-green-400 h-2' : 'bg-zinc-600 h-1'"
                                    ></div>
                                </template>
                            </div>
                        </div>

                        {{-- Remote Peers --}}
                        <template x-for="(peer, peerId) in peers" :key="peerId">
                            <div class="relative bg-zinc-800 rounded-xl overflow-hidden min-h-[200px] cursor-pointer" @click="toggleFocus(peerId)">
                                <video
                                    :id="'video-' + peerId"
                                    autoplay
                                    playsinline
                                    class="w-full h-full object-cover"
                                    :class="{ 'hidden': !peer.hasVideo }"
                                ></video>

                                <div
                                    x-show="!peer.hasVideo"
                                    class="absolute inset-0 flex items-center justify-center bg-zinc-800"
                                >
                                    <div class="w-20 h-20 rounded-full bg-zinc-600 flex items-center justify-center">
                                        <span class="text-2xl font-bold text-white" x-text="(peer.displayName || '?').charAt(0).toUpperCase()"></span>
                                    </div>
                                </div>

                                <div class="absolute bottom-3 left-3 px-2 py-1 rounded bg-black/60 text-white text-sm font-medium" x-text="peer.displayName || 'Connecting...'"></div>

                                <div
                                    x-show="peer.state !== 'connected'"
                                    class="absolute inset-0 flex items-center justify-center bg-zinc-800/80"
                                >
                                    <div class="text-center">
                                        <div class="animate-spin w-8 h-8 border-2 border-white border-t-transparent rounded-full mx-auto mb-2"></div>
                                        <span class="text-white text-sm">Connecting...</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- FOCUS / SPOTLIGHT MODE --}}
                <template x-if="focusedPeerId">
                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 h-full">
                        {{-- Main Stage --}}
                        <div class="flex-1 min-h-0 relative">
                            {{-- Focused: Self --}}
                            <template x-if="focusedPeerId === 'self'">
                                <div class="relative bg-zinc-800 rounded-xl overflow-hidden h-full cursor-pointer" @click="toggleFocus('self')">
                                    <video
                                        x-ref="localVideo"
                                        x-init="if (localStream) { $el.srcObject = localStream; $el.play().catch(() => {}) }"
                                        autoplay
                                        muted
                                        playsinline
                                        class="w-full h-full object-cover"
                                        :class="{ 'hidden': !videoEnabled }"
                                    ></video>

                                    <div
                                        x-show="!videoEnabled"
                                        class="absolute inset-0 flex items-center justify-center bg-zinc-800"
                                    >
                                        <div class="w-24 h-24 rounded-full bg-zinc-600 flex items-center justify-center">
                                            <span class="text-3xl font-bold text-white" x-text="userName.charAt(0).toUpperCase()"></span>
                                        </div>
                                    </div>

                                    <div class="absolute bottom-3 left-3 px-2 py-1 rounded bg-black/60 text-white text-sm font-medium">
                                        You (<span x-text="userName"></span>)
                                    </div>

                                    <div class="absolute top-3 right-3 flex flex-col gap-0.5" x-show="audioEnabled">
                                        <template x-for="i in 5" :key="i">
                                            <div
                                                class="w-1 rounded-full transition-all duration-100"
                                                :class="audioLevel >= (i * 20) ? 'bg-green-400 h-2' : 'bg-zinc-600 h-1'"
                                            ></div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Focused: Remote peer --}}
                            <template x-if="focusedPeerId !== 'self' && peers[focusedPeerId]">
                                <div class="relative bg-zinc-800 rounded-xl overflow-hidden h-full cursor-pointer" @click="toggleFocus(focusedPeerId)">
                                    <video
                                        :id="'video-focus-' + focusedPeerId"
                                        autoplay
                                        playsinline
                                        class="w-full h-full object-cover"
                                        :class="{ 'hidden': !peers[focusedPeerId]?.hasVideo }"
                                    ></video>

                                    <div
                                        x-show="!peers[focusedPeerId]?.hasVideo"
                                        class="absolute inset-0 flex items-center justify-center bg-zinc-800"
                                    >
                                        <div class="w-24 h-24 rounded-full bg-zinc-600 flex items-center justify-center">
                                            <span class="text-3xl font-bold text-white" x-text="(peers[focusedPeerId]?.displayName || '?').charAt(0).toUpperCase()"></span>
                                        </div>
                                    </div>

                                    <div class="absolute bottom-3 left-3 px-2 py-1 rounded bg-black/60 text-white text-sm font-medium" x-text="peers[focusedPeerId]?.displayName || 'Connecting...'"></div>

                                    <div
                                        x-show="peers[focusedPeerId]?.state !== 'connected'"
                                        class="absolute inset-0 flex items-center justify-center bg-zinc-800/80"
                                    >
                                        <div class="text-center">
                                            <div class="animate-spin w-8 h-8 border-2 border-white border-t-transparent rounded-full mx-auto mb-2"></div>
                                            <span class="text-white text-sm">Connecting...</span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- Grid View button --}}
                            <button
                                type="button"
                                @click.stop="focusedPeerId = null; $nextTick(() => attachLocalVideo())"
                                class="absolute top-3 left-3 px-3 py-1.5 rounded-lg bg-black/60 hover:bg-black/80 text-white text-xs font-medium flex items-center gap-1.5 transition-colors z-10"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                                {{ __('Grid View') }}
                            </button>
                        </div>

                        {{-- Sidebar Strip (remaining tiles) --}}
                        <div class="flex sm:flex-col gap-2 sm:w-48 sm:min-w-[12rem] overflow-x-auto sm:overflow-y-auto shrink-0 max-h-[30vh] sm:max-h-none">
                            {{-- Self tile in sidebar (when not focused) --}}
                            <template x-if="focusedPeerId !== 'self'">
                                <div class="relative bg-zinc-800 rounded-xl overflow-hidden min-w-[8rem] sm:min-w-0 aspect-video shrink-0 cursor-pointer" @click="toggleFocus('self')">
                                    <video
                                        x-ref="localVideo"
                                        x-init="if (localStream) { $el.srcObject = localStream; $el.play().catch(() => {}) }"
                                        autoplay
                                        muted
                                        playsinline
                                        class="w-full h-full object-cover"
                                        :class="{ 'hidden': !videoEnabled }"
                                    ></video>

                                    <div
                                        x-show="!videoEnabled"
                                        class="absolute inset-0 flex items-center justify-center bg-zinc-800"
                                    >
                                        <div class="w-10 h-10 rounded-full bg-zinc-600 flex items-center justify-center">
                                            <span class="text-sm font-bold text-white" x-text="userName.charAt(0).toUpperCase()"></span>
                                        </div>
                                    </div>

                                    <div class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 rounded bg-black/60 text-white text-xs font-medium">You</div>
                                </div>
                            </template>

                            {{-- Remote peers in sidebar (not the focused one) --}}
                            <template x-for="(peer, peerId) in peers" :key="'sidebar-' + peerId">
                                <div
                                    x-show="peerId !== focusedPeerId"
                                    class="relative bg-zinc-800 rounded-xl overflow-hidden min-w-[8rem] sm:min-w-0 aspect-video shrink-0 cursor-pointer"
                                    @click="toggleFocus(peerId)"
                                >
                                    <video
                                        :id="'video-' + peerId"
                                        autoplay
                                        playsinline
                                        class="w-full h-full object-cover"
                                        :class="{ 'hidden': !peer.hasVideo }"
                                    ></video>

                                    <div
                                        x-show="!peer.hasVideo"
                                        class="absolute inset-0 flex items-center justify-center bg-zinc-800"
                                    >
                                        <div class="w-10 h-10 rounded-full bg-zinc-600 flex items-center justify-center">
                                            <span class="text-sm font-bold text-white" x-text="(peer.displayName || '?').charAt(0).toUpperCase()"></span>
                                        </div>
                                    </div>

                                    <div class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 rounded bg-black/60 text-white text-xs font-medium" x-text="peer.displayName || '...'"></div>

                                    <div
                                        x-show="peer.state !== 'connected'"
                                        class="absolute inset-0 flex items-center justify-center bg-zinc-800/80"
                                    >
                                        <div class="animate-spin w-5 h-5 border-2 border-white border-t-transparent rounded-full"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

            </div>

            {{-- Controls --}}
            <div class="px-4 py-4 bg-zinc-900 border-t border-zinc-700">
                <div class="flex items-center justify-center gap-3 sm:gap-4">
                    {{-- Toggle Audio --}}
                    <button
                        type="button"
                        @click="toggleAudio()"
                        :class="audioEnabled ? 'bg-zinc-700 text-white hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                        class="w-12 h-12 sm:w-14 sm:h-14 rounded-full flex items-center justify-center transition-colors"
                        :title="audioEnabled ? 'Mute microphone' : 'Unmute microphone'"
                    >
                        <svg x-show="audioEnabled" class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                        <svg x-show="!audioEnabled" class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path></svg>
                    </button>

                    {{-- Toggle Video (available for everyone) --}}
                    <button
                        type="button"
                        @click="toggleCamera()"
                        :class="videoEnabled ? 'bg-zinc-700 text-white hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                        class="w-12 h-12 sm:w-14 sm:h-14 rounded-full flex items-center justify-center transition-colors relative"
                        :title="videoEnabled ? 'Turn off camera' : 'Turn on camera'"
                    >
                        <svg x-show="videoEnabled" class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                        <svg x-show="!videoEnabled" class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>
                    </button>

                    {{-- Flip Camera (mobile, when camera is active) --}}
                    <button
                        type="button"
                        @click="flipCamera()"
                        x-show="hasMultipleCameras && videoEnabled"
                        class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-zinc-700 text-white hover:bg-zinc-600 flex items-center justify-center transition-colors"
                        title="Flip camera"
                    >
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    </button>

                    {{-- Screen Share (when camera is active) --}}
                    <button
                        type="button"
                        x-show="videoEnabled && hasScreenShare"
                        @click="toggleScreenShare()"
                        :class="isScreenSharing ? 'bg-blue-500 text-white hover:bg-blue-600' : 'bg-zinc-700 text-white hover:bg-zinc-600'"
                        class="w-12 h-12 sm:w-14 sm:h-14 rounded-full flex items-center justify-center transition-colors"
                        :title="isScreenSharing ? 'Stop sharing screen' : 'Share screen'"
                    >
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </button>

                    {{-- End Call --}}
                    <button
                        type="button"
                        @click="leaveCall()"
                        class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-red-600 text-white hover:bg-red-700 flex items-center justify-center transition-colors"
                        title="Leave call"
                    >
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M5 3a2 2 0 00-2 2v1c0 8.284 6.716 15 15 15h1a2 2 0 002-2v-3.28a1 1 0 00-.684-.948l-4.493-1.498a1 1 0 00-1.21.502l-1.13 2.257a11.042 11.042 0 01-5.516-5.517l2.257-1.128a1 1 0 00.502-1.21L9.228 3.683A1 1 0 008.279 3H5z"></path></svg>
                    </button>
                </div>

                <div class="mt-3 text-center text-xs text-zinc-500">
                    <span x-text="cameraToast || cameraLabel || (!videoEnabled ? 'Audio only — tap camera to enable' : 'No camera detected')"></span>
                </div>
            </div>

            {{-- Debug Log --}}
            <div class="bg-zinc-950 border-t border-zinc-800">
                <button
                    type="button"
                    @click="showLog = !showLog"
                    class="w-full px-4 py-2 text-xs text-zinc-500 hover:text-zinc-300 flex items-center justify-between"
                >
                    <span>{{ __('Debug Log') }}</span>
                    <svg class="w-4 h-4 transition-transform" :class="showLog && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="showLog" x-collapse class="px-4 pb-3 max-h-40 overflow-y-auto">
                    <template x-for="(entry, i) in logEntries.slice(-20)" :key="i">
                        <div class="text-xs font-mono" :class="entry.type === 'error' ? 'text-red-400' : 'text-zinc-500'" x-text="entry.time + ' ' + entry.msg"></div>
                    </template>
                    <div x-show="logEntries.length === 0" class="text-xs text-zinc-600">{{ __('No log entries yet...') }}</div>
                </div>
            </div>
        </div>
    </template>
</div>

@script
<script>
Alpine.data('videoCallTest', (config) => ({
    // Config
    roomId: config.roomId,
    userId: String(config.userId),
    userName: config.userName,

    // Screen state
    screen: 'lobby', // 'lobby' or 'call'
    myRole: null, // 'presenter' or 'viewer'

    // State
    connected: false,
    echoConnected: false,
    audioEnabled: true,
    videoEnabled: true,
    error: null,
    showLog: false,
    linkCopied: false,
    audioLevel: 0,
    cameraLabel: '',
    hasMultipleCameras: false,
    useFrontCamera: true,
    participantCount: 1,
    cameraToast: '',
    focusedPeerId: null, // null = grid, 'self' = self focused, peerId string = that peer

    // Lobby state
    lobbyPeerCount: 0,
    lobbyVideoCount: 0,
    previewStream: null,

    // Participant limits
    maxVideoParticipants: 2,

    // WebRTC
    localStream: null,
    peers: {},
    peerConnections: {},
    channel: null,
    logEntries: [],

    // Track peer roles
    peerRoles: {}, // peerId -> 'presenter' | 'viewer'

    // Video quality settings
    maxVideoBitrate: 1500000,
    maxVideoFramerate: 30,

    // Feature detection
    hasScreenShare: typeof navigator.mediaDevices?.getDisplayMedia === 'function',

    // Screen sharing state
    isScreenSharing: false,
    screenStream: null,

    // ICE servers
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' },
    ],

    // Total visible tiles (self + all peers) — used for grid layout
    get tileCount() {
        return 1 + Object.keys(this.peers).length;
    },

    log(msg, type = 'info') {
        const time = new Date().toLocaleTimeString();
        this.logEntries.push({ time, msg, type });
        console[type === 'error' ? 'error' : 'log'](`[VideoCall] ${msg}`);
    },

    async init() {
        this.log('Initializing lobby...');

        if (!window.Echo) {
            this.error = 'Echo not available. Make sure you are logged in.';
            this.log('Echo not found on window', 'error');
            return;
        }

        // Check for multiple cameras
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const cameras = devices.filter(d => d.kind === 'videoinput');
            this.hasMultipleCameras = cameras.length > 1;
        } catch (e) {
            // Ignore
        }

        // Start camera preview in lobby
        await this.startPreview();

        // Join channel early to see who's in the room
        this.joinLobbyChannel();

        window.addEventListener('beforeunload', () => this.cleanup());

        this.log('Lobby ready');
    },

    async startPreview() {
        try {
            this.previewStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 1280, max: 1280 },
                    height: { ideal: 720, max: 720 },
                    frameRate: { ideal: 30, max: 30 },
                },
                audio: false,
            });
            this.$nextTick(async () => {
                if (this.$refs.previewVideo) {
                    this.$refs.previewVideo.srcObject = this.previewStream;
                    try { await this.$refs.previewVideo.play(); } catch (e) { /* non-critical */ }
                }
            });

            // Re-check cameras after permission granted (iOS needs this)
            const devices = await navigator.mediaDevices.enumerateDevices();
            const cameras = devices.filter(d => d.kind === 'videoinput');
            this.hasMultipleCameras = cameras.length > 1;
            this.log('Cameras found: ' + cameras.length);
        } catch (e) {
            this.log('Preview camera unavailable: ' + e.message);
        }
    },

    stopPreview() {
        if (this.previewStream) {
            this.previewStream.getTracks().forEach(t => t.stop());
            this.previewStream = null;
        }
    },

    joinLobbyChannel() {
        this.log('Joining lobby channel: video-call.' + this.roomId);

        this.channel = window.Echo.join('video-call.' + this.roomId)
            .here((users) => {
                this.echoConnected = true;
                this.lobbyPeerCount = users.filter(u => String(u.id) !== this.userId).length;
                this.log(`Lobby: ${users.length} user(s) in room`);
            })
            .joining((user) => {
                this.lobbyPeerCount++;
                this.log('Lobby: ' + user.name + ' peeked in');
            })
            .leaving((user) => {
                this.lobbyPeerCount = Math.max(0, this.lobbyPeerCount - 1);
                // Decrement lobbyVideoCount if the leaving user had camera on
                const leavingId = String(user.id);
                if (this.peerRoles[leavingId] === 'presenter') {
                    this.lobbyVideoCount = Math.max(0, this.lobbyVideoCount - 1);
                }
                if (this.screen === 'call') {
                    this.participantCount = Math.max(1, this.participantCount - 1);
                    this.removePeer(leavingId);
                }
                this.log('Left: ' + user.name);
            })
            .listenForWhisper('role-announce', (data) => {
                this.peerRoles[String(data.userId)] = data.role;
                if (data.role === 'presenter') {
                    this.lobbyVideoCount++;
                }
                this.log(`${data.userName} joined as ${data.role}`);
            })
            .listenForWhisper('role-changed', (data) => {
                const peerId = String(data.userId);
                const oldRole = this.peerRoles[peerId];
                this.peerRoles[peerId] = data.role;
                // Update lobbyVideoCount based on role transition
                if (oldRole !== 'presenter' && data.role === 'presenter') {
                    this.lobbyVideoCount++;
                } else if (oldRole === 'presenter' && data.role !== 'presenter') {
                    this.lobbyVideoCount = Math.max(0, this.lobbyVideoCount - 1);
                }
                this.log(`${data.userName} switched to ${data.role === 'presenter' ? 'camera on' : 'audio only'}`);
            })
            .listenForWhisper('offer', (data) => {
                if (String(data.targetId) !== String(this.userId)) return;
                if (this.screen !== 'call') return;
                this.log('Received offer from ' + data.userName);
                this.handleOffer(String(data.userId), data.userName, data.sdp);
            })
            .listenForWhisper('answer', (data) => {
                if (String(data.targetId) !== String(this.userId)) return;
                this.log('Received answer from user ' + data.userId);
                this.handleAnswer(String(data.userId), data.sdp);
            })
            .listenForWhisper('ice-candidate', (data) => {
                if (String(data.targetId) !== String(this.userId)) return;
                this.handleIceCandidate(String(data.userId), data.candidate);
            })
            .error((error) => {
                this.log('Channel error: ' + JSON.stringify(error), 'error');
                this.error = 'Connection error';
            });
    },

    async joinCall(role) {
        this.myRole = role;

        // Detach preview video BEFORE screen swap — releasing the track
        // from the old element so it can play on the new one
        if (this.$refs.previewVideo) {
            this.$refs.previewVideo.srcObject = null;
        }

        this.screen = 'call';
        this.connected = true;

        this.log(`Joining call as ${role}`);

        if (role === 'presenter' && this.previewStream) {
            // Reuse the live preview video track instead of stopping + re-acquiring
            // This avoids the camera re-init delay on iOS/iPadOS
            this.localStream = new MediaStream();
            this.previewStream.getVideoTracks().forEach(track => this.localStream.addTrack(track));
            this.previewStream = null; // Consumed, not stopped

            // Get audio separately
            try {
                const audioStream = await navigator.mediaDevices.getUserMedia({
                    audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                });
                audioStream.getAudioTracks().forEach(track => this.localStream.addTrack(track));
            } catch (e) {
                this.log('Microphone error: ' + e.message, 'error');
            }

            const videoTrack = this.localStream.getVideoTracks()[0];
            this.cameraLabel = videoTrack ? videoTrack.label || 'Camera active' : '';
            this.videoEnabled = true;

            const devices = await navigator.mediaDevices.enumerateDevices();
            const cameras = devices.filter(d => d.kind === 'videoinput');
            this.hasMultipleCameras = cameras.length > 1;

            // Wait for x-if template to render, then attach stream
            await this.attachLocalVideo();
            this.log('Local media started: reused preview + new audio');
        } else {
            this.stopPreview();
            await this.startLocalMedia(role === 'presenter');
        }

        // Monitor audio levels
        this.startAudioMonitor();

        // Announce role to all peers
        this.channel.whisper('role-announce', {
            userId: this.userId,
            userName: this.userName,
            role: this.myRole,
        });

        // Fetch current users from the presence channel and create offers
        // Use a short delay to ensure the channel state is updated
        await new Promise(resolve => setTimeout(resolve, 500));

        // Re-read the channel members and create offers to existing call participants
        // We need to re-trigger connection to peers who are already in the call
        this.channel.here && this.channel.subscription.members.each((member) => {
            if (String(member.id) !== this.userId) {
                this.log('Creating offer for peer: ' + member.info.name);
                this.createPeerConnection(String(member.id), member.info.name, true);
                this.participantCount++;
            }
        });

        this.participantCount = Math.max(1, this.participantCount);
    },

    async startLocalMedia(withVideo = true) {
        try {
            const constraints = {
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            };

            if (withVideo) {
                constraints.video = {
                    facingMode: this.useFrontCamera ? 'user' : 'environment',
                    width: { ideal: 1280, max: 1280 },
                    height: { ideal: 720, max: 720 },
                    frameRate: { ideal: 30, max: 30 },
                };
            }

            this.localStream = await navigator.mediaDevices.getUserMedia(constraints);

            if (withVideo) {
                await this.attachLocalVideo();
                const videoTrack = this.localStream.getVideoTracks()[0];
                this.cameraLabel = videoTrack ? videoTrack.label || 'Camera active' : '';
                this.videoEnabled = true;

                // Re-check cameras after permission granted (iOS needs this)
                const devices = await navigator.mediaDevices.enumerateDevices();
                const cameras = devices.filter(d => d.kind === 'videoinput');
                this.hasMultipleCameras = cameras.length > 1;
            } else {
                this.videoEnabled = false;
                this.cameraLabel = '';
            }

            this.log('Local media started: ' + (withVideo ? 'video + audio' : 'audio only'));
        } catch (e) {
            this.log('Media error: ' + e.message, 'error');
            if (withVideo) {
                this.log('Falling back to audio only');
                this.myRole = 'viewer';
                await this.startLocalMedia(false);
            } else {
                this.error = 'Could not access microphone';
            }
        }
    },

    /**
     * Robustly attach localStream to the local video element.
     * Polls for the ref (nested x-if templates may not render for several ticks)
     * and explicitly calls play() for Safari/WebKit on iOS/iPadOS.
     */
    async attachLocalVideo() {
        if (!this.localStream) return;

        let el = this.$refs.localVideo;

        // Nested x-if templates (screen + focusedPeerId) need multiple ticks
        if (!el) {
            for (let i = 0; i < 15; i++) {
                await this.$nextTick();
                el = this.$refs.localVideo;
                if (el) break;
            }
        }

        // Fallback — progressive waits for slower devices (older iPads)
        if (!el) {
            for (const delay of [50, 100, 200]) {
                await new Promise(r => setTimeout(r, delay));
                el = this.$refs.localVideo;
                if (el) break;
            }
        }

        if (el) {
            el.srcObject = this.localStream;
            try {
                await el.play();
                this.log('Local video attached and playing');
            } catch (e) {
                // Autoplay restriction — usually resolves on next user interaction
                this.log('Video play(): ' + e.message);
            }
        } else {
            this.log('Could not find localVideo ref after waiting', 'error');
        }
    },

    async createPeerConnection(peerId, displayName, createOffer = false) {
        if (this.peerConnections[peerId]) {
            this.peerConnections[peerId].close();
        }

        const pc = new RTCPeerConnection({ iceServers: this.iceServers });
        this.peerConnections[peerId] = pc;
        this.peers[peerId] = {
            displayName: displayName,
            state: 'connecting',
            hasVideo: false, // Updated when we receive a video track
        };

        // Default to viewer if we haven't heard their role yet
        if (!this.peerRoles[peerId]) {
            this.peerRoles[peerId] = 'viewer';
        }

        // Add local tracks
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                pc.addTrack(track, this.localStream);
            });
        }

        pc.onicecandidate = (event) => {
            if (event.candidate && this.channel) {
                this.channel.whisper('ice-candidate', {
                    userId: this.userId,
                    targetId: peerId,
                    candidate: event.candidate.toJSON(),
                });
            }
        };

        pc.onconnectionstatechange = () => {
            const state = pc.connectionState;
            this.log(`Peer ${displayName} connection: ${state}`);
            if (this.peers[peerId]) {
                this.peers[peerId].state = state;
            }
            if (state === 'failed' || state === 'closed') {
                this.removePeer(peerId);
            }
        };

        pc.oniceconnectionstatechange = () => {
            this.log(`Peer ${displayName} ICE: ${pc.iceConnectionState}`);
        };

        pc.ontrack = (event) => {
            this.log(`Received remote track (${event.track.kind}) from ${displayName}`);

            // Track video presence for avatar/video swap
            if (event.track.kind === 'video' && this.peers[peerId]) {
                this.peers[peerId].hasVideo = true;
                event.track.onended = () => {
                    if (this.peers[peerId]) this.peers[peerId].hasVideo = false;
                };
                event.track.onmute = () => {
                    if (this.peers[peerId]) this.peers[peerId].hasVideo = false;
                };
                event.track.onunmute = () => {
                    if (this.peers[peerId]) this.peers[peerId].hasVideo = true;
                };
            }

            // Attach stream to element (all peers use <video> now)
            this.$nextTick(async () => {
                const stream = event.streams[0];
                if (!stream) return;
                // Attach to grid/sidebar video element
                const el = document.getElementById(`video-${peerId}`);
                if (el) {
                    el.srcObject = stream;
                    try { await el.play(); } catch (e) { /* non-critical */ }
                }
                // Also attach to focus-mode video element if visible
                const focusEl = document.getElementById(`video-focus-${peerId}`);
                if (focusEl) {
                    focusEl.srcObject = stream;
                    try { await focusEl.play(); } catch (e) { /* non-critical */ }
                }
            });
        };

        if (createOffer) {
            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                await this.applyBandwidthLimits(pc);
                this.channel.whisper('offer', {
                    userId: this.userId,
                    userName: this.userName,
                    targetId: peerId,
                    sdp: offer.sdp,
                });
                this.log('Sent offer to ' + displayName);
            } catch (e) {
                this.log('Failed to create offer: ' + e.message, 'error');
            }
        }

        return pc;
    },

    async applyBandwidthLimits(pc) {
        try {
            const sender = pc.getSenders().find(s => s.track?.kind === 'video');
            if (sender) {
                const params = sender.getParameters();
                if (!params.encodings) {
                    params.encodings = [{}];
                }
                params.encodings[0].maxBitrate = this.maxVideoBitrate;
                params.encodings[0].maxFramerate = this.maxVideoFramerate;
                await sender.setParameters(params);
                this.log('Applied bandwidth limits: ' + this.maxVideoBitrate + 'bps');
            }
        } catch (e) {
            this.log('Bandwidth limit warning: ' + e.message);
        }
    },

    async handleOffer(peerId, displayName, sdp) {
        // Polite peer pattern — resolve offer glare when both sides send offers
        const existingPc = this.peerConnections[peerId];
        if (existingPc && existingPc.signalingState === 'have-local-offer') {
            if (Number(this.userId) < Number(peerId)) {
                // Lower userId is "impolite" — keeps our offer, ignores theirs
                this.log(`Offer glare with ${displayName}: ignoring (we have priority)`);
                return;
            }
            // Higher userId is "polite" — drops our offer, accepts theirs
            this.log(`Offer glare with ${displayName}: accepting their offer`);
        }

        try {
            const pc = await this.createPeerConnection(peerId, displayName, false);
            await pc.setRemoteDescription({ type: 'offer', sdp });
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            await this.applyBandwidthLimits(pc);

            this.channel.whisper('answer', {
                userId: this.userId,
                targetId: peerId,
                sdp: answer.sdp,
            });
            this.log('Sent answer to ' + displayName);
        } catch (e) {
            this.log('Failed to handle offer: ' + e.message, 'error');
        }
    },

    async handleAnswer(peerId, sdp) {
        const pc = this.peerConnections[peerId];
        if (pc) {
            try {
                await pc.setRemoteDescription({ type: 'answer', sdp });
                this.log('Set remote answer for peer ' + peerId);
            } catch (e) {
                this.log('Failed to set answer: ' + e.message, 'error');
            }
        }
    },

    async handleIceCandidate(peerId, candidate) {
        const pc = this.peerConnections[peerId];
        if (pc && candidate) {
            try {
                await pc.addIceCandidate(new RTCIceCandidate(candidate));
            } catch (e) {
                // ICE candidate errors are common and usually non-fatal
            }
        }
    },

    toggleFocus(id) {
        this.focusedPeerId = this.focusedPeerId === id ? null : id;

        // Re-attach local video after x-if template swap
        this.$nextTick(() => this.attachLocalVideo());

        // Re-attach focused peer's video stream
        if (this.focusedPeerId && this.focusedPeerId !== 'self') {
            this.$nextTick(() => {
                const peerId = this.focusedPeerId;
                const pc = this.peerConnections[peerId];
                if (!pc) return;
                const el = document.getElementById('video-focus-' + peerId);
                if (!el) return;
                // Find the remote stream from the peer connection receivers
                const receivers = pc.getReceivers();
                const videoReceiver = receivers.find(r => r.track?.kind === 'video');
                const audioReceiver = receivers.find(r => r.track?.kind === 'audio');
                const stream = new MediaStream();
                if (videoReceiver?.track) stream.addTrack(videoReceiver.track);
                if (audioReceiver?.track) stream.addTrack(audioReceiver.track);
                if (stream.getTracks().length > 0) {
                    el.srcObject = stream;
                    el.play().catch(() => {});
                }
            });
        }
    },

    removePeer(peerId) {
        if (this.focusedPeerId === peerId) {
            this.focusedPeerId = null;
        }
        const pc = this.peerConnections[peerId];
        if (pc) {
            pc.close();
            delete this.peerConnections[peerId];
        }
        delete this.peers[peerId];
        delete this.peerRoles[peerId];
    },

    toggleAudio() {
        this.audioEnabled = !this.audioEnabled;
        if (this.localStream) {
            this.localStream.getAudioTracks().forEach(track => {
                track.enabled = this.audioEnabled;
            });
        }
        this.log('Audio ' + (this.audioEnabled ? 'enabled' : 'muted'));
    },

    async toggleCamera() {
        if (this.videoEnabled) {
            // Turn camera OFF — stop video tracks and notify peers
            if (this.localStream) {
                this.localStream.getVideoTracks().forEach(track => {
                    track.stop();
                    this.localStream.removeTrack(track);
                });
            }
            // Remove video sender from peer connections
            for (const pc of Object.values(this.peerConnections)) {
                const sender = pc.getSenders().find(s => s.track?.kind === 'video');
                if (sender) {
                    try { pc.removeTrack(sender); } catch (e) { /* ignore */ }
                }
            }
            this.videoEnabled = false;
            this.myRole = 'viewer';
            this.cameraLabel = '';
            this.channel.whisper('role-changed', {
                userId: this.userId,
                userName: this.userName,
                role: 'viewer',
            });
            this.log('Camera turned off');
        } else {
            // Turn camera ON — check slots first
            let cameraCount = 0;
            for (const role of Object.values(this.peerRoles)) {
                if (role === 'presenter') cameraCount++;
            }
            if (cameraCount >= this.maxVideoParticipants) {
                this.showCameraToast('Camera slots full (' + this.maxVideoParticipants + '/' + this.maxVideoParticipants + ')');
                return;
            }

            try {
                const videoStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: this.useFrontCamera ? 'user' : 'environment',
                        width: { ideal: 1280, max: 1280 },
                        height: { ideal: 720, max: 720 },
                        frameRate: { ideal: 30, max: 30 },
                    },
                });
                const videoTrack = videoStream.getVideoTracks()[0];
                this.localStream.addTrack(videoTrack);
                this.cameraLabel = videoTrack.label || 'Camera active';

                // Add video track to all peer connections
                for (const pc of Object.values(this.peerConnections)) {
                    pc.addTrack(videoTrack, this.localStream);
                }

                // Show in local video element
                await this.attachLocalVideo();

                this.videoEnabled = true;
                this.myRole = 'presenter';
                this.channel.whisper('role-changed', {
                    userId: this.userId,
                    userName: this.userName,
                    role: 'presenter',
                });
                this.log('Camera turned on');
            } catch (e) {
                this.log('Failed to enable camera: ' + e.message, 'error');
                this.showCameraToast('Could not access camera');
            }
        }
    },

    showCameraToast(msg) {
        this.cameraToast = msg;
        setTimeout(() => { this.cameraToast = ''; }, 3000);
    },

    async flipCamera() {
        if (!this.videoEnabled) return;
        this.useFrontCamera = !this.useFrontCamera;
        this.log('Flipping to ' + (this.useFrontCamera ? 'front' : 'rear') + ' camera');

        if (this.localStream) {
            this.localStream.getVideoTracks().forEach(track => track.stop());
        }

        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: this.useFrontCamera ? 'user' : 'environment',
                    width: { ideal: 1280, max: 1280 },
                    height: { ideal: 720, max: 720 },
                    frameRate: { ideal: 30, max: 30 },
                },
            });

            const newVideoTrack = newStream.getVideoTracks()[0];
            this.cameraLabel = newVideoTrack.label || 'Camera';

            const oldVideoTrack = this.localStream.getVideoTracks()[0];
            if (oldVideoTrack) {
                this.localStream.removeTrack(oldVideoTrack);
            }
            this.localStream.addTrack(newVideoTrack);

            await this.attachLocalVideo();

            for (const [peerId, pc] of Object.entries(this.peerConnections)) {
                const sender = pc.getSenders().find(s => s.track?.kind === 'video');
                if (sender) {
                    await sender.replaceTrack(newVideoTrack);
                }
            }
        } catch (e) {
            this.log('Failed to flip camera: ' + e.message, 'error');
        }
    },

    async toggleScreenShare() {
        if (!this.videoEnabled || !this.hasScreenShare) return;

        if (this.isScreenSharing) {
            // Stop screen sharing - revert to camera
            if (this.screenStream) {
                this.screenStream.getTracks().forEach(t => t.stop());
                this.screenStream = null;
            }

            // Get camera track back
            const cameraStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: this.useFrontCamera ? 'user' : 'environment',
                    width: { ideal: 1280, max: 1280 },
                    height: { ideal: 720, max: 720 },
                    frameRate: { ideal: 30, max: 30 },
                },
            });
            const cameraTrack = cameraStream.getVideoTracks()[0];

            // Replace in local stream
            const oldTrack = this.localStream.getVideoTracks()[0];
            if (oldTrack) this.localStream.removeTrack(oldTrack);
            this.localStream.addTrack(cameraTrack);

            // Replace in peer connections
            for (const pc of Object.values(this.peerConnections)) {
                const sender = pc.getSenders().find(s => s.track?.kind === 'video');
                if (sender) await sender.replaceTrack(cameraTrack);
            }

            // Update local video
            await this.attachLocalVideo();

            this.isScreenSharing = false;
            this.cameraLabel = cameraTrack.label || 'Camera';
            this.log('Screen sharing stopped');
            return;
        }

        try {
            this.screenStream = await navigator.mediaDevices.getDisplayMedia({
                video: { width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate: { ideal: 15 } },
                audio: false,
            });

            const screenTrack = this.screenStream.getVideoTracks()[0];

            // When user stops sharing via browser UI
            screenTrack.onended = () => {
                this.toggleScreenShare(); // Revert to camera
            };

            // Replace camera track with screen track in local stream
            const oldTrack = this.localStream.getVideoTracks()[0];
            if (oldTrack) {
                oldTrack.stop();
                this.localStream.removeTrack(oldTrack);
            }
            this.localStream.addTrack(screenTrack);

            // Replace in all peer connections
            for (const pc of Object.values(this.peerConnections)) {
                const sender = pc.getSenders().find(s => s.track?.kind === 'video');
                if (sender) await sender.replaceTrack(screenTrack);
            }

            // Update local video to show screen share
            await this.attachLocalVideo();

            this.isScreenSharing = true;
            this.cameraLabel = 'Sharing screen';
            this.log('Screen sharing started');
        } catch (e) {
            this.log('Screen share error: ' + e.message, 'error');
        }
    },

    startAudioMonitor() {
        if (!this.localStream) return;

        try {
            const audioContext = new AudioContext();
            const source = audioContext.createMediaStreamSource(this.localStream);
            const analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            source.connect(analyser);

            const dataArray = new Uint8Array(analyser.frequencyBinCount);

            const updateLevel = () => {
                if (!this.localStream) return;
                analyser.getByteFrequencyData(dataArray);
                const avg = dataArray.reduce((a, b) => a + b, 0) / dataArray.length;
                this.audioLevel = Math.min(100, avg * 2);
                requestAnimationFrame(updateLevel);
            };
            updateLevel();
        } catch (e) {
            // Non-critical
        }
    },

    copyRoomLink() {
        const url = `${window.location.origin}/video-call-test/${this.roomId}`;
        navigator.clipboard.writeText(url).then(() => {
            this.linkCopied = true;
            this.log('Room link copied: ' + url);
            setTimeout(() => { this.linkCopied = false; }, 2000);
        });
    },

    leaveCall() {
        this.cleanup();
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '/';
        }
    },

    cleanup() {
        this.stopPreview();

        if (this.screenStream) {
            this.screenStream.getTracks().forEach(t => t.stop());
            this.screenStream = null;
        }
        this.isScreenSharing = false;

        if (this.channel) {
            window.Echo.leave('video-call.' + this.roomId);
            this.channel = null;
        }

        Object.values(this.peerConnections).forEach(pc => pc.close());
        this.peerConnections = {};
        this.peers = {};
        this.peerRoles = {};

        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
            this.localStream = null;
        }

        this.connected = false;
        this.echoConnected = false;
    },
}));
</script>
@endscript
