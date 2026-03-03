<?php

use App\Events\NewThreadNotification;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
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
        $uuid = request()->cookie('guest_chat_uuid');
        if ($uuid) {
            $session = GuestChatSession::where('uuid', $uuid)->whereIn('status', ['active', 'converted'])->first();
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

        // Parse name into first/last
        $nameParts = $this->name ? preg_split('/\s+/', trim($this->name), 2) : [];
        $firstName = $nameParts[0] ?? null;
        $lastName = $nameParts[1] ?? null;

        // Find or create a customer user for this email
        $customerUser = User::where('email', $this->email)->first();

        if (! $customerUser) {
            $customerUser = new User([
                'name' => $this->name ?: explode('@', $this->email)[0],
                'email' => $this->email,
                'password' => bcrypt(Str::random(32)),
            ]);
            $customerUser->type = 'customer';
            $customerUser->save();
        }

        // Find or create a Customer record
        $customer = Customer::where('email', $this->email)->first();

        if (! $customer) {
            $messengerSettings = MessengerSetting::getInstance();
            $defaultAssignee = $messengerSettings->default_assigned_to;
            $createdBy = $defaultAssignee ?? User::where('type', 'internal')->first()?->id;

            $customer = Customer::create([
                'user_id' => $customerUser->id,
                'first_name' => $firstName ?? explode('@', $this->email)[0],
                'last_name' => $lastName ?? '',
                'email' => $this->email,
                'source' => 'online',
                'status' => 'active',
                'created_by' => $createdBy,
                'assigned_to' => $defaultAssignee,
            ]);

            // Create a new lead deal
            Deal::create([
                'customer_id' => $customer->id,
                'title' => 'Painting Estimate - '.($this->name ?: $this->email),
                'stage' => 'lead',
                'lead_source' => 'website',
                'created_by' => $createdBy,
            ]);
        }

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_WEBSITE_LEAD,
            'subject' => 'Support: '.($this->name ?: $this->email),
            'customer_id' => $customer->id,
            'last_message_at' => now(),
        ]);

        // Add customer user as participant so they appear in communications hub
        $thread->addParticipant($customerUser);

        $internalUsers = User::where('type', 'internal')->get()->filter(function (User $user) {
            if ($user->roles->isEmpty()) {
                return true;
            }

            return $user->can('chat.view_website_leads');
        });
        foreach ($internalUsers as $user) {
            $thread->addParticipant($user);
        }

        // Send the welcome greeting from messenger settings as the first chat message
        $greeting = MessengerSetting::getInstance()->widget_greeting_logged_out;
        if ($greeting) {
            $assignee = $customer->assigned_to ? User::find($customer->assigned_to) : null;
            $greetingUser = $assignee ?? $internalUsers->first();
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
            'user_id' => $customerUser->id,
            'status' => 'converted',
            'started_at' => now(),
            'metadata' => [
                'page_url' => request()->header('Referer'),
                'referrer' => request()->header('Referer'),
            ],
        ]);

        $this->sessionId = $session->id;

        cookie()->queue('guest_chat_uuid', $uuid, 60 * 24 * 30);

        // Broadcast new thread to all internal users via Echo
        try {
            broadcast(new NewThreadNotification($thread));
        } catch (\Throwable) {
            // Broadcasting is non-critical
        }

        // Also send a database/email notification
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
            ['guest_email' => $session->email, 'guest_name' => $session->name],
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
                ['guest_email' => $session->email, 'guest_name' => $session->name],
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

    #[Computed]
    public function isOnline(): bool
    {
        return CompanySetting::getInstance()->isWithinBusinessHours();
    }
};
?>

