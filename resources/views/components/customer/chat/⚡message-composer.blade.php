<?php

use App\Models\ChatThread;
use App\Services\AudioMessageService;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public ChatThread $thread;

    #[Validate('required|string|max:5000')]
    public string $message = '';

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    #[Validate(['attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt'])]
    public array $attachments = [];

    public bool $isRecording = false;

    public bool $isProcessingAudio = false;

    public function mount(ChatThread $thread): void
    {
        $this->thread = $thread;
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function send(): void
    {
        $this->validate();

        $user = auth('customer')->user();

        // Verify user is still a participant
        if (! $this->thread->hasParticipant($user)) {
            $this->addError('message', 'You are not authorized to send messages in this thread.');

            return;
        }

        $chatService = app(ChatService::class);
        $options = ['is_internal' => false];

        // Send with or without attachments
        if (! empty($this->attachments)) {
            $uploadedFiles = [];
            foreach ($this->attachments as $attachment) {
                $uploadedFiles[] = $attachment;
            }

            $chatService->sendMessageWithAttachments(
                $this->thread,
                $user,
                $this->message,
                $uploadedFiles,
                $options
            );
        } else {
            $chatService->sendMessage(
                $this->thread,
                $user,
                $this->message,
                $options
            );
        }

        $this->reset(['message', 'attachments']);
        $this->resetValidation();
        $this->dispatch('message-sent');
    }

    /**
     * Process recorded audio and send as a message.
     */
    public function processRecordedAudio(string $audioBase64, string $mimeType = 'audio/webm'): void
    {
        $this->isProcessingAudio = true;

        try {
            $user = auth('customer')->user();

            // Verify user is still a participant
            if (! $this->thread->hasParticipant($user)) {
                $this->addError('audio', 'You are not authorized to send messages in this thread.');

                return;
            }

            $audioService = app(AudioMessageService::class);
            $audioData = $audioService->processBase64Audio($audioBase64, $mimeType);

            $this->sendAudioMessage($audioData);
        } catch (\Exception $e) {
            $this->addError('audio', 'Failed to process audio recording: '.$e->getMessage());
        } finally {
            $this->isProcessingAudio = false;
            $this->isRecording = false;
        }
    }

    /**
     * Send an audio message.
     *
     * @param  array{audio_url: string, audio_path: string, audio_duration: float|null, transcription: string|null, transcription_status: string}  $audioData
     */
    private function sendAudioMessage(array $audioData): void
    {
        $chatService = app(ChatService::class);
        $user = auth('customer')->user();

        $chatService->sendAudioMessage(
            $this->thread,
            $user,
            $audioData,
            ['is_internal' => false]
        );

        // Notify parent component
        $this->dispatch('message-sent');
    }
}; ?>

