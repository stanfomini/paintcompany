<?php

use App\Models\RecordingInvite;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.video-call', ['title' => 'Record Video'])]
class extends Component {
    public RecordingInvite $invite;
    public bool $completed = false;
    public ?int $lastRecordingId = null;

    public function mount(string $token): void
    {
        $this->invite = RecordingInvite::where('token', $token)->firstOrFail();

        if ($this->invite->isExpired()) {
            abort(410, 'This recording invite has expired.');
        }

        if ($this->invite->isCompleted()) {
            $this->completed = true;
        }
    }

    /**
     * Link a completed recording to this invite.
     *
     * TODO: The upload API currently requires authentication. For public invite
     * recordings, we need either:
     * 1. A separate upload endpoint that accepts the invite token for auth, or
     * 2. A middleware exception for invite-based uploads.
     * For now, the component structure is complete and the linkRecording method
     * is ready to be called once the upload mechanism is resolved.
     */
    public function linkRecording(int $recordingId): void
    {
        if ($this->invite->isCompleted()) {
            return;
        }

        $this->invite->update([
            'video_recording_id' => $recordingId,
            'status' => 'completed',
        ]);

        $this->completed = true;
        $this->lastRecordingId = $recordingId;
    }
};
?>

<div class="flex flex-col min-h-screen bg-zinc-900 text-white">
    {{-- Header bar --}}
    <div class="shrink-0 border-b border-zinc-800 px-4 py-3">
        <div class="max-w-2xl mx-auto flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-white truncate">{{ config('app.name') }}</p>
                <p class="text-xs text-zinc-400">{{ __('Recording Invite') }}</p>
            </div>
        </div>
    </div>

    {{-- Main content --}}
    <div class="flex-1 flex items-center justify-center p-4">
        <div class="max-w-2xl w-full">
            @if($completed)
                {{-- Completed state --}}
                <div class="text-center py-16 space-y-4">
                    <div class="w-20 h-20 mx-auto rounded-full bg-green-600/20 flex items-center justify-center">
                        <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-white">{{ __('Thank You!') }}</h2>
                    <p class="text-zinc-400 max-w-md mx-auto">
                        {{ __('Your recording has been submitted successfully. You can close this page now.') }}
                    </p>
                </div>
            @else
                {{-- Recording interface --}}
                <div class="space-y-6">
                    {{-- Invite details --}}
                    <div class="text-center space-y-2">
                        <h1 class="text-2xl font-bold text-white">{{ $invite->title }}</h1>
                        @if($invite->instructions)
                            <p class="text-zinc-400 max-w-lg mx-auto">{{ $invite->instructions }}</p>
                        @endif
                        <p class="text-xs text-zinc-500">
                            {{ __('Max duration:') }} {{ floor($invite->max_duration_seconds / 60) }}:{{ str_pad($invite->max_duration_seconds % 60, 2, '0', STR_PAD_LEFT) }}
                        </p>
                    </div>

                    {{-- Recorder area --}}
                    <div
                        x-data="inviteRecorder({
                            csrfToken: @js(csrf_token()),
                            inviteToken: @js($invite->token),
                            maxDuration: @js($invite->max_duration_seconds),
                        })"
                    >
                        {{-- Camera preview / recording area --}}
                        <div class="relative aspect-video bg-zinc-800 rounded-xl overflow-hidden mx-auto max-w-xl">
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

                            {{-- Idle: start camera prompt --}}
                            <div x-show="stage === 'idle'" class="absolute inset-0 flex flex-col items-center justify-center gap-4">
                                <button
                                    type="button"
                                    @click="startCamera()"
                                    class="px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold transition-colors flex items-center gap-2"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                                    </svg>
                                    {{ __('Enable Camera') }}
                                </button>
                                <p class="text-xs text-zinc-500">{{ __('Camera and microphone access required') }}</p>
                            </div>

                            {{-- Recording indicator --}}
                            <div x-show="stage === 'recording'" class="absolute top-3 left-3 flex items-center gap-2 px-3 py-1.5 rounded-full bg-red-600/90 backdrop-blur-sm text-white text-sm font-semibold z-10">
                                <div class="w-2.5 h-2.5 rounded-full bg-white animate-pulse"></div>
                                <span x-text="recordingTime"></span>
                            </div>

                            {{-- Flip camera --}}
                            <button
                                x-show="stage === 'previewing' || stage === 'recording'"
                                type="button"
                                @click="flipCamera()"
                                class="absolute top-3 right-3 w-10 h-10 flex items-center justify-center rounded-full bg-black/40 backdrop-blur-sm text-white active:bg-black/60 z-10"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            </button>

                            {{-- Upload overlay --}}
                            <div x-show="stage === 'uploading'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                                <div class="text-center">
                                    <div class="w-20 h-20 mx-auto mb-4 relative">
                                        <svg class="w-20 h-20 transform -rotate-90" viewBox="0 0 80 80">
                                            <circle cx="40" cy="40" r="34" fill="none" stroke="#3f3f46" stroke-width="5" />
                                            <circle cx="40" cy="40" r="34" fill="none" stroke="#6366f1" stroke-width="5" stroke-linecap="round"
                                                :stroke-dasharray="'213.63'"
                                                :stroke-dashoffset="213.63 - (213.63 * uploadProgress / 100)"
                                            />
                                        </svg>
                                        <span class="absolute inset-0 flex items-center justify-center text-white text-lg font-bold" x-text="Math.round(uploadProgress) + '%'"></span>
                                    </div>
                                    <p class="text-white text-base font-medium">{{ __('Uploading...') }}</p>
                                </div>
                            </div>

                            {{-- Finalizing overlay --}}
                            <div x-show="stage === 'finalizing'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                                <div class="text-center">
                                    <div class="animate-spin w-14 h-14 border-4 border-white border-t-transparent rounded-full mx-auto mb-4"></div>
                                    <p class="text-white text-base font-medium">{{ __('Finalizing...') }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- Controls below the video --}}
                        <div class="mt-4">
                            {{-- Previewing: record button --}}
                            <div x-show="stage === 'previewing'" class="flex justify-center">
                                <button
                                    type="button"
                                    @click="startRecording()"
                                    class="w-20 h-20 rounded-full border-[5px] border-white flex items-center justify-center active:scale-95 transition-transform"
                                >
                                    <div class="w-14 h-14 rounded-full bg-red-600"></div>
                                </button>
                            </div>

                            {{-- Recording: stop button --}}
                            <div x-show="stage === 'recording'" class="flex justify-center">
                                <button
                                    type="button"
                                    @click="stopRecording()"
                                    class="w-20 h-20 rounded-full border-[5px] border-white flex items-center justify-center active:scale-95 transition-transform"
                                >
                                    <div class="w-8 h-8 rounded-sm bg-red-600"></div>
                                </button>
                            </div>

                            {{-- Review: retake / save --}}
                            <div x-show="stage === 'review'" class="flex items-center gap-3 max-w-sm mx-auto">
                                <button
                                    type="button"
                                    @click="retake()"
                                    class="flex-1 py-3 rounded-xl bg-zinc-700 text-white font-semibold text-base active:bg-zinc-600 transition-colors"
                                >
                                    {{ __('Retake') }}
                                </button>
                                <button
                                    type="button"
                                    @click="saveRecording()"
                                    class="flex-[2] py-3 rounded-xl bg-indigo-600 text-white font-semibold text-base active:bg-indigo-700 transition-colors flex items-center justify-center gap-2"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                                    {{ __('Submit Recording') }}
                                </button>
                            </div>
                        </div>

                        {{-- Error message --}}
                        <div x-show="errorMessage" class="mt-4 text-center">
                            <p class="text-red-400 text-sm" x-text="errorMessage"></p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Footer --}}
    <div class="shrink-0 border-t border-zinc-800 px-4 py-3 text-center">
        <p class="text-xs text-zinc-500">{{ __('Powered by') }} {{ config('app.name') }}</p>
    </div>
