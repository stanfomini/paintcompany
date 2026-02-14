<?php

use App\Models\ChatThread;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('components.layouts.customer', ['title' => 'Conversation'])] class extends Component {
    public ChatThread $thread;

    #[Computed]
    public function messages()
    {
        return app(ChatService::class)->getMessagesForThread($this->thread, auth('customer')->user());
    }

    public function mount(ChatThread $thread): void
    {
        $user = auth('customer')->user();

        // Authorization: thread must be deal or direct type
        if (! in_array($thread->type, [ChatThread::TYPE_DEAL, ChatThread::TYPE_DIRECT], true)) {
            throw new NotFoundHttpException('Thread not found.');
        }

        // Authorization: customer must be a participant
        if (! $thread->hasParticipant($user)) {
            throw new NotFoundHttpException('Thread not found.');
        }

        $this->thread = $thread;

        // Mark messages as read on view
        app(ChatService::class)->markThreadAsRead($thread, $user);
    }

    public function getListeners(): array
    {
        return [
            "echo-private:chat.thread.{$this->thread->id},.message.sent" => 'onNewMessage',
            "echo-private:chat.thread.{$this->thread->id},.message.read" => '$refresh',
        ];
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        $user = auth('customer')->user();
        app(ChatService::class)->markThreadAsRead($this->thread, $user);
    }

    public function onNewMessage(): void
    {
        unset($this->messages);
        app(ChatService::class)->markThreadAsRead($this->thread, auth('customer')->user());
    }
}; ?>

<div
    x-data="{ pollTimer: null }"
    x-init="
        if (window.Echo?.connector?.pusher) {
            window.Echo.connector.pusher.connection.bind('disconnected', () => {
                this.pollTimer = setInterval(() => $wire.$refresh(), 30000);
            });
            window.Echo.connector.pusher.connection.bind('connected', () => {
                if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
            });
        } else {
            this.pollTimer = setInterval(() => $wire.$refresh(), 15000);
        }
    "
    x-on:beforeunmount.window="if (this.pollTimer) clearInterval(this.pollTimer)"
