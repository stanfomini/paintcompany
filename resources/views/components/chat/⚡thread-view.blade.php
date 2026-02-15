<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\ChatService;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        $user = auth()->user();
        if (! $user?->can('chat.access')) {
            abort(403);
        }
    }

    public ChatThread $thread;

    public bool $embedded = false;

    public int $perPage = 50;

    public ?int $beforeMessageId = null;

    public ?int $replyingToMessageId = null;

    public function mount(ChatThread $thread, bool $embedded = false): void
    {
        $this->embedded = $embedded;
        // Verify user is a participant in this thread
        abort_unless($thread->hasParticipant(auth()->user()), 403);

        $this->thread = $thread->load(['deal', 'activeParticipants.user']);

        // Mark thread as read on view
        app(ChatService::class)->markThreadAsRead($thread, auth()->user());
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
        // Clear the cache so messages re-load
        unset($this->messages);

        // Mark as read
        app(ChatService::class)->markThreadAsRead($this->thread, auth()->user());
    }

    public function onNewMessage(): void
    {
        unset($this->messages);
        app(ChatService::class)->markThreadAsRead($this->thread, auth()->user());
    }

    #[Computed]
    public function messages(): Collection
    {
        return app(ChatService::class)->getMessagesForThread(
            $this->thread,
            auth()->user(),
            $this->perPage,
            $this->beforeMessageId
        );
    }

    public function loadMoreMessages(): void
    {
        $oldestMessage = $this->messages->first();

        if ($oldestMessage) {
            $this->beforeMessageId = $oldestMessage->id;
        }

        unset($this->messages);
        unset($this->hasMoreMessages);
    }

    public function setReplyTo(int $messageId): void
    {
        $this->replyingToMessageId = $messageId;
        $this->dispatch('set-reply-to', messageId: $messageId);
    }

    public function cancelReply(): void
    {
        $this->replyingToMessageId = null;
        $this->dispatch('cancel-reply');
    }

    public function deleteMessage(int $messageId): void
    {
        $message = ChatMessage::findOrFail($messageId);

        // Verify ownership or internal user
        abort_unless(
            $message->user_id === auth()->id() || auth()->user()->isInternal(),
            403
        );

        app(ChatService::class)->deleteMessage($message, auth()->user());

        unset($this->messages);
        $this->dispatch('message-deleted');
    }

    public function translateMessage(int $messageId): void
    {
        $message = ChatMessage::findOrFail($messageId);
        abort_unless($message->thread_id === $this->thread->id, 403);

        $text = trim($message->content);
        if (mb_strlen($text) < 5) {
            return;
        }

        $tr = new GoogleTranslate();
        $tr->setSource(null);
        $tr->setTarget('en');
        $translated = $tr->translate($text);
        $detectedLang = $tr->getLastDetectedSource();

        $targetLang = $detectedLang === 'en' ? 'es' : 'en';

        if ($detectedLang === $targetLang) {
            $metadata = $message->metadata ?? [];
            $metadata['detected_lang'] = $detectedLang;
            $message->update(['metadata' => $metadata]);
            unset($this->messages);

            return;
        }

        // Re-translate if initial target was wrong
        if ($targetLang !== 'en') {
            $tr->setSource(null);
            $tr->setTarget($targetLang);
            $translated = $tr->translate($text);
        }

        $metadata = $message->metadata ?? [];
        $metadata['detected_lang'] = $detectedLang;
        $metadata['translations'][$targetLang] = $translated;
        $message->update(['metadata' => $metadata]);

        unset($this->messages);
    }

    #[Computed]
    public function participants(): Collection
    {
        return $this->thread->activeParticipants->pluck('user');
    }

    #[Computed]
    public function displayName(): string
    {
        return $this->thread->getDisplayNameFor(auth()->user());
    }

    #[Computed]
    public function replyingToMessage(): ?ChatMessage
    {
        if (! $this->replyingToMessageId) {
            return null;
        }

        return ChatMessage::with('user')->find($this->replyingToMessageId);
    }

    #[Computed]
    public function hasMoreMessages(): bool
    {
        $oldestMessage = $this->messages->first();

        if (! $oldestMessage) {
            return false;
        }

        return $this->thread->messages()
            ->visibleTo(auth()->user())
            ->where('id', '<', $oldestMessage->id)
            ->exists();
    }

    #[Computed]
    public function threadContext(): ?array
    {
        if (! auth()->user()->isInternal() || $this->embedded) {
            return null;
        }

        if ($this->thread->type === 'deal' && $this->thread->deal) {
            $deal = $this->thread->deal->load(['customer.user', 'estimates', 'job']);
            $customer = $deal->customer;

            return [
                'type' => 'deal',
                'customer_name' => $customer?->full_name,
                'customer_email' => $customer?->user?->email,
                'customer_phone' => $customer?->phone,
                'property_address' => $deal->property?->full_address ?? null,
                'customer_url' => $customer ? route('customers.show', $customer) : null,
                'deal_url' => route('deals.show', $deal),
                'estimates' => $deal->estimates->map(fn ($e) => [
                    'url' => route('estimates.show', $e),
                    'number' => $e->estimate_number,
                ])->toArray(),
                'job_url' => $deal->job ? route('jobs.show', $deal->job) : null,
            ];
        }

        if ($this->thread->type === 'direct') {
            $other = $this->participants->first(fn ($p) => $p->id !== auth()->id());

            if (! $other) {
                return null;
            }

            return [
                'type' => 'direct',
                'name' => $other->name,
                'type_badge' => ucfirst($other->type),
                'email' => $other->email,
                'phone' => $other->phone ?? null,
                'profile_url' => $this->getProfileUrl($other),
            ];
        }

        return null;
    }

    private function getProfileUrl(User $user): ?string
    {
        return match ($user->type) {
            'customer' => $user->customerProfile ? route('customers.show', $user->customerProfile) : null,
            'agent' => route('agents.show', $user),
            'crew' => route('crews.show', $user),
            default => null,
        };
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
    class="flex flex-col {{ $embedded ? 'h-full' : 'h-[calc(100vh-8rem)]' }}"
>
    <div class="{{ $embedded ? '' : 'max-w-4xl mx-auto' }} w-full flex flex-col h-full">
        {{-- Header --}}
        @if (! $embedded)
            <div class="border-b border-zinc-200 dark:border-zinc-700 pb-4 mb-4 shrink-0">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <a href="{{ route('chat.index') }}" class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                            <flux:icon name="arrow-left" class="h-5 w-5" />
                        </a>
                        <div>
                            <flux:heading size="lg">{{ $this->displayName }}</flux:heading>
                            <div class="flex items-center gap-2 mt-1">
                                @if ($thread->type === 'deal' && $thread->deal)
                                    <flux:badge size="sm" color="blue">{{ __('Deal:') }} {{ $thread->deal->title }}</flux:badge>
                                @endif
                                <flux:text class="text-sm text-zinc-500">
                                    {{ trans_choice('{1} :count participant|[2,*] :count participants', $this->participants->count(), ['count' => $this->participants->count()]) }}
                                </flux:text>
                            </div>
                        </div>
                    </div>

                    {{-- Participants avatars --}}
                    <div class="flex -space-x-2">
                        @foreach ($this->participants->take(5) as $participant)
                            <flux:tooltip content="{{ $participant->name }}">
                                <flux:avatar
                                    name="{{ $participant->name }}"
                                    size="sm"
                                    class="ring-2 ring-white dark:ring-zinc-900"
                                />
                            </flux:tooltip>
                        @endforeach
                        @if ($this->participants->count() > 5)
                            <div class="flex items-center justify-center w-8 h-8 rounded-full bg-zinc-200 dark:bg-zinc-700 ring-2 ring-white dark:ring-zinc-900 text-xs font-medium">
                                +{{ $this->participants->count() - 5 }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Profile Context Card (internal users only) --}}
        @if ($this->threadContext)
            <div
                x-data="{ contextOpen: false }"
                class="shrink-0 mb-2"
            >
                <button
                    @click="contextOpen = !contextOpen"
                    class="w-full flex items-center justify-between px-3 py-2 text-sm text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded-lg transition-colors"
                >
                    <div class="flex items-center gap-2">
                        <flux:icon name="information-circle" class="size-4" />
                        @if ($this->threadContext['type'] === 'deal')
                            <span>{{ $this->threadContext['customer_name'] ?? __('Customer Info') }}</span>
                        @else
                            <span>{{ $this->threadContext['name'] ?? __('Contact Info') }}</span>
                        @endif
                    </div>
                    <flux:icon
                        name="chevron-down"
                        class="size-4 transition-transform"
                        ::class="contextOpen ? 'rotate-180' : ''"
                    />
                </button>

                <div x-show="contextOpen" x-collapse x-cloak>
                    <div class="mx-1 mt-1 p-3 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        @if ($this->threadContext['type'] === 'deal')
                            {{-- Deal thread context --}}
                            <div class="space-y-2">
                                @if ($this->threadContext['customer_name'])
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="user" class="size-4 text-zinc-400" />
                                        <flux:text class="text-sm font-medium">{{ $this->threadContext['customer_name'] }}</flux:text>
                                    </div>
                                @endif

                                @if ($this->threadContext['customer_email'])
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="envelope" class="size-4 text-zinc-400" />
                                        <flux:text class="text-sm">{{ $this->threadContext['customer_email'] }}</flux:text>
                                    </div>
                                @endif

                                @if ($this->threadContext['customer_phone'])
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="phone" class="size-4 text-zinc-400" />
                                        <flux:text class="text-sm">{{ $this->threadContext['customer_phone'] }}</flux:text>
                                    </div>
                                @endif

                                @if ($this->threadContext['property_address'])
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="map-pin" class="size-4 text-zinc-400" />
                                        <flux:text class="text-sm">{{ $this->threadContext['property_address'] }}</flux:text>
                                    </div>
                                @endif

                                {{-- Quick Links --}}
                                <div class="flex flex-wrap gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                    @if ($this->threadContext['customer_url'])
                                        <a href="{{ $this->threadContext['customer_url'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                            <flux:icon name="user" class="size-3" />
                                            {{ __('Customer') }}
                                        </a>
                                    @endif

                                    <a href="{{ $this->threadContext['deal_url'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                        <flux:icon name="briefcase" class="size-3" />
                                        {{ __('Deal') }}
                                    </a>

                                    @foreach ($this->threadContext['estimates'] as $estimate)
                                        <a href="{{ $estimate['url'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                            <flux:icon name="document-text" class="size-3" />
                                            {{ $estimate['number'] }}
                                        </a>
                                    @endforeach

                                    @if ($this->threadContext['job_url'])
                                        <a href="{{ $this->threadContext['job_url'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                            <flux:icon name="clipboard-document-check" class="size-3" />
                                            {{ __('Job') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @else
                            {{-- Direct thread context --}}
                            <div class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <flux:text class="text-sm font-medium">{{ $this->threadContext['name'] }}</flux:text>
                                    <flux:badge size="sm">{{ $this->threadContext['type_badge'] }}</flux:badge>
                                </div>

                                @if ($this->threadContext['email'])
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="envelope" class="size-4 text-zinc-400" />
                                        <flux:text class="text-sm">{{ $this->threadContext['email'] }}</flux:text>
                                    </div>
                                @endif

                                @if ($this->threadContext['phone'])
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="phone" class="size-4 text-zinc-400" />
                                        <flux:text class="text-sm">{{ $this->threadContext['phone'] }}</flux:text>
                                    </div>
                                @endif

                                @if ($this->threadContext['profile_url'])
                                    <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                        <a href="{{ $this->threadContext['profile_url'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                            <flux:icon name="arrow-top-right-on-square" class="size-3" />
                                            {{ __('View Profile') }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Messages area --}}
        <div
            class="flex-1 overflow-y-auto space-y-4 px-2"
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
            {{-- Load more button --}}
            @if ($this->hasMoreMessages)
                <div class="text-center py-2">
                    <flux:button
                        wire:click="loadMoreMessages"
                        variant="ghost"
                        size="sm"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="loadMoreMessages">{{ __('Load older messages') }}</span>
                        <span wire:loading wire:target="loadMoreMessages">{{ __('Loading...') }}</span>
                    </flux:button>
                </div>
            @endif

            {{-- Messages --}}
            @foreach ($this->messages as $message)
                <div
                    wire:key="message-{{ $message->id }}"
                    @class([
                        'flex gap-3',
                        'flex-row-reverse' => $message->user_id === auth()->id(),
                    ])
                >
                    {{-- Avatar --}}
                    @if ($message->user_id !== auth()->id())
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
                        'items-end' => $message->user_id === auth()->id(),
                        'items-start' => $message->user_id !== auth()->id(),
                    ])>
                        {{-- Sender name (for others' messages) --}}
                        @if ($message->user_id !== auth()->id())
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
                            'bg-blue-50 text-zinc-800 dark:bg-blue-600 dark:text-white' => $message->user_id === auth()->id() && !$message->is_internal,
                            'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 border border-yellow-300 dark:border-yellow-700' => $message->is_internal,
                            'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' => $message->user_id !== auth()->id() && !$message->is_internal,
                            'bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400 italic' => $message->isSystem(),
                        ])>
                            @if ($message->is_internal)
                                <div class="flex items-center gap-1 mb-1">
                                    <flux:icon name="lock-closed" class="h-3 w-3" />
                                    <span class="text-xs font-medium">{{ __('Internal Note') }}</span>
                                </div>
                            @endif

                            @if ($message->isSystem())
                                <flux:text class="text-sm">{{ $message->content }}</flux:text>
                            @elseif ($message->isAudio())
                                {{-- Audio message --}}
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
                                                console.log('[AudioPlayer] Audio loaded successfully');
                                            }
                                        }"
                                        class="relative"
                                    >
                                        @php
                                            $audioUrl = $message->metadata['audio_url'] ?? '';
                                            $audioMime = $message->metadata['audio_mime_type'] ?? null;
                                            $isM4a = $audioMime === 'audio/mp4' || str_ends_with($audioUrl, '.m4a');
                                        @endphp
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
                                            {{-- Always include MP4 fallback for Safari-recorded .webm files --}}
                                            <source src="{{ $audioUrl }}" type="audio/mp4">
                                            <source src="{{ $audioUrl }}">
                                            {{ __('Your browser does not support audio playback.') }}
                                        </audio>

                                        {{-- Error state --}}
                                        <div x-show="audioError" x-cloak class="flex items-center gap-2 text-xs {{ $message->user_id === auth()->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span>{{ __('Audio unavailable') }}</span>
                                            <a
                                                href="{{ $message->metadata['audio_url'] ?? '' }}"
                                                download
                                                class="underline hover:no-underline"
                                            >{{ __('Download') }}</a>
                                        </div>
                                    </div>

                                    {{-- Duration indicator --}}
                                    @if (!empty($message->metadata['audio_duration']))
                                        <div class="text-xs {{ $message->user_id === auth()->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}">
                                            {{ gmdate('i:s', (int) $message->metadata['audio_duration']) }}
                                        </div>
                                    @endif

                                    {{-- Transcription --}}
                                    @if (!empty($message->metadata['transcription']))
                                        <div class="pt-1 border-t {{ $message->user_id === auth()->id() ? 'border-blue-200 dark:border-blue-500' : 'border-zinc-200 dark:border-zinc-700' }}">
                                            <flux:text class="text-sm italic {{ $message->user_id === auth()->id() ? 'text-blue-500 dark:text-blue-200' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                "{{ $message->metadata['transcription'] }}"
                                            </flux:text>
                                        </div>
                                        {{-- Audio transcription translation toggle --}}
                                        @if ($message->needsTranslationFor(app()->getLocale()))
                                            <div x-data="{ showTranslation: false }" class="mt-1">
                                                <button
                                                    @click="showTranslation = !showTranslation"
                                                    class="text-xs underline opacity-70 hover:opacity-100 transition-opacity {{ $message->user_id === auth()->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}"
                                                >
                                                    <span x-show="!showTranslation">{{ __('See translation') }}</span>
                                                    <span x-show="showTranslation" x-cloak>{{ __('Show original') }}</span>
                                                </button>
                                                <div x-show="showTranslation" x-cloak class="mt-1">
                                                    <flux:text class="text-sm italic {{ $message->user_id === auth()->id() ? 'text-blue-500 dark:text-blue-200' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                        "{{ $message->getTranslation(app()->getLocale()) }}"
                                                    </flux:text>
                                                    <span class="text-xs opacity-60">{{ __('Translated from :lang', ['lang' => strtoupper($message->getDetectedLanguage())]) }}</span>
                                                </div>
                                            </div>
                                        @endif
                                    @elseif (($message->metadata['transcription_status'] ?? '') === 'pending')
                                        <div class="text-xs {{ $message->user_id === auth()->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                            <span class="animate-pulse">{{ __('Transcribing...') }}</span>
                                        </div>
                                    @elseif (($message->metadata['transcription_status'] ?? '') === 'failed')
                                        <div class="text-xs {{ $message->user_id === auth()->id() ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                            {{ __('Transcription unavailable') }}
                                        </div>
                                    @endif

                                    {{-- Retention notice --}}
                                    <div class="text-xs {{ $message->user_id === auth()->id() ? 'text-blue-400/60 dark:text-blue-200/60' : 'text-zinc-400 dark:text-zinc-500' }}">
                                        <flux:icon name="clock" class="inline h-3 w-3 mr-0.5" />
                                        {{ __('Audio files are deleted after 30 days') }}
                                    </div>
                                </div>
                            @else
                                <flux:text class="whitespace-pre-wrap break-words">{{ $message->content }}</flux:text>

                            {{-- Translation toggle --}}
                            @if ($message->needsTranslationFor(app()->getLocale()))
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
                            'justify-end' => $message->user_id === auth()->id(),
                        ])>
                            <flux:text class="text-xs text-zinc-400">
                                {{ $message->created_at->format('M j, g:i A') }}
                            </flux:text>
                            @if ($message->isEdited())
                                <flux:text class="text-xs text-zinc-400">{{ __('(edited)') }}</flux:text>
                            @endif

                            {{-- Actions --}}
                            @if (!$message->isSystem())
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item
                                            wire:click="setReplyTo({{ $message->id }})"
                                            icon="arrow-uturn-left"
                                        >
                                            {{ __('Reply') }}
                                        </flux:menu.item>
                                        @if (! $message->getDetectedLanguage())
                                            <flux:menu.item
                                                wire:click="translateMessage({{ $message->id }})"
                                                icon="language"
                                            >
                                                {{ __('Translate') }}
                                            </flux:menu.item>
                                        @endif
                                        @if ($message->user_id === auth()->id() || auth()->user()->isInternal())
                                            <flux:menu.item
                                                wire:click="deleteMessage({{ $message->id }})"
                                                wire:confirm="{{ __('Are you sure you want to delete this message?') }}"
                                                icon="trash"
                                                variant="danger"
                                            >
                                                {{ __('Delete') }}
                                            </flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>
                    </div>

                    {{-- Spacer for own messages --}}
                    @if ($message->user_id === auth()->id())
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

        {{-- Message composer --}}
        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4 mt-4 shrink-0">
            @livewire('chat.message-composer', ['thread' => $thread], key('composer-' . $thread->id))
        </div>
    </div>
</div>