<div
    x-data="{
        recording: false,
        recordingTime: 0,
        mediaRecorder: null,
        audioChunks: [],
        timer: null,
        stream: null,
        hasMediaSupport: true,
        permissionDenied: false,
        minRecordingTime: 1000,
        cancelled: false,
        recordingStartTime: null,
        errorMessage: null,
        requestingPermission: false,
        previewing: false,
        previewUrl: null,
        previewBlob: null,
        previewDuration: 0,
        currentMimeType: null,

        // Audio device selection
        audioDevices: [],
        selectedDeviceId: null,
        showDeviceSelector: false,
        loadingDevices: false,

        // Audio level monitoring
        audioContext: null,
        analyser: null,
        audioLevel: 0,
        peakLevel: 0,
        levelAnimationFrame: null,
        silenceWarningShown: false,
        silenceCheckTimeout: null,

        // Silent audio detection for preview
        isAudioSilent: false,
        analyzingAudio: false,

        async init() {
            this.hasMediaSupport = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
            console.log('[AudioRecorder] Init - hasMediaSupport:', this.hasMediaSupport);
            console.log('[AudioRecorder] Init - Secure context:', window.isSecureContext);

            // Load saved device preference
            const savedDevice = localStorage.getItem('preferredAudioDevice');
            if (savedDevice) {
                this.selectedDeviceId = savedDevice;
                console.log('[AudioRecorder] Loaded saved device:', savedDevice);
            }

            // Try to load devices (may need permission first)
            if (this.hasMediaSupport) {
                await this.loadAudioDevices();
            }
        },

        async loadAudioDevices() {
            try {
                this.loadingDevices = true;
                const devices = await navigator.mediaDevices.enumerateDevices();
                this.audioDevices = devices.filter(d => d.kind === 'audioinput');
                console.log('[AudioRecorder] Found audio devices:', this.audioDevices.length);

                // If we have devices but no labels, we need permission first
                const hasLabels = this.audioDevices.some(d => d.label);
                if (!hasLabels && this.audioDevices.length > 0) {
                    console.log('[AudioRecorder] Device labels not available yet (need permission)');
                }

                // Validate saved device still exists
                if (this.selectedDeviceId) {
                    const deviceExists = this.audioDevices.find(d => d.deviceId === this.selectedDeviceId);
                    if (!deviceExists) {
                        console.log('[AudioRecorder] Saved device no longer available, clearing preference');
                        this.selectedDeviceId = null;
                        localStorage.removeItem('preferredAudioDevice');
                    }
                }
            } catch (err) {
                console.error('[AudioRecorder] Error loading devices:', err);
            } finally {
                this.loadingDevices = false;
            }
        },

        selectDevice(deviceId) {
            this.selectedDeviceId = deviceId;
            localStorage.setItem('preferredAudioDevice', deviceId);
            this.showDeviceSelector = false;
            console.log('[AudioRecorder] Selected device:', deviceId);
        },

        getDeviceLabel(device) {
            if (device.label) {
                return device.label;
            }
            // Fallback for when labels aren't available
            const index = this.audioDevices.indexOf(device);
            return 'Microphone ' + (index + 1);
        },

        toggleRecording() {
            console.log('[AudioRecorder] toggleRecording called - current state:', {
                recording: this.recording,
                previewing: this.previewing,
                hasMediaSupport: this.hasMediaSupport,
                permissionDenied: this.permissionDenied,
                requestingPermission: this.requestingPermission
            });

            if (this.previewing) {
                console.log('[AudioRecorder] In preview mode, ignoring toggle');
                return;
            }

            if (this.recording) {
                this.stopRecording();
            } else {
                this.startRecording();
            }
        },

        async startRecording() {
            console.log('[AudioRecorder] startRecording called');

            if (this.recording) {
                console.log('[AudioRecorder] Already recording, returning');
                return;
            }
            if (!this.hasMediaSupport) {
                console.log('[AudioRecorder] No media support, returning');
                this.errorMessage = 'Your browser does not support audio recording.';
                return;
            }
            if (this.requestingPermission) {
                console.log('[AudioRecorder] Already requesting permission, returning');
                return;
            }

            this.errorMessage = null;
            this.requestingPermission = true;
            this.permissionDenied = false;
            this.silenceWarningShown = false;
            this.audioLevel = 0;
            this.peakLevel = 0;

            try {
                console.log('[AudioRecorder] Requesting microphone access...');

                // Build audio constraints with device selection
                const audioConstraints = {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                };

                // Add device constraint if a specific device is selected
                if (this.selectedDeviceId) {
                    audioConstraints.deviceId = { exact: this.selectedDeviceId };
                    console.log('[AudioRecorder] Using selected device:', this.selectedDeviceId);
                }

                this.stream = await navigator.mediaDevices.getUserMedia({
                    audio: audioConstraints
                });

                console.log('[AudioRecorder] Microphone access granted, stream:', this.stream);
                this.requestingPermission = false;

                // Reload devices now that we have permission (to get labels)
                await this.loadAudioDevices();

                // Start audio level monitoring
                this.startLevelMonitoring();

                this.currentMimeType = this.getSupportedMimeType();
                console.log('[AudioRecorder] Using mimeType:', this.currentMimeType);

                this.mediaRecorder = new MediaRecorder(this.stream, { mimeType: this.currentMimeType });
                this.audioChunks = [];

                this.mediaRecorder.ondataavailable = (e) => {
                    if (e.data.size > 0) {
                        this.audioChunks.push(e.data);
                        console.log('[AudioRecorder] Chunk received, size:', e.data.size, 'total chunks:', this.audioChunks.length);
                    }
                };

                this.mediaRecorder.onstop = async () => {
                    console.log('[AudioRecorder] MediaRecorder stopped, chunks:', this.audioChunks.length, 'cancelled:', this.cancelled);

                    this.stopLevelMonitoring();
                    this.cleanupStream();

                    if (this.audioChunks.length > 0 && !this.cancelled) {
                        const audioBlob = new Blob(this.audioChunks, { type: this.currentMimeType });
                        console.log('[AudioRecorder] Audio blob created, size:', audioBlob.size);

                        // Analyze audio for silence before showing preview
                        await this.analyzeAudioForSilence(audioBlob);

                        this.previewBlob = audioBlob;
                        this.previewUrl = URL.createObjectURL(audioBlob);
                        this.previewDuration = this.recordingTime;
                        this.previewing = true;
                        console.log('[AudioRecorder] Preview ready, URL:', this.previewUrl, 'isSilent:', this.isAudioSilent);
                    }
                };

                this.mediaRecorder.onerror = (e) => {
                    console.error('[AudioRecorder] MediaRecorder error:', e.error);
                    this.errorMessage = 'Recording error: ' + (e.error?.message || 'Unknown error');
                    this.cleanupRecording();
                };

                this.mediaRecorder.start(100);
                console.log('[AudioRecorder] Recording started');

                this.recording = true;
                this.cancelled = false;
                this.recordingTime = 0;
                this.recordingStartTime = Date.now();
                this.timer = setInterval(() => this.recordingTime++, 1000);

                // Set up silence warning after 3 seconds (only if peak level is essentially zero)
                this.silenceCheckTimeout = setTimeout(() => {
                    if (this.recording && this.peakLevel < 2) {
                        this.silenceWarningShown = true;
                        console.log('[AudioRecorder] Silence warning - no audio detected, peakLevel:', this.peakLevel);
                    }
                }, 3000);

            } catch (err) {
                console.error('[AudioRecorder] Error starting recording:', err.name, err.message, err);
                this.requestingPermission = false;

                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    this.permissionDenied = true;
                    this.errorMessage = 'Microphone access denied. Please click the camera/microphone icon in your browser\'s address bar to allow access, then try again.';
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    this.hasMediaSupport = false;
                    this.errorMessage = 'No microphone found. Please connect a microphone and refresh the page.';
                } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                    this.errorMessage = 'Microphone is busy or unavailable. Please close other apps using the microphone and try again.';
                } else if (err.name === 'OverconstrainedError') {
                    // Selected device may not be available, clear selection and retry
                    if (this.selectedDeviceId) {
                        console.log('[AudioRecorder] Device constraint failed, clearing selection');
                        this.selectedDeviceId = null;
                        localStorage.removeItem('preferredAudioDevice');
                        this.errorMessage = 'Selected microphone not available. Please try again or select a different microphone.';
                    } else {
                        this.errorMessage = 'Microphone settings not supported. Please try a different microphone.';
                    }
                } else if (err.name === 'SecurityError') {
                    this.errorMessage = 'Microphone access blocked due to security settings. Please ensure you are using HTTPS.';
                } else {
                    this.errorMessage = 'Could not start recording: ' + err.message;
                }
            }
        },

        startLevelMonitoring() {
            try {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                this.analyser = this.audioContext.createAnalyser();
                this.analyser.fftSize = 2048;
                this.analyser.smoothingTimeConstant = 0.3;

                const source = this.audioContext.createMediaStreamSource(this.stream);
                source.connect(this.analyser);

                // Use time domain data for more accurate level detection
                const dataArray = new Uint8Array(this.analyser.fftSize);

                const updateLevel = () => {
                    if (!this.recording) return;

                    // Get waveform data (values 0-255, 128 is silence)
                    this.analyser.getByteTimeDomainData(dataArray);

                    // Calculate the deviation from silence (128)
                    let maxDeviation = 0;
                    for (let i = 0; i < dataArray.length; i++) {
                        const deviation = Math.abs(dataArray[i] - 128);
                        if (deviation > maxDeviation) {
                            maxDeviation = deviation;
                        }
                    }

                    // Normalize to 0-100 scale (max deviation is 128)
                    this.audioLevel = Math.min(100, Math.round((maxDeviation / 128) * 100));

                    // Track peak level
                    if (this.audioLevel > this.peakLevel) {
                        this.peakLevel = this.audioLevel;
                    }

                    // Clear silence warning if we detect audio (threshold of 3 is very sensitive)
                    if (this.audioLevel > 3 && this.silenceWarningShown) {
                        this.silenceWarningShown = false;
                    }

                    this.levelAnimationFrame = requestAnimationFrame(updateLevel);
                };

                updateLevel();
                console.log('[AudioRecorder] Level monitoring started');
            } catch (err) {
                console.error('[AudioRecorder] Error starting level monitoring:', err);
            }
        },

        stopLevelMonitoring() {
            if (this.levelAnimationFrame) {
                cancelAnimationFrame(this.levelAnimationFrame);
                this.levelAnimationFrame = null;
            }
            if (this.silenceCheckTimeout) {
                clearTimeout(this.silenceCheckTimeout);
                this.silenceCheckTimeout = null;
            }
            if (this.audioContext && this.audioContext.state !== 'closed') {
                this.audioContext.close();
            }
            this.audioContext = null;
            this.analyser = null;
            this.audioLevel = 0;
            console.log('[AudioRecorder] Level monitoring stopped');
        },

        async analyzeAudioForSilence(blob) {
            this.analyzingAudio = true;
            this.isAudioSilent = false;

            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const arrayBuffer = await blob.arrayBuffer();
                const audioBuffer = await audioContext.decodeAudioData(arrayBuffer);

                // Get audio data from first channel
                const channelData = audioBuffer.getChannelData(0);

                // Calculate both RMS and peak to detect silence
                let sumSquares = 0;
                let maxAbs = 0;
                for (let i = 0; i < channelData.length; i++) {
                    const abs = Math.abs(channelData[i]);
                    sumSquares += channelData[i] * channelData[i];
                    if (abs > maxAbs) maxAbs = abs;
                }
                const rms = Math.sqrt(sumSquares / channelData.length);

                // Only mark as silent if BOTH RMS is extremely low AND peak is very low
                // This is a very conservative threshold - only truly silent audio
                // RMS < 0.001 means essentially no signal, peak < 0.01 means no spikes
                this.isAudioSilent = rms < 0.001 && maxAbs < 0.01;

                console.log('[AudioRecorder] Audio analysis - RMS:', rms.toFixed(6), 'Peak:', maxAbs.toFixed(6), 'isSilent:', this.isAudioSilent);

                audioContext.close();
            } catch (err) {
                console.error('[AudioRecorder] Error analyzing audio:', err);
                // Don't block on analysis errors
                this.isAudioSilent = false;
            } finally {
                this.analyzingAudio = false;
            }
        },

        stopRecording() {
            console.log('[AudioRecorder] stopRecording called');

            if (!this.mediaRecorder || !this.recording) {
                console.log('[AudioRecorder] Not recording or no mediaRecorder, returning');
                return;
            }

            const recordingDuration = Date.now() - this.recordingStartTime;
            console.log('[AudioRecorder] Recording duration:', recordingDuration, 'ms');

            if (recordingDuration < this.minRecordingTime) {
                console.log('[AudioRecorder] Recording too short, cancelling');
                this.errorMessage = 'Recording too short. Please record for at least 1 second.';
                this.cancelRecording();
                return;
            }

            this.stopLevelMonitoring();
            this.mediaRecorder.stop();
            this.recording = false;
            clearInterval(this.timer);
            console.log('[AudioRecorder] Stop signal sent to mediaRecorder');
        },

        cancelRecording() {
            console.log('[AudioRecorder] cancelRecording called');

            this.stopLevelMonitoring();

            if (this.mediaRecorder && this.recording) {
                this.cancelled = true;
                this.mediaRecorder.stop();
            }

            this.recording = false;
            this.silenceWarningShown = false;
            clearInterval(this.timer);
            this.audioChunks = [];
            this.cleanupStream();
            console.log('[AudioRecorder] Recording cancelled');
        },

        async sendRecording() {
            console.log('[AudioRecorder] sendRecording called');

            if (!this.previewBlob) {
                console.log('[AudioRecorder] No preview blob, returning');
                return;
            }

            try {
                const base64 = await this.blobToBase64(this.previewBlob);
                const mimeType = this.currentMimeType || 'audio/webm';
                console.log('[AudioRecorder] Sending audio, base64 length:', base64.length, 'mimeType:', mimeType);

                this.discardPreview();
                $wire.processRecordedAudio(base64, mimeType);
            } catch (err) {
                console.error('[AudioRecorder] Error sending recording:', err);
                this.errorMessage = 'Failed to send recording: ' + err.message;
            }
        },

        discardRecording() {
            console.log('[AudioRecorder] discardRecording called');
            this.discardPreview();
        },

        discardPreview() {
            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }
            this.previewing = false;
            this.previewUrl = null;
            this.previewBlob = null;
            this.previewDuration = 0;
            this.audioChunks = [];
            this.isAudioSilent = false;
            console.log('[AudioRecorder] Preview discarded');
        },

        cleanupStream() {
            if (this.stream) {
                this.stream.getTracks().forEach(track => {
                    track.stop();
                    console.log('[AudioRecorder] Track stopped:', track.kind);
                });
                this.stream = null;
            }
        },

        cleanupRecording() {
            this.recording = false;
            this.requestingPermission = false;
            this.silenceWarningShown = false;
            this.stopLevelMonitoring();
            clearInterval(this.timer);
            this.audioChunks = [];
            this.cleanupStream();
        },

        retryPermission() {
            console.log('[AudioRecorder] Retrying permission request');
            this.permissionDenied = false;
            this.errorMessage = null;
            this.startRecording();
        },

        getSupportedMimeType() {
            const types = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/mp4',
            ];
            for (const type of types) {
                if (MediaRecorder.isTypeSupported(type)) {
                    console.log('[AudioRecorder] Supported mimeType found:', type);
                    return type;
                }
            }
            console.log('[AudioRecorder] No preferred mimeType supported, using default');
            return 'audio/webm';
        },

        blobToBase64(blob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onloadend = () => {
                    const result = reader.result;
                    if (result && typeof result === 'string') {
                        resolve(result.split(',')[1]);
                    } else {
                        reject(new Error('Failed to read audio data'));
                    }
                };
                reader.onerror = () => reject(new Error('FileReader error'));
                reader.readAsDataURL(blob);
            });
        },

        formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return mins + ':' + secs.toString().padStart(2, '0');
        }
    }"
    x-on:keydown.escape.window="recording ? cancelRecording() : (previewing ? discardRecording() : null)"
    class="relative"