<div class="fixed bottom-4 right-4 z-50">
    {{-- Chat Panel --}}
    @if($isOpen)
        <div class="w-80 sm:w-96 max-h-[500px] bg-white rounded-2xl shadow-2xl border border-zinc-200 flex flex-col overflow-hidden">
            {{-- Header --}}
            <div class="bg-emerald-600 text-white px-4 py-3 flex items-center justify-between shrink-0 rounded-t-2xl">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold">{{ __('Chat with us') }}</p>
                        <p class="text-xs text-emerald-100">
                            @if($this->isOnline)
                                {{ __('We\'re online!') }}
                            @else
                                {{ __('Leave a message') }}
                            @endif
                        </p>
                    </div>
                </div>
                <button wire:click="$set('isOpen', false)" class="text-white/80 hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            @if(! $sessionId)
                {{-- Email/Name Form --}}
                <div class="p-4">
                    <p class="text-sm text-zinc-600 mb-3">
                        @if($this->isOnline)
                            {{ __('We\'re here to help! Enter your details to start chatting.') }}
                        @else
                            {{ __('We\'re currently offline. Leave a message and we\'ll reply soon!') }}
                        @endif
                    </p>
                    <form wire:submit="startChat" class="space-y-3">
                        <div>
                            <label for="guest-chat-email" class="block text-xs font-medium text-zinc-700 mb-1">{{ __('Email') }} *</label>
                            <input wire:model="email" id="guest-chat-email" type="email" required class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 bg-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('your@email.com') }}" />
                            @error('email') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="guest-chat-name" class="block text-xs font-medium text-zinc-700 mb-1">{{ __('Name') }}</label>
                            <input wire:model="name" id="guest-chat-name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 bg-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('Your name (optional)') }}" />
                        </div>
                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium py-2 rounded-lg transition-colors">
                            <span wire:loading.remove wire:target="startChat">{{ __('Start Chat') }}</span>
                            <span wire:loading wire:target="startChat">{{ __('Starting...') }}</span>
                        </button>
                    </form>
                </div>
            @else
                {{-- Messages Area --}}
                <div
                    x-data="{
                        scroll() { this.$el.scrollTop = this.$el.scrollHeight },
                        init() {
                            this.$nextTick(() => this.scroll());
                            new MutationObserver(() => this.scroll()).observe(this.$el, { childList: true, subtree: true });
                        }
                    }"
                    class="flex-1 overflow-y-auto p-4 space-y-3 min-h-[200px] max-h-[320px]"
                    wire:poll.5s.visible="$refresh"
                >
                    @forelse($this->chatMessages as $message)
                        <div wire:key="msg-{{ $message->id }}" class="flex {{ $message->user_id ? 'justify-start' : 'justify-end' }}">
                            <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm {{ $message->user_id ? 'bg-zinc-100 text-zinc-800' : 'bg-emerald-600 text-white' }}">
                                @if($message->user_id)
                                    <p class="text-xs font-medium {{ $message->user_id ? 'text-zinc-500' : 'text-emerald-100' }} mb-0.5">{{ $message->user?->name ?? __('Team') }}</p>
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
                        <div class="text-center text-sm text-zinc-400 py-8">
                            <svg class="w-8 h-8 mx-auto mb-2 text-zinc-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                            <p>{{ __('Send a message to get started!') }}</p>
                        </div>
                    @endforelse
                </div>

                {{-- Message Composer --}}
                <div class="shrink-0" x-data="{
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
                        <div class="border-t border-zinc-200 p-3 space-y-2">
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 bg-red-500 rounded-full animate-pulse"></div>
                                <span class="text-sm font-medium text-red-600" x-text="formatTime(recordingTime)"></span>
                                {{-- VU Meter --}}
                                <div class="flex-1 flex gap-0.5 items-center h-4">
                                    <template x-for="i in 10" :key="i">
                                        <div class="flex-1 rounded-sm transition-all duration-75"
                                             :class="audioLevel >= i * 10 ? (i <= 7 ? 'bg-emerald-500 h-full' : 'bg-red-500 h-full') : 'bg-zinc-300 h-1/2'"
                                        ></div>
                                    </template>
                                </div>
                                <button @click="stopRecording()" type="button" class="text-xs font-medium text-zinc-600 hover:text-zinc-900 px-2 py-1 rounded border border-zinc-300">
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
                        <div class="border-t border-zinc-200 p-3 space-y-2">
                            <audio controls :src="previewUrl" class="w-full h-8"></audio>
                            <div class="flex gap-2">
                                <button @click="discardPreview()" type="button" class="flex-1 text-xs font-medium text-zinc-600 hover:text-zinc-900 px-3 py-1.5 rounded border border-zinc-300">
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
                        <div class="border-t border-zinc-200 p-3 flex items-center gap-2 text-sm text-zinc-500">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            {{ __('Processing audio...') }}
                        </div>
                    @endif

                    {{-- Normal Input (hidden when recording/previewing/processing) --}}
                    <template x-if="!recording && !previewing">
                        <div class="border-t border-zinc-200 p-3" @if($isProcessingAudio) style="display:none" @endif>
                            <form wire:submit="sendMessage" class="flex gap-2">
                                <input wire:model="newMessage" type="text" class="flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 bg-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('Type a message...') }}" />
                                {{-- Microphone button --}}
                                <button x-show="hasMediaSupport" @click="startRecording()" type="button" class="text-zinc-400 hover:text-emerald-600 rounded-lg px-2 py-2 transition-colors shrink-0" :class="requestingPermission && 'animate-pulse'">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z" /></svg>
                                </button>
                                {{-- Send button --}}
                                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-2 transition-colors shrink-0">
                                    <svg wire:loading.remove wire:target="sendMessage" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                                    <svg wire:loading wire:target="sendMessage" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                </button>
                            </form>
                            @error('newMessage') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </template>

                    {{-- Permission denied message --}}
                    <template x-if="permissionDenied">
                        <div class="px-3 pb-2">
                            <p class="text-xs text-red-500">{{ __('Microphone access denied. Check browser permissions.') }}</p>
                        </div>
                    </template>
                </div>
            @endif
        </div>
    @else
        {{-- Collapsed Chat Button --}}
        <button
            wire:click="$set('isOpen', true)"
            class="group relative inline-flex items-center justify-center w-14 h-14 bg-emerald-600 hover:bg-emerald-700 text-white rounded-full shadow-lg transition-all hover:scale-105"
            aria-label="{{ __('Chat with us') }}"
        >
            @if($this->isOnline)
                <span class="absolute top-0 right-0 w-3.5 h-3.5 bg-green-400 border-2 border-white rounded-full"></span>
            @endif
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
            <span class="absolute bottom-full right-0 mb-2 px-2 py-1 text-xs font-medium text-white bg-zinc-800 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none">
                {{ __('Chat with us') }}
            </span>
        </button>
    @endif
</div>
