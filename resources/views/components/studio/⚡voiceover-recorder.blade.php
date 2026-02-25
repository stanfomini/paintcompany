<?php

use App\Models\VideoProject;
use App\Models\VideoProjectVoiceover;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public VideoProject $videoProject;
    public bool $isRecording = false;
    public ?int $recordingId = null;

    public function addVoiceover(int $videoRecordingId): void
    {
        abort_unless($this->videoProject->user_id === auth()->id(), 403);

        $this->videoProject->voiceovers()->create([
            'video_recording_id' => $videoRecordingId,
            'start_ms' => 0,
            'volume' => 100,
            'sort_order' => $this->videoProject->voiceovers()->count(),
        ]);

        $this->dispatch('voiceover-added');
    }
};
?>

<div
    x-data="voiceoverRecorder({ csrfToken: @js(csrf_token()) })"
    class="space-y-3"
>
    {{-- Record Button --}}
    <div x-show="stage === 'idle'">
        <flux:button
            size="sm"
            variant="ghost"
            @click="requestMicAccess()"
            class="w-full justify-start"
        >
            <flux:icon name="microphone" class="size-4 text-red-500" />
            {{ __('Record Voiceover') }}
        </flux:button>
    </div>

    {{-- Requesting Mic Access --}}
    <div x-show="stage === 'requesting'" x-cloak class="text-center py-3">
        <div class="animate-spin w-5 h-5 border-2 border-zinc-400 border-t-transparent rounded-full mx-auto mb-2"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requesting microphone access...') }}</p>
    </div>

    {{-- Ready to Record / Recording --}}
    <div x-show="stage === 'ready' || stage === 'recording'" x-cloak class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 space-y-3">
        {{-- Audio Level Meter --}}
        <div class="flex items-center gap-2">
            <span :class="stage === 'recording' ? 'text-red-500' : 'text-zinc-400'">
                <flux:icon name="microphone" class="size-4 shrink-0" />
            </span>
            <div class="flex-1 h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-75"
                    :class="stage === 'recording' ? 'bg-red-500' : 'bg-green-500'"
                    :style="'width: ' + Math.min(100, audioLevel) + '%'"
                ></div>
            </div>
        </div>

        {{-- Timer --}}
        <div class="text-center">
            <span
                class="text-lg font-mono font-semibold"
                :class="stage === 'recording' ? 'text-red-500' : 'text-zinc-600 dark:text-zinc-400'"
                x-text="recordingTime"
            ></span>
            <div x-show="stage === 'recording'" class="flex items-center justify-center gap-1.5 mt-1">
                <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                <span class="text-xs text-red-500 font-medium">{{ __('Recording') }}</span>
            </div>
        </div>

        {{-- Controls --}}
        <div class="flex items-center justify-center gap-3">
            <template x-if="stage === 'ready'">
                <div class="flex items-center gap-3">
                    <flux:button size="sm" variant="ghost" @click="cancelRecorder()">
                        {{ __('Cancel') }}
                    </flux:button>
                    <button
                        type="button"
                        @click="startRecording()"
                        class="w-12 h-12 rounded-full border-[3px] border-red-500 flex items-center justify-center hover:border-red-600 transition-colors"
                    >
                        <div class="w-8 h-8 rounded-full bg-red-500"></div>
                    </button>
                </div>
            </template>
            <template x-if="stage === 'recording'">
                <button
                    type="button"
                    @click="stopRecording()"
                    class="w-12 h-12 rounded-full border-[3px] border-red-500 flex items-center justify-center hover:border-red-600 transition-colors"
                >
                    <div class="w-5 h-5 rounded-sm bg-red-500"></div>
                </button>
            </template>
        </div>
    </div>

    {{-- Finalizing --}}
    <div x-show="stage === 'finalizing'" x-cloak class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
        <div class="animate-spin w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-2"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Saving voiceover...') }}</p>
    </div>

    {{-- Error --}}
    <div x-show="errorMessage" x-cloak class="text-xs text-red-500 text-center py-2" x-text="errorMessage"></div>
</div>

