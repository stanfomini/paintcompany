<?php

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $showPanel = false;
    public ?int $recordingId = null;
    public string $recordingTitle = '';
    public ?string $shareUrl = null;
    public bool $linkCopied = false;

    public function togglePanel(): void
    {
        $this->showPanel = ! $this->showPanel;
    }

    public function closePanel(): void
    {
        $this->showPanel = false;
    }

    public function saveTitle(): void
    {
        if (! $this->recordingId || ! $this->recordingTitle) {
            return;
        }

        $recording = VideoRecording::find($this->recordingId);

        if ($recording && $recording->user_id === auth()->id()) {
            $recording->update(['title' => $this->recordingTitle]);
        }
    }

    public function addToChannel(int $channelId): void
    {
        if (! $this->recordingId) {
            return;
        }

        $channel = VideoChannel::find($channelId);

        if (! $channel) {
            return;
        }

        $exists = VideoChannelItem::where('video_channel_id', $channelId)
            ->where('video_recording_id', $this->recordingId)
            ->exists();

        if (! $exists) {
            $maxSort = VideoChannelItem::where('video_channel_id', $channelId)->max('sort_order') ?? 0;

            VideoChannelItem::create([
                'video_channel_id' => $channelId,
                'video_recording_id' => $this->recordingId,
                'sort_order' => $maxSort + 1,
                'published_at' => now(),
            ]);
        }

        $this->dispatch('notify', message: __('Added to channel'));
    }

    public function resetRecording(): void
    {
        $this->recordingId = null;
        $this->recordingTitle = '';
        $this->shareUrl = null;
        $this->linkCopied = false;
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

<div>
    {{-- Floating record button --}}
    <button
        wire:click="togglePanel"
        class="fixed bottom-6 right-6 z-50 flex items-center gap-2 rounded-full bg-red-600 px-4 py-3 text-sm font-medium text-white shadow-lg hover:bg-red-700 transition-colors"
        title="{{ __('Quick Record') }}"
    >
        <flux:icon name="video-camera" class="size-5" />
        <span class="hidden sm:inline">{{ __('Record') }}</span>
    </button>

    {{-- Recording panel --}}
    @if($showPanel)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50" wire:click.self="closePanel">
            <div class="w-full max-w-lg bg-white dark:bg-zinc-800 rounded-t-xl sm:rounded-xl shadow-2xl overflow-hidden" @click.stop>

                {{-- Header --}}
                <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Quick Record') }}</h3>
                    <button wire:click="closePanel" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </div>

                {{-- Content --}}
                <div class="p-4" x-data="quickRecorder(@js($this->recordingId), @js($this->shareUrl))">

                    {{-- Camera preview / recording --}}
                    <template x-if="state === 'idle' || state === 'recording' || state === 'countdown'">
                        <div>
                            <div class="relative rounded-lg overflow-hidden bg-black aspect-video mb-4">
                                <video x-ref="preview" autoplay muted playsinline class="w-full h-full object-cover"></video>

                                {{-- Countdown overlay --}}
                                <template x-if="state === 'countdown'">
                                    <div class="absolute inset-0 flex items-center justify-center bg-black/60">
                                        <span class="text-6xl font-bold text-white" x-text="countdownValue"></span>
                                    </div>
                                </template>

                                {{-- Recording indicator --}}
                                <template x-if="state === 'recording'">
                                    <div class="absolute top-3 left-3 flex items-center gap-2">
                                        <span class="relative flex h-3 w-3">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                        </span>
                                        <span class="text-sm font-mono text-white bg-black/50 px-2 py-0.5 rounded" x-text="elapsed"></span>
                                    </div>
                                </template>
                            </div>

                            {{-- Controls --}}
                            <div class="flex items-center justify-center gap-4">
                                <template x-if="state === 'idle'">
                                    <button @click="startCountdown()" class="flex items-center gap-2 rounded-full bg-red-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-red-700 transition-colors">
                                        <span class="h-3 w-3 rounded-full bg-white"></span>
                                        {{ __('Start Recording') }}
                                    </button>
                                </template>
                                <template x-if="state === 'recording'">
                                    <button @click="stopRecording()" class="flex items-center gap-2 rounded-full bg-zinc-700 px-6 py-2.5 text-sm font-medium text-white hover:bg-zinc-600 transition-colors">
                                        <span class="h-3 w-3 rounded bg-red-500"></span>
                                        {{ __('Stop') }}
                                    </button>
                                </template>
                            </div>

                            <template x-if="error">
                                <p class="mt-3 text-sm text-red-500 text-center" x-text="error"></p>
                            </template>
                        </div>
                    </template>

                    {{-- Uploading state --}}
                    <template x-if="state === 'uploading'">
                        <div class="text-center py-8">
                            <flux:icon name="arrow-up-tray" class="mx-auto size-10 text-zinc-400 animate-pulse" />
                            <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Uploading...') }}</p>
                            <div class="mt-3 w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2">
                                <div class="bg-blue-600 h-2 rounded-full transition-all" :style="'width: ' + uploadProgress + '%'"></div>
                            </div>
                        </div>
                    </template>

                    {{-- Done state --}}
                    <template x-if="state === 'done'">
                        <div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Title') }}</label>
                                <input
                                    type="text"
                                    wire:model.blur="recordingTitle"
                                    wire:change="saveTitle"
                                    class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-700 px-3 py-2 text-sm text-zinc-900 dark:text-white focus:ring-2 focus:ring-blue-500"
                                    placeholder="{{ __('Untitled Recording') }}"
                                />
                            </div>

                            <div class="space-y-2">
                                {{-- Copy link --}}
                                <button
                                    @click="copyLink()"
                                    class="w-full flex items-center gap-3 rounded-lg border border-zinc-200 dark:border-zinc-600 px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors"
                                >
                                    <flux:icon name="link" class="size-5 text-zinc-500" />
                                    <span class="text-zinc-700 dark:text-zinc-300" x-text="linkCopied ? '{{ __('Copied!') }}' : '{{ __('Copy Share Link') }}'"></span>
                                </button>

                                {{-- Add to channel --}}
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

                                {{-- Record another --}}
                                <button
                                    @click="recordAnother()"
                                    wire:click="resetRecording"
                                    class="w-full flex items-center gap-3 rounded-lg border border-zinc-200 dark:border-zinc-600 px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors"
                                >
                                    <flux:icon name="video-camera" class="size-5 text-zinc-500" />
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ __('Record Another') }}</span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    @endif

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('quickRecorder', (existingRecordingId, existingShareUrl) => ({
                state: existingRecordingId ? 'done' : 'idle',
                stream: null,
                mediaRecorder: null,
                chunks: [],
                elapsed: '00:00',
                timerInterval: null,
                startTime: null,
                error: null,
                uploadProgress: 0,
                recordingId: existingRecordingId,
                shareUrl: existingShareUrl,
                linkCopied: false,
                countdownValue: 3,

                init() {
                    if (this.state === 'idle') {
                        this.initCamera();
                    }

                    // Cleanup on panel close
                    this.$watch('$wire.showPanel', (open) => {
                        if (open && this.state === 'idle') {
                            this.$nextTick(() => this.initCamera());
                        } else if (!open) {
                            this.cleanup();
                        }
                    });
                },

                async initCamera() {
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({
                            video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                            audio: true,
                        });
                        this.$nextTick(() => {
                            if (this.$refs.preview) {
                                this.$refs.preview.srcObject = this.stream;
                            }
                        });
                        this.error = null;
                    } catch (e) {
                        this.error = 'Camera access denied. Please allow camera and microphone permissions.';
                    }
                },

                startCountdown() {
                    this.state = 'countdown';
                    this.countdownValue = 3;
                    const interval = setInterval(() => {
                        this.countdownValue--;
                        if (this.countdownValue <= 0) {
                            clearInterval(interval);
                            this.startRecording();
                        }
                    }, 1000);
                },

                startRecording() {
                    if (!this.stream) return;

                    this.state = 'recording';
                    this.chunks = [];

                    const mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')
                        ? 'video/webm;codecs=vp9,opus' : 'video/webm';

                    this.mediaRecorder = new MediaRecorder(this.stream, {
                        mimeType,
                        videoBitsPerSecond: 2_500_000,
                    });

                    this.mediaRecorder.ondataavailable = (e) => {
                        if (e.data.size > 0) this.chunks.push(e.data);
                    };

                    this.mediaRecorder.onstop = () => {
                        this.uploadRecording();
                    };

                    this.mediaRecorder.start(1000);
                    this.startTime = Date.now();
                    this.timerInterval = setInterval(() => this.updateTimer(), 1000);
                },

                updateTimer() {
                    const seconds = Math.floor((Date.now() - this.startTime) / 1000);
                    const m = Math.floor(seconds / 60);
                    const s = seconds % 60;
                    this.elapsed = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                },

                stopRecording() {
                    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                        this.mediaRecorder.stop();
                    }
                    if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                        this.timerInterval = null;
                    }
                },

                async uploadRecording() {
                    this.state = 'uploading';
                    this.uploadProgress = 0;

                    const mimeType = this.chunks[0]?.type || 'video/webm';
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

                    try {
                        // Start chunked upload
                        const startResp = await fetch('/api/video-recordings/start', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                            body: JSON.stringify({ mime_type: mimeType, title: 'Quick Recording ' + new Date().toLocaleString() }),
                        });
                        const startData = await startResp.json();
                        this.recordingId = startData.id;
                        this.shareUrl = startData.share_token ? ('/v/' + startData.share_token) : null;

                        // Upload chunks
                        for (let i = 0; i < this.chunks.length; i++) {
                            const formData = new FormData();
                            formData.append('chunk', this.chunks[i]);
                            formData.append('chunk_index', i);

                            await fetch('/api/video-recordings/' + this.recordingId + '/chunk', {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                                body: formData,
                            });

                            this.uploadProgress = Math.round(((i + 1) / this.chunks.length) * 90);
                        }

                        // Finalize
                        await fetch('/api/video-recordings/' + this.recordingId + '/finalize', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                            body: JSON.stringify({}),
                        });

                        this.uploadProgress = 100;

                        // Set Livewire properties
                        this.$wire.set('recordingId', this.recordingId);
                        this.$wire.set('recordingTitle', 'Quick Recording ' + new Date().toLocaleString());
                        this.$wire.set('shareUrl', this.shareUrl);

                        this.state = 'done';
                    } catch (e) {
                        this.error = 'Upload failed. Please try again.';
                        this.state = 'idle';
                        this.initCamera();
                    }
                },

                async copyLink() {
                    if (this.shareUrl) {
                        await navigator.clipboard.writeText(window.location.origin + this.shareUrl);
                        this.linkCopied = true;
                        this.$wire.set('linkCopied', true);
                        setTimeout(() => { this.linkCopied = false; }, 3000);
                    }
                },

                recordAnother() {
                    this.state = 'idle';
                    this.recordingId = null;
                    this.shareUrl = null;
                    this.chunks = [];
                    this.elapsed = '00:00';
                    this.linkCopied = false;
                    this.$nextTick(() => this.initCamera());
                },

                cleanup() {
                    if (this.stream) {
                        this.stream.getTracks().forEach(t => t.stop());
                        this.stream = null;
                    }
                    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                        this.mediaRecorder.stop();
                    }
                    if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                    }
                },

                destroy() {
                    this.cleanup();
                },
            }));
        });
    </script>
</div>