>
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <flux:button variant="ghost" href="{{ route('customer.chat.index') }}" icon="arrow-left">
                {{ __('Back to Messages') }}
            </flux:button>
        </div>

        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
            <!-- Thread Header -->
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                <div class="flex items-center gap-3">
                    @php $isDirect = $thread->type === \App\Models\ChatThread::TYPE_DIRECT; @endphp
                    <div class="w-10 h-10 rounded-full {{ $isDirect ? 'bg-teal-100 dark:bg-teal-900/30' : 'bg-zinc-200 dark:bg-zinc-700' }} flex items-center justify-center">
                        <flux:icon name="{{ $isDirect ? 'user' : 'chat-bubble-left-right' }}" class="w-5 h-5 {{ $isDirect ? 'text-teal-500' : 'text-zinc-500' }}" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ $thread->getDisplayNameFor(auth('customer')->user()) }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">
                            {{ $isDirect ? __('Direct Message') : __('Project Conversation') }}
                        </flux:text>
                    </div>
                </div>
            </div>

            <!-- Messages Container -->
            <div
                class="h-[500px] overflow-y-auto p-4 space-y-4 bg-white dark:bg-zinc-900"
                x-data="{
                    scrollToBottom() {
                        this.$el.scrollTop = this.$el.scrollHeight;
                    }
                }"
                x-init="
                    scrollToBottom();
                    const observer = new MutationObserver(() => $nextTick(() => scrollToBottom()));
                    observer.observe($el, { childList: true, subtree: true });
                "
                @message-sent.window="$nextTick(() => scrollToBottom())"
            >
                @forelse ($this->messages as $message)
                    @php
                        $isOwnMessage = $message->user_id === auth('customer')->id();
                        $isSystem = $message->type === 'system';
                    @endphp

                    <div wire:key="message-{{ $message->id }}" class="{{ $isOwnMessage ? 'flex justify-end' : 'flex justify-start' }}">
                        @if ($isSystem)
                            <div class="w-full text-center py-2">
                                <flux:text class="text-xs text-zinc-400 italic">{{ $message->content }}</flux:text>
                            </div>
                        @else
                            <div class="flex items-start gap-3 max-w-[80%] {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                <!-- Avatar -->
                                <div class="flex-shrink-0">
                                    @if ($message->user)
                                        <flux:avatar size="sm" :initials="$message->user->initials()" class="bg-zinc-200 dark:bg-zinc-700" />
                                    @else
                                        <div class="w-8 h-8 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                            <flux:icon name="user" class="w-4 h-4 text-zinc-500" />
                                        </div>
                                    @endif
                                </div>

                                <!-- Message Content -->
                                <div class="{{ $isOwnMessage ? 'text-right' : 'text-left' }}">
                                    <div class="flex items-center gap-2 mb-1 {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                        <flux:text class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                            {{ $message->user?->name ?? __('Unknown') }}
                                        </flux:text>
                                        <flux:text class="text-xs text-zinc-400">
                                            {{ $message->created_at->format('M j, g:i A') }}
                                        </flux:text>
                                        @if ($message->edited_at)
                                            <flux:text class="text-xs text-zinc-400 italic">{{ __('(edited)') }}</flux:text>
                                        @endif
                                    </div>
                                    <div class="rounded-lg p-3 {{ $isOwnMessage ? 'bg-blue-50 text-zinc-800 dark:bg-blue-600 dark:text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' }}">
                                        @if ($message->isAudio())
                                            {{-- Audio message --}}
                                            @php
                                                $audioUrl = $message->metadata['audio_url'] ?? '';
                                                $audioMime = $message->metadata['audio_mime_type'] ?? null;
                                                $isM4a = $audioMime === 'audio/mp4' || str_ends_with($audioUrl, '.m4a');
                                            @endphp
                                            <div class="space-y-2">
                                                {{-- Audio player with multiple source formats for cross-browser support --}}
                                                <div
                                                    x-data="{
                                                        audioError: false,
                                                        audioLoaded: false,
                                                        handleError(e) {
                                                            console.error('[AudioPlayer] Error loading audio:', e.target.error);
                                                            this.audioError = true;
                                                        },
                                                        handleLoaded() {
                                                            this.audioLoaded = true;
                                                        }
                                                    }"
                                                    class="relative"
                                                >
                                                    <audio
                                                        controls
                                                        class="w-full max-w-[280px]"
                                                        preload="metadata"
                                                        x-on:error="handleError($event)"
                                                        x-on:loadedmetadata="handleLoaded()"
                                                        x-show="!audioError"
                                                    >
                                                        @if ($isM4a)
                                                            <source src="{{ $audioUrl }}" type="audio/mp4">
                                                        @else
                                                            <source src="{{ $audioUrl }}" type="audio/webm; codecs=opus">
                                                            <source src="{{ $audioUrl }}" type="audio/webm">
                                                        @endif
                                                        <source src="{{ $audioUrl }}" type="audio/mp4">
                                                        <source src="{{ $audioUrl }}">
                                                        {{ __('Your browser does not support audio playback.') }}
                                                    </audio>

                                                    {{-- Error state --}}
                                                    <div x-show="audioError" x-cloak class="flex items-center gap-2 text-xs {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                        <span>{{ __('Audio unavailable') }}</span>
                                                        <a href="{{ $message->metadata['audio_url'] ?? '' }}" download class="underline hover:no-underline">{{ __('Download') }}</a>
                                                    </div>
                                                </div>

                                                {{-- Duration indicator --}}
                                                @if (!empty($message->metadata['audio_duration']))
                                                    <div class="text-xs {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}">
                                                        {{ gmdate('i:s', (int) $message->metadata['audio_duration']) }}
                                                    </div>
                                                @endif

                                                {{-- Transcription --}}
                                                @if (!empty($message->metadata['transcription']))
                                                    <div class="pt-1 border-t {{ $isOwnMessage ? 'border-blue-200 dark:border-blue-500' : 'border-zinc-200 dark:border-zinc-700' }}">
                                                        <flux:text class="text-sm italic {{ $isOwnMessage ? 'text-blue-500 dark:text-blue-200' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                            "{{ $message->metadata['transcription'] }}"
                                                        </flux:text>
                                                    </div>
                                                @elseif (($message->metadata['transcription_status'] ?? '') === 'pending')
                                                    <div class="text-xs {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                        <span class="animate-pulse">{{ __('Transcribing...') }}</span>
                                                    </div>
                                                @elseif (($message->metadata['transcription_status'] ?? '') === 'failed')
                                                    <div class="text-xs {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                        {{ __('Transcription unavailable') }}
                                                    </div>
                                                @endif

                                                {{-- Retention notice --}}
                                                <div class="text-xs {{ $isOwnMessage ? 'text-blue-400/60 dark:text-blue-200/60' : 'text-zinc-400 dark:text-zinc-500' }}">
                                                    <flux:icon name="clock" class="inline h-3 w-3 mr-0.5" />
                                                    {{ __('Audio files are deleted after 30 days') }}
                                                </div>
                                            </div>
                                        @else
                                            <flux:text class="whitespace-pre-wrap break-words">{{ $message->content }}</flux:text>
                                        @endif
                                    </div>

                                    <!-- Attachments -->
                                    @if ($message->attachments->isNotEmpty())
                                        <div class="mt-2 space-y-1">
                                            @foreach ($message->attachments as $attachment)
                                                <div class="flex items-center gap-2 text-sm {{ $isOwnMessage ? 'justify-end' : 'justify-start' }}">
                                                    <flux:icon name="paper-clip" class="w-4 h-4 text-zinc-400" />
                                                    <flux:text class="text-zinc-500">{{ $attachment->original_filename }}</flux:text>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-16">
                        <flux:icon name="chat-bubble-left" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:text class="mt-4 text-zinc-500">{{ __('No messages yet. Start the conversation!') }}</flux:text>
                    </div>
                @endforelse
            </div>

            <!-- Message Composer -->
            <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4">
                <livewire:customer.chat.message-composer :thread="$thread" :key="'composer-'.$thread->id" />
            </div>
        </div>
    </div>
</div>