@script
<script>
Alpine.data('voiceoverRecorder', (config) => ({
    stage: 'idle',
    audioStream: null,
    mediaRecorder: null,
    analyserNode: null,
    audioContext: null,
    audioLevel: 0,
    recordingSeconds: 0,
    recordingTimer: null,
    levelTimer: null,
    errorMessage: '',
    maxDuration: 300,

    recordingId: null,
    chunkIndex: 0,
    uploadQueue: [],
    isUploading: false,

    _rawStream: null,

    get recordingTime() {
        const m = Math.floor(this.recordingSeconds / 60);
        const s = this.recordingSeconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    },

    async requestMicAccess() {
        this.stage = 'requesting';
        this.errorMessage = '';

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
                video: false,
            });

            this._rawStream = stream;
            this.audioStream = stream;

            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const source = this.audioContext.createMediaStreamSource(this._rawStream);
            this.analyserNode = this.audioContext.createAnalyser();
            this.analyserNode.fftSize = 256;
            source.connect(this.analyserNode);

            this.startLevelMeter();
            this.stage = 'ready';
        } catch (e) {
            console.error('Microphone access failed:', e);
            this.errorMessage = 'Could not access microphone. Please check permissions.';
            this.stage = 'idle';
        }
    },

    startLevelMeter() {
        if (!this.analyserNode) return;

        const dataArray = new Uint8Array(this.analyserNode.frequencyBinCount);

        const update = () => {
            if (!this.analyserNode) return;
            this.analyserNode.getByteFrequencyData(dataArray);
            let sum = 0;
            for (let i = 0; i < dataArray.length; i++) {
                sum += dataArray[i];
            }
            this.audioLevel = (sum / dataArray.length) / 2.55;
            this.levelTimer = requestAnimationFrame(update);
        };

        update();
    },

    stopLevelMeter() {
        if (this.levelTimer) {
            cancelAnimationFrame(this.levelTimer);
            this.levelTimer = null;
        }
        this.audioLevel = 0;
    },

    getSupportedAudioMimeType() {
        const types = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/mp4',
        ];
        for (const type of types) {
            if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(type)) {
                return type;
            }
        }
        return '';
    },

    async startRecording() {
        if (!this._rawStream) return;

        this.recordingSeconds = 0;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.isUploading = false;
        this.recordingId = null;

        const mimeType = this.getSupportedAudioMimeType();
        if (!mimeType) {
            this.errorMessage = 'Your browser does not support audio recording.';
            return;
        }

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
            console.error('Failed to start recording session:', e);
            this.errorMessage = 'Failed to start recording. Please try again.';
            return;
        }

        this.mediaRecorder = new MediaRecorder(this._rawStream, {
            mimeType,
            audioBitsPerSecond: 128000,
        });

        this.mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0 && this.recordingId) {
                this.uploadQueue.push(e.data);
                this.processUploadQueue();
            }
        };

        this.mediaRecorder.onstop = () => {
            this.finalizeRecording();
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
            } catch (e) {
                console.error(`Chunk ${currentIndex} upload failed:`, e);
                this.uploadQueue.unshift(chunk);
                await new Promise(r => setTimeout(r, 1000));
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
    },

    async finalizeRecording() {
        this.stage = 'finalizing';
        this.stopLevelMeter();

        while (this.uploadQueue.length > 0 || this.isUploading) {
            await new Promise(r => setTimeout(r, 200));
        }

        try {
            const response = await fetch(`/api/video-recordings/${this.recordingId}/finalize`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                    'Accept': 'application/json',
                },
            });

            if (!response.ok) throw new Error('Finalize failed');

            this.$wire.call('addVoiceover', this.recordingId);

            this.cleanupStream();
            this.recordingId = null;
            this.chunkIndex = 0;
            this.recordingSeconds = 0;
            this.stage = 'idle';
        } catch (e) {
            console.error('Finalize failed:', e);
            this.errorMessage = 'Failed to save voiceover. Please try again.';
            this.cleanupStream();
            this.stage = 'idle';
        }
    },

    cancelRecorder() {
        this.stopLevelMeter();
        this.cleanupStream();

        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
            this.recordingTimer = null;
        }

        this.mediaRecorder = null;
        this.recordingId = null;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.recordingSeconds = 0;
        this.errorMessage = '';
        this.stage = 'idle';
    },

    cleanupStream() {
        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.audioStream = null;
        }
        if (this.audioContext) {
            this.audioContext.close().catch(() => {});
            this.audioContext = null;
            this.analyserNode = null;
        }
    },

    destroy() {
        this.stopLevelMeter();
        this.cleanupStream();
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
        }
    },
}));
</script>
@endscript