</div>

@script
<script>
Alpine.data('inviteRecorder', (config) => ({
    stage: 'idle',
    stream: null,
    mediaRecorder: null,
    recordedChunks: [],
    recordedBlob: null,
    uploadProgress: 0,
    recordingSeconds: 0,
    recordingTimer: null,
    useFrontCamera: true,
    errorMessage: '',

    _rawStream: null,

    // TODO: Chunked upload for invite recordings needs a public API endpoint
    // that authenticates via invite token instead of session auth.
    // For now, the recording flow is structurally complete but upload
    // will need the invite-token-based upload endpoint to function.
    recordingId: null,
    chunkIndex: 0,
    uploadQueue: [],
    isUploading: false,
    totalUploaded: 0,

    get recordingTime() {
        const m = Math.floor(this.recordingSeconds / 60);
        const s = this.recordingSeconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    },

    async startCamera() {
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

            this.$nextTick(() => {
                if (this.$refs.videoPreview) {
                    this.$refs.videoPreview.srcObject = this._rawStream;
                }
            });
        } catch (e) {
            console.error('Camera access failed:', e);
            this.errorMessage = 'Could not access camera. Please check permissions.';
        }
    },

    async flipCamera() {
        this.useFrontCamera = !this.useFrontCamera;
        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.stream = null;
        }
        await this.startCamera();
    },

    async startRecording() {
        if (!this._rawStream) return;

        this.recordedChunks = [];
        this.recordingSeconds = 0;
        this.errorMessage = '';

        const mimeType = this.getSupportedMimeType();
        if (!mimeType) {
            this.errorMessage = 'Your browser does not support video recording.';
            return;
        }

        // TODO: Start chunked upload session using invite token auth endpoint
        // For now, we accumulate chunks locally and upload at the end.

        this.mediaRecorder = new MediaRecorder(this._rawStream, {
            mimeType,
            videoBitsPerSecond: 2500000,
        });

        this.mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) {
                this.recordedChunks.push(e.data);
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
            if (this.recordingSeconds >= config.maxDuration) {
                this.stopRecording();
            }
        }, 1000);
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
        this.recordedBlob = null;
        this.recordedChunks = [];
        this.errorMessage = '';
        this.startCamera();
    },

    async saveRecording() {
        if (!this.recordedBlob) return;

        // TODO: Replace with invite-token-based upload endpoint
        // Currently using the standard upload endpoint which requires auth.
        // This will need a separate public endpoint like:
        // POST /api/recording-invites/{token}/upload

        this.stage = 'uploading';
        this.uploadProgress = 0;

        const ext = this.recordedBlob.type.includes('mp4') ? 'mp4' : 'webm';
        const formData = new FormData();
        formData.append('file', this.recordedBlob, `recording.${ext}`);
        formData.append('invite_token', config.inviteToken);

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

                // TODO: Change to invite-specific upload endpoint
                xhr.open('POST', '/api/video-recordings');
                xhr.setRequestHeader('X-CSRF-TOKEN', config.csrfToken);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.send(formData);
            });

            this.stage = 'finalizing';
            this.$wire.linkRecording(response.id);
        } catch (e) {
            console.error('Upload failed:', e);
            this.errorMessage = 'Upload failed. Please try again.';
            this.stage = 'review';
        }
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

    destroy() {
        this.cleanupStream();
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
        }
    },
}));
</script>
@endscript
