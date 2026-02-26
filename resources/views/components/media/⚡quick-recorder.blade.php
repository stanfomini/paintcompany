<?php

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $lastRecordingId = null;
    public ?string $lastShareToken = null;
    public bool $showPostActions = false;

    public function addToChannel(int $channelId): void
    {
        abort_unless(auth()->user()?->can('media.manage_channels'), 403);

        if (! $this->lastRecordingId) {
            return;
        }

        $channel = VideoChannel::find($channelId);

        if (! $channel) {
            return;
        }

        $exists = VideoChannelItem::where('video_channel_id', $channelId)
            ->where('video_recording_id', $this->lastRecordingId)
            ->exists();

        if (! $exists) {
            $maxSort = VideoChannelItem::where('video_channel_id', $channelId)->max('sort_order') ?? 0;

            VideoChannelItem::create([
                'video_channel_id' => $channelId,
                'video_recording_id' => $this->lastRecordingId,
                'sort_order' => $maxSort + 1,
                'published_at' => now(),
            ]);
        }

        $this->dispatch('notify', message: __('Added to channel'));
    }

    public function dismissPostActions(): void
    {
        $this->showPostActions = false;
        $this->lastRecordingId = null;
        $this->lastShareToken = null;
        $this->dispatch('restore-body-scroll');
    }

    #[Computed]
    public function channels()
    {
        return VideoChannel::query()
            ->where('user_id', auth()->id())
            ->orWhere('visibility', 'public')
            ->orderBy('name')
            ->get(['id', 'name']);
    }
};
?>

<div
    x-data="quickRecorder({ csrfToken: @js(csrf_token()) })"
    x-on:open-quick-recorder.window="startCamera()"
    x-on:restore-body-scroll.window="
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
    "
