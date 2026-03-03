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
            "echo-private:chat.thread.{$this->thread->id},.message.translated" => '$refresh',
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
                @foreach ($this->messages as $message)
                    <div
                        wire:key="message-{{ $message->id }}"
                        @class([
                            'flex gap-3',
                            'flex-row-reverse' => $message->user_id === auth('customer')->id(),
                        ])
                    >
                        {{-- Avatar --}}
                        @if ($message->user_id !== auth('customer')->id())
                            <div class="shrink-0">
                                <flux:tooltip content="{{ $message->user?->name ?? __('Unknown') }}">
                                    <flux:avatar
                                        name="{{ $message->user?->name ?? __('Unknown') }}"
                                        size="sm"
                                    />
                                </flux:tooltip>
                            </div>
                        @endif

                        {{-- Message bubble --}}
                        <div @class([
                            'max-w-[70%]',
                            'items-end' => $message->user_id === auth('customer')->id(),
                            'items-start' => $message->user_id !== auth('customer')->id(),
                        ])>
                            {{-- Sender name (for others' messages) --}}
                            @if ($message->user_id !== auth('customer')->id())
                                <div class="flex items-center gap-2 mb-1">
                                    <flux:text class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        {{ $message->user?->name ?? __('Unknown') }}
                                    </flux:text>
                                    @if ($message->user?->isInternal())
                                        <flux:badge size="sm" color="blue">{{ __('Staff') }}</flux:badge>
                                    @elseif ($message->user?->isAgent())
                                        <flux:badge size="sm" color="green">{{ __('Agent') }}</flux:badge>
                                    @endif
                                </div>
                            @endif

                            {{-- Reply reference --}}
                            @if ($message->parent)
                                <div class="mb-1 text-xs text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800 rounded px-2 py-1 border-l-2 border-zinc-300 dark:border-zinc-600">
                                    <span class="font-medium">{{ $message->parent->user?->name }}:</span>
                                    {{ Str::limit($message->parent->content, 50) }}
                                </div>
                            @endif

                            {{-- Message content --}}
                            <div @class([
                                'rounded-lg px-4 py-2',
                                'bg-blue-50 text-zinc-800 dark:bg-blue-600 dark:text-white' => $message->user_id === auth('customer')->id() && !$message->isSystem(),
                                'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' => $message->user_id !== auth('customer')->id() && !$message->isSystem(),
                                'bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400 italic' => $message->isSystem(),
                            ])>
                                @if ($message->isSystem())
                                    <flux:text class="text-sm">{{ $message->content }}</flux:text>
                                @elseif ($message->isAudio())
                                    {{-- Audio message --}}
                                    @php
                                        $audioUrl = $message->getAudioUrl() ?? '';
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
                                            <div x-show="audioError" x-cloak class="flex items-center gap-2 text-xs {{ $message->user_id === auth('customer')->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span>{{ __('Audio unavailable') }}</span>
                                                <a href="{{ $message->getAudioUrl() ?? '' }}" download class="underline hover:no-underline">{{ __('Download') }}</a>
                                            </div>
                                        </div>

                                        {{-- Duration indicator --}}
                                        @if (!empty($message->metadata['audio_duration']))
                                            <div class="text-xs {{ $message->user_id === auth('customer')->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}">
                                                {{ gmdate('i:s', (int) $message->metadata['audio_duration']) }}
                                            </div>
                                        @endif

                                        {{-- Transcription --}}
                                        @if (!empty($message->metadata['transcription']))
                                            <div class="pt-1 border-t {{ $message->user_id === auth('customer')->id() ? 'border-blue-200 dark:border-blue-500' : 'border-zinc-200 dark:border-zinc-700' }}">
                                                <flux:text class="text-sm italic {{ $message->user_id === auth('customer')->id() ? 'text-blue-500 dark:text-blue-200' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                    "{{ $message->metadata['transcription'] }}"
                                                </flux:text>
                                            </div>
                                            {{-- Audio transcription translation toggle --}}
                                            @if ($message->needsTranslationFor(app()->getLocale()))
                                                <div x-data="{ showTranslation: false }" class="mt-1">
                                                    <button
                                                        @click="showTranslation = !showTranslation"
                                                        class="text-xs underline opacity-70 hover:opacity-100 transition-opacity {{ $message->user_id === auth('customer')->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}"
                                                    >
                                                        <span x-show="!showTranslation">{{ __('See translation') }}</span>
                                                        <span x-show="showTranslation" x-cloak>{{ __('Show original') }}</span>
                                                    </button>
                                                    <div x-show="showTranslation" x-cloak class="mt-1">
                                                        <flux:text class="text-sm italic {{ $message->user_id === auth('customer')->id() ? 'text-blue-500 dark:text-blue-200' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                            "{{ $message->getTranslation(app()->getLocale()) }}"
                                                        </flux:text>
                                                        <span class="text-xs opacity-60">{{ __('Translated from :lang', ['lang' => strtoupper($message->getDetectedLanguage())]) }}</span>
                                                    </div>
                                                </div>
                                            @endif
                                        @elseif (($message->metadata['transcription_status'] ?? '') === 'pending')
                                            <div class="text-xs {{ $message->user_id === auth('customer')->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                <span class="animate-pulse">{{ __('Transcribing...') }}</span>
                                            </div>
                                        @elseif (($message->metadata['transcription_status'] ?? '') === 'failed')
                                            <div class="text-xs {{ $message->user_id === auth('customer')->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                {{ __('Transcription unavailable') }}
                                            </div>
                                        @endif

                                        {{-- Retention notice --}}
                                        <div class="text-xs {{ $message->user_id === auth('customer')->id() ? 'text-blue-400/60 dark:text-blue-200/60' : 'text-zinc-400 dark:text-zinc-500' }}">
                                            <flux:icon name="clock" class="inline h-3 w-3 mr-0.5" />
                                            {{ __('Audio files are deleted after 30 days') }}
                                        </div>
                                    </div>
                                @else
                                    <flux:text class="whitespace-pre-wrap break-words">{{ $message->content }}</flux:text>

                                    {{-- Translation toggle --}}
                                    @if ($message->needsTranslationFor(app()->getLocale()) && !$message->isSystem())
                                        <div x-data="{ showTranslation: false }" class="mt-1">
                                            <button
                                                @click="showTranslation = !showTranslation"
                                                class="text-xs underline opacity-70 hover:opacity-100 transition-opacity"
                                            >
                                                <span x-show="!showTranslation">{{ __('See translation') }}</span>
                                                <span x-show="showTranslation" x-cloak>{{ __('Show original') }}</span>
                                            </button>
                                            <div x-show="showTranslation" x-cloak class="mt-1">
                                                <flux:text class="whitespace-pre-wrap break-words">{{ $message->getTranslation(app()->getLocale()) }}</flux:text>
                                                <span class="text-xs opacity-60">{{ __('Translated from :lang', ['lang' => strtoupper($message->getDetectedLanguage())]) }}</span>
                                            </div>
                                        </div>
                                    @endif
                                @endif

                                {{-- Attachments --}}
                                @if ($message->attachments->isNotEmpty())
                                    <div class="mt-2 space-y-2">
                                        @foreach ($message->attachments as $attachment)
                                            <div class="flex items-center gap-2 text-sm">
                                                @if ($attachment->isImage())
                                                    <a href="{{ $attachment->getUrl() }}" target="_blank" class="block">
                                                        <img
                                                            src="{{ $attachment->getUrl() }}"
                                                            alt="{{ $attachment->original_filename }}"
                                                            class="max-w-xs rounded"
                                                        />
                                                    </a>
                                                @else
                                                    <a
                                                        href="{{ $attachment->getUrl() }}"
                                                        target="_blank"
                                                        class="flex items-center gap-2 px-3 py-2 bg-white/10 rounded hover:bg-white/20"
                                                    >
                                                        <flux:icon name="document" class="h-4 w-4" />
                                                        <span>{{ $attachment->original_filename }}</span>
                                                        <span class="text-xs opacity-75">({{ $attachment->formatted_size }})</span>
                                                    </a>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            {{-- Message meta --}}
                            <div @class([
                                'flex items-center gap-2 mt-1',
                                'justify-end' => $message->user_id === auth('customer')->id(),
                            ])>
                                <flux:text class="text-xs text-zinc-400">
                                    {{ $message->created_at->format('M j, g:i A') }}
                                </flux:text>
                                @if ($message->isEdited())
                                    <flux:text class="text-xs text-zinc-400">{{ __('(edited)') }}</flux:text>
                                @endif
                            </div>
                        </div>

                        {{-- Spacer for own messages --}}
                        @if ($message->user_id === auth('customer')->id())
                            <div class="shrink-0 w-8"></div>
                        @endif
                    </div>
                @endforeach

                @if ($this->messages->isEmpty())
                    <div class="flex items-center justify-center h-full">
                        <div class="text-center">
                            <flux:icon name="chat-bubble-left-right" class="mx-auto h-12 w-12 text-zinc-400" />
                            <flux:heading size="sm" class="mt-4">{{ __('No messages yet') }}</flux:heading>
                            <flux:text class="mt-2 text-zinc-500">
                                {{ __('Start the conversation by sending a message below.') }}
                            </flux:text>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Message Composer -->
            <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-4">
                <livewire:customer.chat.message-composer :thread="$thread" :key="'composer-'.$thread->id" />
            </div>
        </div>
    </div>
</div>