>
    {{-- Attachment previews --}}
    @if (!empty($attachments))
        <div class="mb-3 flex flex-wrap gap-2">
            @foreach ($attachments as $index => $attachment)
                <div
                    wire:key="attachment-{{ $index }}"
                    class="relative group bg-zinc-100 dark:bg-zinc-800 rounded-lg p-2"
                >
                    @if (str_starts_with($attachment->getMimeType(), 'image/'))
                        <img
                            src="{{ $attachment->temporaryUrl() }}"
                            class="h-16 w-16 object-cover rounded"
                            alt="{{ __('Attachment preview') }}"
                        />
                    @else
                        <div class="flex items-center gap-2 px-2">
                            <flux:icon name="document" class="h-6 w-6 text-zinc-500" />
                            <span class="text-sm text-zinc-600 dark:text-zinc-400 max-w-[100px] truncate">
                                {{ $attachment->getClientOriginalName() }}
                            </span>
                        </div>
                    @endif
                    <button
                        type="button"
                        wire:click="removeAttachment({{ $index }})"
                        class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                        <flux:icon name="x-mark" class="h-3 w-3" />
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Error message --}}
    <div
        x-show="errorMessage"
        x-cloak
        class="mb-3 flex items-center gap-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2"
    >
        <flux:icon name="exclamation-circle" class="h-4 w-4 text-red-500 shrink-0" />
        <span class="text-sm text-red-700 dark:text-red-300 flex-1" x-text="errorMessage"></span>
        <button
            x-show="permissionDenied"
            type="button"
            x-on:click="retryPermission()"
            class="text-red-600 hover:text-red-800 text-sm font-medium whitespace-nowrap"
        >
            {{ __('Try again') }}
        </button>
        <button
            type="button"
            x-on:click="errorMessage = null; permissionDenied = false;"
            class="text-red-500 hover:text-red-700 shrink-0"
        >
            <flux:icon name="x-mark" class="h-4 w-4" />
        </button>
    </div>

    {{-- Requesting permission indicator --}}
    <div
        x-show="requestingPermission"
        x-cloak
        class="mb-3 flex items-center gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg px-4 py-3"
    >
        <svg class="animate-spin h-5 w-5 text-amber-600 dark:text-amber-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <flux:text class="text-amber-700 dark:text-amber-300">
            {{ __('Requesting microphone access... Please allow in your browser.') }}
        </flux:text>
    </div>

    {{-- Audio preview --}}
    <div
        x-show="previewing"
        x-cloak
        class="mb-3 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4"
    >
        <div class="flex items-center gap-3 mb-3">
            <flux:icon name="microphone" class="h-5 w-5 text-zinc-500" />
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {{ __('Voice message preview') }}
            </span>
            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                (<span x-text="formatTime(previewDuration)"></span>)
            </span>
        </div>
        <audio
            x-ref="audioPreview"
            :src="previewUrl"
            controls
            class="w-full h-10 mb-3"
        ></audio>

        {{-- Silent audio warning --}}
        <div
            x-show="isAudioSilent"
            x-cloak
            class="flex items-start gap-2 mb-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg"
        >
            <svg class="h-5 w-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div class="flex-1">
                <p class="text-sm font-medium text-amber-800 dark:text-amber-200">{{ __('Audio appears to be silent') }}</p>
                <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">
                    The recording may not have captured audio from your microphone.
                    <template x-if="audioDevices.length > 1">
                        <span>Try selecting a different microphone using the settings button next to the record button.</span>
                    </template>
                    <template x-if="audioDevices.length <= 1">
                        <span>Please check your microphone is connected and working properly.</span>
                    </template>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 mb-3 text-xs text-amber-600 dark:text-amber-400">
            <flux:icon name="exclamation-triangle" class="h-4 w-4 shrink-0" />
            <span>{{ __('Audio files are automatically deleted after 30 days. Only the transcription will be kept.') }}</span>
        </div>
        <div class="flex gap-2 justify-end">
            <flux:button
                type="button"
                variant="ghost"
                x-on:click="discardRecording()"
            >
                <flux:icon name="trash" class="h-4 w-4 mr-1" />
                {{ __('Discard') }}
            </flux:button>
            <flux:button
                type="button"
                variant="primary"
                x-on:click="sendRecording()"
            >
                <flux:icon name="paper-airplane" class="h-4 w-4 mr-1" />
                {{ __('Send') }}
            </flux:button>
        </div>
    </div>

    {{-- Processing indicator --}}
    <div
        wire:loading.flex
        wire:target="processRecordedAudio"
        class="mb-3 items-center gap-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg px-4 py-3"
    >
        <svg class="animate-spin h-5 w-5 text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <flux:text class="text-blue-700 dark:text-blue-300">
            {{ __('Processing audio message...') }}
        </flux:text>
    </div>

    <form wire:submit="send" class="space-y-3" wire:loading.class="opacity-50 pointer-events-none" wire:target="processRecordedAudio" x-show="!previewing">
        <div class="flex gap-2 items-end">
            {{-- Attachment button - hidden during recording --}}
            <div class="shrink-0" x-show="!recording" x-cloak>
                <label class="cursor-pointer">
                    <input
                        type="file"
                        wire:model="attachments"
                        multiple
                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                        class="hidden"
                    />
                    <flux:button
                        type="button"
                        variant="ghost"
                        icon="paper-clip"
                        as="span"
                        wire:loading.attr="disabled"
                        wire:target="attachments"
                    />
                </label>
            </div>

            {{-- Message input area - fixed height container to prevent jumping --}}
            <div class="flex-1 min-w-0 min-h-[68px]">
                {{-- Recording indicator with audio level --}}
                <div
                    x-show="recording"
                    x-cloak
                    class="flex flex-col gap-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-4 py-3 min-h-[68px]"
                >
                    <div class="flex items-center gap-3">
                        <span class="relative flex h-3 w-3 shrink-0">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                        </span>
                        <span class="text-red-700 dark:text-red-300 font-medium">
                            {{ __('Recording') }} <span x-text="formatTime(recordingTime)"></span>
                        </span>

                        {{-- Audio level meter --}}
                        <div class="flex-1 flex items-center gap-1 h-4">
                            <template x-for="i in 10" :key="i">
                                <div
                                    class="w-1.5 rounded-full transition-all duration-75"
                                    :class="{
                                        'bg-green-500': audioLevel >= (i * 10) && i <= 6,
                                        'bg-yellow-500': audioLevel >= (i * 10) && i > 6 && i <= 8,
                                        'bg-red-500': audioLevel >= (i * 10) && i > 8,
                                        'bg-zinc-300 dark:bg-zinc-600': audioLevel < (i * 10)
                                    }"
                                    :style="{ height: audioLevel >= (i * 10) ? '100%' : '30%' }"
                                ></div>
                            </template>
                        </div>

                        <button
                            type="button"
                            x-on:click.stop="cancelRecording()"
                            class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-200 text-sm font-medium shrink-0"
                        >
                            {{ __('Cancel') }}
                        </button>
                    </div>

                    {{-- Silence warning --}}
                    <div
                        x-show="silenceWarningShown"
                        x-cloak
                        class="flex items-center gap-2 text-xs text-amber-700 dark:text-amber-400"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span>{{ __('No audio detected. Try selecting a different microphone below.') }}</span>
                    </div>
                </div>

                {{-- Normal text input --}}
                <div x-show="!recording">
                    <flux:textarea
                        wire:model="message"
                        rows="2"
                        placeholder="{{ __('Type your message...') }}"
                        wire:keydown.enter.prevent="send"
                        wire:keydown.shift.enter.stop
                        class="resize-none"
                    />
                    @error('message')
                        <flux:text class="text-sm text-red-500 mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>
            </div>

            {{-- Audio recording button with device selector --}}
            <div class="shrink-0 self-end pb-0.5 relative">
                <div class="flex items-center gap-1">
                    {{-- Device selector button (shown when multiple devices available and not recording) --}}
                    <div x-show="audioDevices.length > 1 && !recording" x-cloak class="relative">
                        <button
                            type="button"
                            x-on:click="showDeviceSelector = !showDeviceSelector"
                            class="p-1.5 rounded-full text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                            title="{{ __('Select microphone') }}"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </button>

                        {{-- Device selector dropdown --}}
                        <div
                            x-show="showDeviceSelector"
                            x-cloak
                            x-on:click.outside="showDeviceSelector = false"
                            class="absolute bottom-full right-0 mb-2 w-64 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-lg z-50"
                        >
                            <div class="p-2 border-b border-zinc-200 dark:border-zinc-700">
                                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Select Microphone') }}</span>
                            </div>
                            <div class="max-h-48 overflow-y-auto p-1">
                                <template x-for="device in audioDevices" :key="device.deviceId">
                                    <button
                                        type="button"
                                        x-on:click="selectDevice(device.deviceId)"
                                        class="w-full text-left px-3 py-2 text-sm rounded-md transition-colors flex items-center gap-2"
                                        :class="{
                                            'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300': selectedDeviceId === device.deviceId,
                                            'hover:bg-zinc-100 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300': selectedDeviceId !== device.deviceId
                                        }"
                                    >
                                        <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
                                            <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                                        </svg>
                                        <span class="truncate" x-text="getDeviceLabel(device)"></span>
                                        <svg x-show="selectedDeviceId === device.deviceId" class="w-4 h-4 shrink-0 ml-auto text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Main record button --}}
                    <button
                        type="button"
                        x-on:click="toggleRecording()"
                        x-bind:disabled="!hasMediaSupport || requestingPermission"
                        x-bind:class="{
                            'bg-red-500 hover:bg-red-600 text-white ring-4 ring-red-200 dark:ring-red-800 animate-pulse': recording,
                            'bg-amber-500 text-white cursor-wait': requestingPermission,
                            'bg-zinc-200 dark:bg-zinc-700 hover:bg-zinc-300 dark:hover:bg-zinc-600': !recording && !requestingPermission
                        }"
                        class="p-2.5 rounded-full text-zinc-700 dark:text-zinc-200 transition-all duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                        x-bind:title="recording ? 'Click to stop recording' : (requestingPermission ? 'Requesting microphone access...' : 'Click to record voice message')"
                    >
                        <svg x-show="!recording && !requestingPermission" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
                            <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                        </svg>
                        <svg x-show="requestingPermission" x-cloak class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg x-show="recording" x-cloak class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M6 6h12v12H6z"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Send button - hidden during recording --}}
            <div class="shrink-0 self-end pb-0.5" x-show="!recording" x-cloak>
                <flux:button
                    type="submit"
                    variant="primary"
                    icon="paper-airplane"
                    wire:loading.attr="disabled"
                    wire:target="send"
                >
                    <span wire:loading.remove wire:target="send">{{ __('Send') }}</span>
                    <span wire:loading wire:target="send">...</span>
                </flux:button>
            </div>
        </div>

        {{-- File upload progress --}}
        <div wire:loading wire:target="attachments" class="text-sm text-zinc-500">
            {{ __('Uploading files...') }}
        </div>

        {{-- Attachment errors --}}
        @error('attachments.*')
            <flux:callout variant="danger" class="text-sm">
                {{ $message }}
            </flux:callout>
        @enderror

        {{-- Audio errors --}}
        @error('audio')
            <flux:callout variant="danger" class="text-sm">
                {{ $message }}
            </flux:callout>
        @enderror
    </form>
</div>