>

    {{-- Fullscreen Recorder Overlay (teleported to body, same as walkaround) --}}
    <template x-teleport="body">
        <div
            x-show="stage !== 'idle'"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-effect="
                if (stage !== 'idle') {
                    document.body.style.overflow = 'hidden';
                    document.body.style.position = 'fixed';
                    document.body.style.width = '100%';
                } else {
                    document.body.style.overflow = '';
                    document.body.style.position = '';
                    document.body.style.width = '';
                }
            "
            class="fixed inset-0 z-[9999] bg-black flex flex-col"
            style="touch-action: none;"
            @touchmove.prevent
        >
            {{-- Video fills the screen --}}
            <div class="relative flex-1 overflow-hidden">
                <video
                    x-ref="videoPreview"
                    autoplay
                    muted
                    playsinline
                    x-show="stage === 'previewing' || stage === 'recording'"
                    class="absolute inset-0 w-full h-full object-cover"
                ></video>

                <video
                    x-ref="videoReview"
                    playsinline
                    controls
                    x-show="stage === 'review'"
                    class="absolute inset-0 w-full h-full object-cover"
                ></video>

                {{-- Top bar: status indicators --}}
                <div class="absolute top-0 left-0 right-0 pt-[env(safe-area-inset-top,12px)] px-4 pb-2 bg-gradient-to-b from-black/60 to-transparent z-10">
                    <div class="flex items-center justify-between h-12">
                        {{-- Close button (previewing only) --}}
                        <button
                            x-show="stage === 'previewing'"
                            type="button"
                            @click="cancelRecorder()"
                            class="w-10 h-10 flex items-center justify-center rounded-full bg-black/40 backdrop-blur-sm text-white active:bg-black/60"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                        <div x-show="stage !== 'previewing'" class="w-10"></div>

                        {{-- Recording time badge --}}
                        <div x-show="stage === 'recording'" class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-red-600/90 backdrop-blur-sm text-white text-sm font-semibold">
                            <div class="w-2.5 h-2.5 rounded-full bg-white animate-pulse"></div>
                            <span x-text="recordingTime"></span>
                        </div>
                        <div x-show="stage !== 'recording'" class="h-8"></div>

                        {{-- Flip camera button --}}
                        <button
                            x-show="stage === 'previewing' || stage === 'recording'"
                            type="button"
                            @click="flipCamera()"
                            class="w-10 h-10 flex items-center justify-center rounded-full bg-black/40 backdrop-blur-sm text-white active:bg-black/60"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        </button>
                        <div x-show="stage !== 'previewing' && stage !== 'recording'" class="w-10"></div>
                    </div>
                </div>

                {{-- Upload / Finalizing / Processing overlays --}}
                <div x-show="stage === 'uploading'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                    <div class="text-center">
                        <div class="w-20 h-20 mx-auto mb-4 relative">
                            <svg class="w-20 h-20 transform -rotate-90" viewBox="0 0 80 80">
                                <circle cx="40" cy="40" r="34" fill="none" stroke="#3f3f46" stroke-width="5" />
                                <circle cx="40" cy="40" r="34" fill="none" stroke="#ef4444" stroke-width="5" stroke-linecap="round"
                                    :stroke-dasharray="'213.63'"
                                    :stroke-dashoffset="213.63 - (213.63 * uploadProgress / 100)"
                                />
                            </svg>
                            <span class="absolute inset-0 flex items-center justify-center text-white text-lg font-bold" x-text="Math.round(uploadProgress) + '%'"></span>
                        </div>
                        <p class="text-white text-base font-medium">{{ __('Uploading...') }}</p>
                    </div>
                </div>

                <div x-show="stage === 'finalizing'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                    <div class="text-center">
                        <div class="animate-spin w-14 h-14 border-4 border-white border-t-transparent rounded-full mx-auto mb-4"></div>
                        <p class="text-white text-base font-medium" x-text="uploadQueue.length > 0 ? '{{ __('Sending final chunks...') }}' : '{{ __('Finalizing...') }}'"></p>
                    </div>
                </div>

                <div x-show="stage === 'processing'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                    <div class="text-center">
                        <div class="animate-spin w-14 h-14 border-4 border-white border-t-transparent rounded-full mx-auto mb-4"></div>
                        <p class="text-white text-base font-medium">{{ __('Processing video...') }}</p>
                        <p class="text-zinc-400 text-sm mt-1">{{ __('This may take a moment') }}</p>
                    </div>
                </div>
            </div>

            {{-- Bottom controls bar --}}
            <div class="shrink-0 bg-black pb-[env(safe-area-inset-bottom,16px)]">
                {{-- Previewing: big record button --}}
                <div x-show="stage === 'previewing'" class="flex items-center justify-center py-6">
                    <button
                        type="button"
                        @click="startRecording()"
                        class="w-20 h-20 rounded-full border-[5px] border-white flex items-center justify-center active:scale-95 transition-transform"
                    >
                        <div class="w-14 h-14 rounded-full bg-red-600"></div>
                    </button>
                </div>

                {{-- Recording: stop button --}}
                <div x-show="stage === 'recording'" class="flex items-center justify-center py-6">
                    <button
                        type="button"
                        @click="stopRecording()"
                        class="w-20 h-20 rounded-full border-[5px] border-white flex items-center justify-center active:scale-95 transition-transform"
                    >
                        <div class="w-8 h-8 rounded-sm bg-red-600"></div>
                    </button>
                </div>

                {{-- Review: retake / title / save --}}
                <div x-show="stage === 'review'" class="px-4 py-4 space-y-3">
                    <input
                        type="text"
                        x-model="videoTitle"
                        placeholder="{{ __('Add a title (optional)') }}"
                        class="w-full px-4 py-3 text-base rounded-xl bg-zinc-900 border border-zinc-700 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-red-500"
                    />
                    <div class="flex items-center gap-3">
                        <button type="button" @click="retake()" class="flex-1 py-3 rounded-xl bg-zinc-800 text-white font-semibold text-base active:bg-zinc-700 transition-colors">
                            {{ __('Retake') }}
                        </button>
                        <button type="button" @click="saveRecording()" class="flex-[2] py-3 rounded-xl bg-red-600 text-white font-semibold text-base active:bg-red-700 transition-colors flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                            {{ __('Save') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Post-save actions panel (shown after successful save) --}}
    @if($showPostActions && $lastShareToken)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50" wire:click.self="dismissPostActions">
            <div class="w-full max-w-sm bg-white dark:bg-zinc-800 rounded-t-xl sm:rounded-xl shadow-2xl overflow-hidden" @click.stop>
                <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Video Saved') }}</h3>
                    <button wire:click="dismissPostActions" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </div>
                <div class="p-4 space-y-2">
                    {{-- Copy link --}}
                    @php $shareUrl = route('video-recording.public', $lastShareToken); @endphp
                    <button
                        x-data="{ copied: false, shareUrl: @js($shareUrl) }"
                        @click="
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(shareUrl).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => { window.prompt('Copy this link:', shareUrl); });
                            } else {
                                window.prompt('Copy this link:', shareUrl);
                            }
                        "
                        class="w-full flex items-center gap-3 rounded-lg border border-zinc-200 dark:border-zinc-600 px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors"
                    >
                        <flux:icon name="link" class="size-5 text-zinc-500" />
                        <span class="text-zinc-700 dark:text-zinc-300" x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy Share Link') }}'"></span>
                    </button>
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 px-1">{{ $shareUrl }}</p>

                    {{-- Add to channel (only for users who can manage channels) --}}
                    @can('media.manage_channels')
                        @if($this->channels->isNotEmpty())
                            <div class="border border-zinc-200 dark:border-zinc-600 rounded-lg">
                                <p class="px-4 py-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Add to Channel') }}</p>
                                @foreach($this->channels as $channel)
                                    <button
                                        wire:click="addToChannel({{ $channel->id }})"
                                        class="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors border-t border-zinc-100 dark:border-zinc-700"
                                    >
                                        <flux:icon name="plus" class="size-4 text-zinc-400" />
                                        <span class="text-zinc-700 dark:text-zinc-300">{{ $channel->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    @endcan

                    {{-- Done --}}
                    <button
                        wire:click="dismissPostActions"
                        class="w-full flex items-center justify-center gap-2 rounded-lg bg-zinc-100 dark:bg-zinc-700 px-4 py-3 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600 transition-colors"
                    >
                        {{ __('Done') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

@script
<script>
Alpine.data('quickRecorder', (config) => ({
    stage: 'idle', // idle, previewing, recording, review, finalizing, uploading, processing
    stream: null,
    mediaRecorder: null,
    recordedChunks: [],
    recordedBlob: null,
    videoTitle: '',
    uploadProgress: 0,
    recordingSeconds: 0,
    recordingTimer: null,
    useFrontCamera: true,
    maxDuration: 120,

    // Raw stream reference (not wrapped by Alpine's Proxy, safe for MediaRecorder)
    _rawStream: null,

    // Chunked streaming properties
    recordingId: null,
    chunkIndex: 0,
    uploadQueue: [],
    isUploading: false,
    totalUploaded: 0,
    chunkRetries: {},
    maxRetries: 3,
    useChunkedUpload: true,

    init() {
        this._beforeUnloadHandler = (e) => {
            if (this.useChunkedUpload && this.recordingId && (this.stage === 'review' || this.stage === 'recording')) {
                navigator.sendBeacon(
                    `/api/video-recordings/${this.recordingId}/finalize`,
                    new Blob([JSON.stringify({ _token: config.csrfToken })], { type: 'application/json' })
                );
            }
        };
        window.addEventListener('beforeunload', this._beforeUnloadHandler);
    },

    destroy() {
        window.removeEventListener('beforeunload', this._beforeUnloadHandler);
        this.cleanupStream();
    },

    get recordingTime() {
        const m = Math.floor(this.recordingSeconds / 60);
        const s = this.recordingSeconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    },

    async enterFullscreen() {
        try {
            const el = document.documentElement;
            if (el.requestFullscreen) {
                await el.requestFullscreen();
            } else if (el.webkitRequestFullscreen) {
                await el.webkitRequestFullscreen();
            }
        } catch (_) {}

        try {
            if (screen.orientation && screen.orientation.lock) {
                await screen.orientation.lock('portrait');
            }
        } catch (_) {}
    },

    async exitFullscreen() {
        try {
            if (screen.orientation && screen.orientation.unlock) {
                screen.orientation.unlock();
            }
        } catch (_) {}
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else if (document.webkitFullscreenElement) {
                await document.webkitExitFullscreen();
            }
        } catch (_) {}
    },

    async startCamera(options = {}) {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: this.useFrontCamera ? 'user' : 'environment',
                    width: { ideal: 1280, max: 1280 },
                    height: { ideal: 720, max: 720 },
                    frameRate: { ideal: 30, max: 30 },
                },
                audio: true,
            });

            this._rawStream = stream;
            this.stream = stream;
            this.stage = 'previewing';

            if (!options.skipFullscreen) {
                await this.enterFullscreen();
            }

            this.$nextTick(() => {
                if (this.$refs.videoPreview) {
                    this.$refs.videoPreview.srcObject = this._rawStream;
                }
            });
        } catch (e) {
            console.error('Camera access failed:', e);
            alert('Could not access camera. Please check permissions.');
        }
    },

    async flipCamera() {
        this.useFrontCamera = !this.useFrontCamera;
        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.stream = null;
        }
        await this.startCamera({ skipFullscreen: true });
    },

    async startRecording() {
        if (!this._rawStream) return;

        this.recordedChunks = [];
        this.recordingSeconds = 0;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.isUploading = false;
        this.totalUploaded = 0;
        this.chunkRetries = {};
        this.recordingId = null;
        this.useChunkedUpload = true;

        const mimeType = this.getSupportedMimeType();
        if (!mimeType) {
            alert('Your browser does not support video recording.');
            return;
        }

        // Start chunked upload session on the server
        try {
            const response = await fetch('/api/video-recordings/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ mime_type: mimeType }),
            });

            if (!response.ok) throw new Error('Failed to start upload session');

            const data = await response.json();
            this.recordingId = data.id;
        } catch (e) {
            console.warn('Chunked upload init failed, will use legacy upload:', e);
            this.useChunkedUpload = false;
        }

        // Use raw stream to avoid Alpine Proxy issues with MediaRecorder
        this.mediaRecorder = new MediaRecorder(this._rawStream, {
            mimeType,
            videoBitsPerSecond: 2500000,
        });

        this.mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) {
                this.recordedChunks.push(e.data);

                // Stream chunk to server during recording
                if (this.useChunkedUpload && this.recordingId) {
                    this.uploadQueue.push(e.data);
                    this.processUploadQueue();
                }
            }
        };

        this.mediaRecorder.onstop = () => {
            this.recordedBlob = new Blob(this.recordedChunks, { type: mimeType });
            this.stage = 'review';

            this.$nextTick(() => {
                if (this.$refs.videoReview) {
                    this.$refs.videoReview.src = URL.createObjectURL(this.recordedBlob);
                }
            });
        };

        this.mediaRecorder.start(1000);
        this.stage = 'recording';

        this.recordingTimer = setInterval(() => {
            this.recordingSeconds++;
            if (this.recordingSeconds >= this.maxDuration) {
                this.stopRecording();
            }
        }, 1000);
    },

    async processUploadQueue() {
        if (this.isUploading || this.uploadQueue.length === 0) return;

        this.isUploading = true;

        while (this.uploadQueue.length > 0) {
            const chunk = this.uploadQueue.shift();
            const currentIndex = this.chunkIndex;
            const retryKey = `chunk_${currentIndex}`;

            try {
                const formData = new FormData();
                formData.append('chunk', chunk, `chunk_${currentIndex}.webm`);
                formData.append('chunk_index', currentIndex);

                const response = await fetch(`/api/video-recordings/${this.recordingId}/chunk`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                if (!response.ok) throw new Error('Chunk upload failed');

                this.chunkIndex++;
                this.totalUploaded += chunk.size;
                this.chunkRetries[retryKey] = 0;
            } catch (e) {
                const retries = (this.chunkRetries[retryKey] || 0) + 1;
                this.chunkRetries[retryKey] = retries;

                if (retries <= this.maxRetries) {
                    this.uploadQueue.unshift(chunk);
                    await new Promise(r => setTimeout(r, 1000 * retries));
                } else {
                    console.error(`Chunk ${currentIndex} failed after ${this.maxRetries} retries`);
                }
            }
        }

        this.isUploading = false;
    },

    stopRecording() {
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
            this.recordingTimer = null;
        }

        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }

        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.stream = null;
        }
    },

    retake() {
        if (this.recordingId) {
            fetch(`/api/video-recordings/${this.recordingId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': config.csrfToken,
                    'Accept': 'application/json',
                },
            }).catch(() => {});
        }

        this.recordedBlob = null;
        this.recordedChunks = [];
        this.videoTitle = '';
        this.recordingId = null;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.totalUploaded = 0;

        this.startCamera({ skipFullscreen: true });
    },

    async saveRecording() {
        if (this.useChunkedUpload && this.recordingId) {
            await this.finalizeChunkedUpload();
        } else {
            await this.uploadRecordingLegacy();
        }
    },

    async finalizeChunkedUpload() {
        this.stage = 'finalizing';

        while (this.uploadQueue.length > 0 || this.isUploading) {
            await new Promise(r => setTimeout(r, 200));
        }

        try {
            const promises = [];

            if (this.videoTitle.trim()) {
                promises.push(
                    fetch(`/api/video-recordings/${this.recordingId}/title`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ title: this.videoTitle.trim() }),
                    })
                );
            }

            promises.push(
                fetch(`/api/video-recordings/${this.recordingId}/finalize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                })
            );

            const results = await Promise.all(promises);
            const finalizeResponse = results[results.length - 1];

            if (!finalizeResponse.ok) throw new Error('Finalize failed');

            // Show post-save actions via Livewire
            const statusResp = await fetch(`/api/video-recordings/${this.recordingId}/status`, {
                headers: { 'Accept': 'application/json' },
            });
            const statusData = await statusResp.json();

            this.$wire.set('lastRecordingId', this.recordingId);
            this.$wire.set('lastShareToken', statusData.share_url ? statusData.share_url.split('/v/')[1] : null);
            this.$wire.set('showPostActions', true);

            this.stage = 'processing';
            this.recordedBlob = null;
            this.recordedChunks = [];
            this.videoTitle = '';
            this.recordingId = null;
            this.totalUploaded = 0;

            setTimeout(async () => {
                this.stage = 'idle';
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                await this.exitFullscreen();
            }, 1500);
        } catch (e) {
            console.error('Finalize failed:', e);
            alert('Failed to save recording. Please try again.');
            this.stage = 'review';
        }
    },

    async uploadRecordingLegacy() {
        if (!this.recordedBlob) return;

        this.stage = 'uploading';
        this.uploadProgress = 0;

        const ext = this.recordedBlob.type.includes('mp4') ? 'mp4' : 'webm';
        const formData = new FormData();
        formData.append('file', this.recordedBlob, `recording.${ext}`);
        if (this.videoTitle.trim()) {
            formData.append('title', this.videoTitle.trim());
        }

        try {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    this.uploadProgress = (e.loaded / e.total) * 100;
                }
            });

            const response = await new Promise((resolve, reject) => {
                xhr.onload = () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(JSON.parse(xhr.responseText));
                    } else {
                        reject(new Error('Upload failed: ' + xhr.statusText));
                    }
                };
                xhr.onerror = () => reject(new Error('Upload failed'));

                xhr.open('POST', '/api/video-recordings');
                xhr.setRequestHeader('X-CSRF-TOKEN', config.csrfToken);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.send(formData);
            });

            this.$wire.set('lastRecordingId', response.id);
            this.$wire.set('lastShareToken', response.share_token);
            this.$wire.set('showPostActions', true);

            this.stage = 'processing';
            this.recordedBlob = null;
            this.recordedChunks = [];
            this.videoTitle = '';

            setTimeout(async () => {
                this.stage = 'idle';
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                await this.exitFullscreen();
            }, 1500);
        } catch (e) {
            console.error('Upload failed:', e);
            alert('Upload failed. Please try again.');
            this.stage = 'review';
        }
    },

    async cancelRecorder() {
        this.cleanupStream();
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
            this.recordingTimer = null;
        }
        this.mediaRecorder = null;
        this.recordedBlob = null;
        this.recordedChunks = [];
        this.videoTitle = '';
        this.recordingId = null;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.totalUploaded = 0;
        this.stage = 'idle';

        await this.exitFullscreen();
    },

    cleanupStream() {
        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.stream = null;
        }
    },

    getSupportedMimeType() {
        const types = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp9,opus',
            'video/webm',
            'video/mp4;codecs=h264,aac',
            'video/mp4',
        ];
        for (const type of types) {
            if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(type)) return type;
        }
        return '';
    },
}));
</script>
@endscript
