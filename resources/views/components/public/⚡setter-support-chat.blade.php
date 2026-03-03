<?php

use App\Events\NewThreadNotification;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\GuestChatSession;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Notifications\NewSupportChatNotification;
use App\Services\AudioMessageService;
use App\Services\ChatService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $isOpen = false;
    public string $email = '';
    public string $name = '';
    public string $newMessage = '';
    public ?int $sessionId = null;
    public bool $isRecording = false;
    public bool $isProcessingAudio = false;

    public function mount(): void
    {
        $uuid = request()->cookie('setter_chat_uuid');
        if ($uuid) {
            $session = GuestChatSession::where('uuid', $uuid)
                ->whereIn('status', ['active', 'converted'])
                ->first();
            if ($session) {
                $this->sessionId = $session->id;
            }
        }
    }

    public function startChat(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        // Find or create a User for this setter (type = null, not a customer)
        $setterUser = User::where('email', $this->email)->first();

        if (! $setterUser) {
            $setterUser = new User([
                'name' => $this->name ?: explode('@', $this->email)[0],
                'email' => $this->email,
                'password' => bcrypt(Str::random(32)),
            ]);
            $setterUser->type = null;
            $setterUser->save();
        }

        // Create a support thread with setter_support source
        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SETTER_SUPPORT,
            'subject' => 'Setter Support: ' . ($this->name ?: $this->email),
            'last_message_at' => now(),
        ]);

        // Add setter as participant
        $thread->addParticipant($setterUser);

        // Only add internal users who have permission to view setter support chats
        $internalUsers = User::where('type', 'internal')->get()->filter(function (User $user) {
            if ($user->roles->isEmpty()) {
                return true;
            }

            return $user->can('chat.view_setter_support');
        });

        foreach ($internalUsers as $user) {
            $thread->addParticipant($user);
        }

        // Send a welcome greeting
        $greeting = MessengerSetting::getInstance()->widget_greeting_logged_out;
        if ($greeting) {
            $greetingUser = $internalUsers->first();
            if ($greetingUser) {
                app(ChatService::class)->sendMessage($thread, $greetingUser, $greeting);
            }
        }

        $uuid = Str::uuid()->toString();
        $session = GuestChatSession::create([
            'uuid' => $uuid,
            'email' => $this->email,
            'name' => $this->name ?: null,
            'ip_address' => request()->ip(),
            'city' => null,
            'user_agent' => request()->userAgent(),
            'chat_thread_id' => $thread->id,
            'user_id' => $setterUser->id,
            'status' => 'active',
            'started_at' => now(),
            'metadata' => [
                'page_url' => request()->header('Referer'),
                'referrer' => request()->header('Referer'),
                'source' => 'setter_support',
            ],
        ]);

        $this->sessionId = $session->id;

        cookie()->queue('setter_chat_uuid', $uuid, 60 * 24 * 30);

        try {
            broadcast(new NewThreadNotification($thread));
        } catch (\Throwable) {
        }

        // Only notify internal users with permission
        foreach ($internalUsers as $user) {
            $user->notify(new NewSupportChatNotification($session));
        }
    }

    public function sendMessage(): void
    {
        $this->validate(['newMessage' => ['required', 'string', 'max:5000']]);

        $session = GuestChatSession::find($this->sessionId);
        if (! $session || ! $session->thread) {
            return;
        }

        app(ChatService::class)->sendGuestMessage(
            $session->thread,
            $this->newMessage,
            ['guest_email' => $session->email, 'guest_name' => $session->name, 'source' => 'setter_support'],
        );

        $this->newMessage = '';
    }

    public function processRecordedAudio(string $audioBase64, string $mimeType = 'audio/webm'): void
    {
        $this->isProcessingAudio = true;

        try {
            $session = GuestChatSession::find($this->sessionId);
            if (! $session || ! $session->thread) {
                return;
            }

            $audioService = app(AudioMessageService::class);
            $audioData = $audioService->processBase64Audio($audioBase64, $mimeType);

            app(ChatService::class)->sendGuestAudioMessage(
                $session->thread,
                $audioData,
                ['guest_email' => $session->email, 'guest_name' => $session->name, 'source' => 'setter_support'],
            );
        } catch (\Exception $e) {
            $this->addError('audio', __('Failed to process audio: :error', ['error' => $e->getMessage()]));
        } finally {
            $this->isProcessingAudio = false;
            $this->isRecording = false;
        }
    }

    #[Computed]
    public function chatMessages(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->sessionId) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $session = GuestChatSession::find($this->sessionId);
        if (! $session) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return ChatMessage::where('thread_id', $session->chat_thread_id)
            ->where('is_internal', false)
            ->orderBy('created_at', 'asc')
            ->get();
    }
};
?>

<div>
    {{-- Chat toggle button --}}
    @if (! $isOpen)
        <button
            wire:click="$set('isOpen', true)"
            class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:border-emerald-400 dark:hover:border-emerald-500 transition-colors text-left"
        >
            <div class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center shrink-0">
                <flux:icon name="chat-bubble-left-right" class="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
            </div>
            <div>
                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Setter Support') }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Need help? Chat with us') }}</p>
            </div>
        </button>
    @endif

    {{-- Chat panel --}}
    @if ($isOpen)
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            {{-- Header --}}
            <div class="bg-emerald-600 text-white px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:icon name="chat-bubble-left-right" class="w-5 h-5" />
                    <span class="font-semibold text-sm">{{ __('Setter Support') }}</span>
                </div>
                <button wire:click="$set('isOpen', false)" class="text-white/80 hover:text-white">
                    <flux:icon name="x-mark" variant="mini" class="w-5 h-5" />
                </button>
            </div>

            @if (! $sessionId)
                {{-- Email form to start chat --}}
                <form wire:submit="startChat" class="p-4 space-y-3">
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">
                        {{ __('Enter your info to start a support chat.') }}
                    </flux:text>
                    <flux:input wire:model="name" :placeholder="__('Your name')" size="sm" />
                    <flux:input wire:model="email" type="email" :placeholder="__('Your email')" size="sm" required />
                    <flux:button type="submit" variant="primary" size="sm" class="w-full">
                        <span wire:loading.remove wire:target="startChat">{{ __('Start Chat') }}</span>
                        <span wire:loading wire:target="startChat">{{ __('Starting...') }}</span>
                    </flux:button>
                </form>
            @else
                {{-- Messages --}}
                <div
                    x-data="{
                        scroll() { this.$el.scrollTop = this.$el.scrollHeight },
                        init() {
                            this.$nextTick(() => this.scroll());
                            new MutationObserver(() => this.scroll()).observe(this.$el, { childList: true, subtree: true });
                        }
                    }"
                    class="h-48 overflow-y-auto p-3 space-y-2"
                    wire:poll.5s.visible="$refresh"
                >
                    @forelse ($this->chatMessages as $message)
                        <div wire:key="msg-{{ $message->id }}" class="flex {{ $message->user_id ? 'justify-start' : 'justify-end' }}">
                            <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm
                                {{ $message->user_id
                                    ? 'bg-zinc-100 dark:bg-zinc-700 text-zinc-900 dark:text-white'
                                    : 'bg-emerald-600 text-white' }}">
                                @if ($message->user_id)
                                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">{{ $message->user?->name ?? __('Team') }}</p>
                                @endif
                                @if ($message->type === 'audio' && ($message->metadata['audio_url'] ?? null))
                                    <div class="space-y-1">
                                        <audio controls preload="metadata" class="max-w-full h-8">
                                            <source src="{{ $message->metadata['audio_url'] }}" type="{{ $message->metadata['audio_mime_type'] ?? 'audio/webm' }}">
                                            <source src="{{ $message->metadata['audio_url'] }}" type="audio/mp4">
                                        </audio>
                                        @if ($message->content)
                                            <p class="text-xs italic opacity-75">{{ $message->content }}</p>
                                        @elseif (($message->metadata['transcription_status'] ?? '') === 'pending')
                                            <p class="text-xs italic opacity-75 animate-pulse">{{ __('Transcribing...') }}</p>
                                        @endif
                                    </div>
                                @else
                                    <p>{{ $message->content }}</p>
                                @endif
                                <p class="text-[10px] mt-1 {{ $message->user_id ? 'text-zinc-400' : 'text-emerald-200' }}">{{ $message->created_at->format('g:i A') }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-sm text-zinc-400 py-4">
                            {{ __('Send a message to get started.') }}
                        </div>
                    @endforelse
                </div>

                {{-- Input --}}
                <div x-data="{
                    recording: false,
                    recordingTime: 0,
                    mediaRecorder: null,
                    audioChunks: [],
                    timer: null,
                    stream: null,
                    hasMediaSupport: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder),
                    permissionDenied: false,
                    requestingPermission: false,
                    previewing: false,
                    previewUrl: null,
                    previewBlob: null,
                    previewDuration: 0,
                    currentMimeType: null,
                    audioContext: null,
                    analyser: null,
                    audioLevel: 0,
                    peakLevel: 0,
                    levelAnimationFrame: null,

                    getSupportedMimeType() {
                        const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
                        for (const type of types) {
                            if (MediaRecorder.isTypeSupported(type)) return type;
                        }
                        return '';
                    },

                    async startRecording() {
                        if (this.recording || !this.hasMediaSupport || this.requestingPermission) return;
                        this.requestingPermission = true;
                        this.permissionDenied = false;
                        try {
                            this.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
                            this.requestingPermission = false;
                            this.startLevelMonitoring();
                            this.currentMimeType = this.getSupportedMimeType();
                            this.mediaRecorder = new MediaRecorder(this.stream, { mimeType: this.currentMimeType });
                            this.audioChunks = [];
                            this.mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) this.audioChunks.push(e.data); };
                            this.mediaRecorder.onstop = () => {
                                this.stopLevelMonitoring();
                                this.cleanupStream();
                                if (this.audioChunks.length > 0) {
                                    const blob = new Blob(this.audioChunks, { type: this.currentMimeType });
                                    this.previewBlob = blob;
                                    this.previewUrl = URL.createObjectURL(blob);
                                    this.previewDuration = this.recordingTime;
                                    this.previewing = true;
                                }
                            };
                            this.mediaRecorder.start(100);
                            this.recording = true;
                            this.recordingTime = 0;
                            this.timer = setInterval(() => this.recordingTime++, 1000);
                        } catch (err) {
                            this.requestingPermission = false;
                            if (err.name === 'NotAllowedError') { this.permissionDenied = true; }
                        }
                    },

                    stopRecording() {
                        if (!this.recording) return;
                        this.recording = false;
                        clearInterval(this.timer);
                        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                            this.mediaRecorder.stop();
                        }
                    },

                    cancelRecording() {
                        this.recording = false;
                        clearInterval(this.timer);
                        this.audioChunks = [];
                        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                            this.mediaRecorder.stop();
                        }
                        this.cleanupStream();
                        this.stopLevelMonitoring();
                        this.previewing = false;
                        if (this.previewUrl) { URL.revokeObjectURL(this.previewUrl); this.previewUrl = null; }
                        this.previewBlob = null;
                    },

                    discardPreview() {
                        this.previewing = false;
                        if (this.previewUrl) { URL.revokeObjectURL(this.previewUrl); this.previewUrl = null; }
                        this.previewBlob = null;
                    },

                    async sendAudio() {
                        if (!this.previewBlob) return;
                        this.previewing = false;
                        const reader = new FileReader();
                        reader.onloadend = () => {
                            const base64 = reader.result.split(',')[1];
                            $wire.processRecordedAudio(base64, this.currentMimeType);
                        };
                        reader.readAsDataURL(this.previewBlob);
                        if (this.previewUrl) { URL.revokeObjectURL(this.previewUrl); this.previewUrl = null; }
                        this.previewBlob = null;
                    },

                    startLevelMonitoring() {
                        try {
                            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                            this.analyser = this.audioContext.createAnalyser();
                            this.analyser.fftSize = 2048;
                            this.analyser.smoothingTimeConstant = 0.3;
                            const source = this.audioContext.createMediaStreamSource(this.stream);
                            source.connect(this.analyser);
                            const dataArray = new Uint8Array(this.analyser.fftSize);
                            const updateLevel = () => {
                                if (!this.recording) return;
                                this.analyser.getByteTimeDomainData(dataArray);
                                let sum = 0, peak = 0;
                                for (let i = 0; i < dataArray.length; i++) {
                                    const v = Math.abs(dataArray[i] - 128);
                                    sum += v * v;
                                    peak = Math.max(peak, v);
                                }
                                this.audioLevel = Math.min(100, Math.round(Math.sqrt(sum / dataArray.length) * 2));
                                this.peakLevel = Math.max(this.peakLevel, peak);
                                this.levelAnimationFrame = requestAnimationFrame(updateLevel);
                            };
                            updateLevel();
                        } catch (e) { /* VU meter is optional */ }
                    },

                    stopLevelMonitoring() {
                        if (this.levelAnimationFrame) { cancelAnimationFrame(this.levelAnimationFrame); this.levelAnimationFrame = null; }
                        if (this.audioContext) { this.audioContext.close().catch(() => {}); this.audioContext = null; }
                        this.audioLevel = 0;
                    },

                    cleanupStream() {
                        if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                    },

                    formatTime(seconds) {
                        const m = Math.floor(seconds / 60);
                        const s = seconds % 60;
                        return m + ':' + (s < 10 ? '0' : '') + s;
                    }
                }" @keydown.escape.window="if (recording) cancelRecording(); else if (previewing) discardPreview();">

                    {{-- Recording State --}}
                    <template x-if="recording">
                        <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 space-y-2">
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 bg-red-500 rounded-full animate-pulse"></div>
                                <span class="text-sm font-medium text-red-600 dark:text-red-400" x-text="formatTime(recordingTime)"></span>
                                {{-- VU Meter --}}
                                <div class="flex-1 flex gap-0.5 items-center h-4">
                                    <template x-for="i in 10" :key="i">
                                        <div class="flex-1 rounded-sm transition-all duration-75"
                                             :class="audioLevel >= i * 10 ? (i <= 7 ? 'bg-emerald-500 h-full' : 'bg-red-500 h-full') : 'bg-zinc-300 dark:bg-zinc-600 h-1/2'"
                                        ></div>
                                    </template>
                                </div>
                                <button @click="stopRecording()" type="button" class="text-xs font-medium text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white px-2 py-1 rounded border border-zinc-300 dark:border-zinc-600">
                                    {{ __('Stop') }}
                                </button>
                                <button @click="cancelRecording()" type="button" class="text-xs text-red-500 hover:text-red-700 px-1">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </div>
                    </template>

                    {{-- Preview State --}}
                    <template x-if="previewing">
                        <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 space-y-2">
                            <audio controls :src="previewUrl" class="w-full h-8"></audio>
                            <div class="flex gap-2">
                                <button @click="discardPreview()" type="button" class="flex-1 text-xs font-medium text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 px-3 py-1.5 rounded border border-zinc-300 dark:border-zinc-600">
                                    {{ __('Discard') }}
                                </button>
                                <button @click="sendAudio()" type="button" class="flex-1 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-700 px-3 py-1.5 rounded">
                                    {{ __('Send') }}
                                </button>
                            </div>
                        </div>
                    </template>

                    {{-- Processing State --}}
                    @if ($isProcessingAudio)
                        <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 flex items-center gap-2 text-sm text-zinc-500">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            {{ __('Processing audio...') }}
                        </div>
                    @endif

                    {{-- Normal Input (hidden when recording/previewing/processing) --}}
                    <template x-if="!recording && !previewing">
                        <form wire:submit="sendMessage" class="border-t border-zinc-200 dark:border-zinc-700 p-3 flex gap-2" @if($isProcessingAudio) style="display:none" @endif>
                            <input wire:model="newMessage" type="text" class="flex-1 rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm text-zinc-900 dark:text-white bg-white dark:bg-zinc-800 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('Type a message...') }}" />
                            {{-- Microphone button --}}
                            <button x-show="hasMediaSupport" @click="startRecording()" type="button" class="text-zinc-400 hover:text-emerald-600 dark:hover:text-emerald-400 rounded-lg px-2 py-2 transition-colors shrink-0" :class="requestingPermission && 'animate-pulse'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z" /></svg>
                            </button>
                            {{-- Send button --}}
                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-2 transition-colors shrink-0">
                                <svg wire:loading.remove wire:target="sendMessage" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                                <svg wire:loading wire:target="sendMessage" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            </button>
                        </form>
                    </template>

                    {{-- Permission denied message --}}
                    <template x-if="permissionDenied">
                        <div class="px-3 pb-2">
                            <p class="text-xs text-red-500">{{ __('Microphone access denied. Check browser permissions.') }}</p>
                        </div>
                    </template>
                </div>
                @error('newMessage') <p class="text-xs text-red-500 px-3 pb-2">{{ $message }}</p> @enderror
            @endif
        </div>
    @endif
</div>
