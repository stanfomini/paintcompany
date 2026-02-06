<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\ChatService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.agent', ['title' => 'Conversation'])] class extends Component {
    public ChatThread $thread;

    public int $perPage = 50;

    public ?int $beforeMessageId = null;

    public ?int $replyingToMessageId = null;

    public function mount(ChatThread $thread): void
    {
        $user = auth('agent')->user();

        // Verify agent is a participant in this thread
        abort_unless($thread->hasParticipant($user), 403);

        $this->thread = $thread->load(['deal', 'activeParticipants.user']);

        // Mark thread as read on view
        app(ChatService::class)->markThreadAsRead($thread, $user);
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        // Clear the cache so messages re-load
        unset($this->messages);
        unset($this->hasMore);

        // Mark as read
        app(ChatService::class)->markThreadAsRead($this->thread, auth('agent')->user());
    }

    #[Computed]
    public function messages(): Collection
    {
        // ChatService.getMessagesForThread uses visibleTo() scope which filters out
        // internal messages for non-internal users (agents)
        return app(ChatService::class)->getMessagesForThread(
            $this->thread,
            auth('agent')->user(),
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
        unset($this->hasMore);
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
        $user = auth('agent')->user();

        // Agents can only delete their own messages
        abort_unless($message->user_id === $user->id, 403);

        app(ChatService::class)->deleteMessage($message, $user);

        unset($this->messages);
        $this->dispatch('message-deleted');
    }

    #[Computed]
    public function participants(): Collection
    {
        return $this->thread->activeParticipants->pluck('user');
    }

    #[Computed]
    public function displayName(): string
    {
        return $this->thread->getDisplayNameFor(auth('agent')->user());
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
    public function hasMore(): bool
    {
        $user = auth('agent')->user();
        $oldestMessage = $this->messages->first();

        if (! $oldestMessage) {
            return false;
        }

        return $this->thread->messages()
            ->visibleTo($user)
            ->where('id', '<', $oldestMessage->id)
            ->exists();
    }
}; ?>

<div wire:poll.10s class="flex flex-col h-[calc(100vh-8rem)]">
    <div class="max-w-4xl mx-auto w-full flex flex-col h-full">
        {{-- Header --}}
        <div class="border-b border-zinc-200 dark:border-zinc-700 pb-4 mb-4 shrink-0">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="{{ route('agent.chat.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                        <flux:icon name="arrow-left" class="h-5 w-5" />
                    </a>
                    <div>
                        <flux:heading size="lg">{{ $this->displayName }}</flux:heading>
                        <div class="flex items-center gap-2 mt-1">
                            @if ($thread->type === 'deal' && $thread->deal)
                                <flux:badge size="sm" color="blue">Deal: {{ $thread->deal->title }}</flux:badge>
                            @endif
                            <flux:text class="text-sm text-zinc-500">
                                {{ $this->participants->count() }} participant{{ $this->participants->count() !== 1 ? 's' : '' }}
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

        {{-- Messages area --}}
        <div
            class="flex-1 overflow-y-auto space-y-4 px-2"
            x-data="{
                scrollToBottom() {
                    this.$el.scrollTop = this.$el.scrollHeight;
                }
            }"
            x-init="scrollToBottom()"
            @message-sent.window="$nextTick(() => scrollToBottom())"
        >
            {{-- Load more button --}}
            @if ($this->hasMore)
                <div class="text-center py-2">
                    <flux:button
                        wire:click="loadMoreMessages"
                        variant="ghost"
                        size="sm"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="loadMoreMessages">Load older messages</span>
                        <span wire:loading wire:target="loadMoreMessages">Loading...</span>
                    </flux:button>
                </div>
            @endif

            {{-- Messages --}}
            @foreach ($this->messages as $message)
                <div
                    wire:key="message-{{ $message->id }}"
                    @class([
                        'flex gap-3',
                        'flex-row-reverse' => $message->user_id === auth('agent')->id(),
                    ])
                >
                    {{-- Avatar --}}
                    @if ($message->user_id !== auth('agent')->id())
                        <div class="shrink-0">
                            <flux:tooltip content="{{ $message->user?->name ?? 'Unknown' }}">
                                <flux:avatar
                                    name="{{ $message->user?->name ?? 'Unknown' }}"
                                    size="sm"
                                />
                            </flux:tooltip>
                        </div>
                    @endif

                    {{-- Message bubble --}}
                    <div @class([
                        'max-w-[70%]',
                        'items-end' => $message->user_id === auth('agent')->id(),
                        'items-start' => $message->user_id !== auth('agent')->id(),
                    ])>
                        {{-- Sender name (for others' messages) --}}
                        @if ($message->user_id !== auth('agent')->id())
                            <div class="flex items-center gap-2 mb-1">
                                <flux:text class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                    {{ $message->user?->name ?? 'Unknown' }}
                                </flux:text>
                                @if ($message->user?->isInternal())
                                    <flux:badge size="sm" color="blue">Staff</flux:badge>
                                @elseif ($message->user?->isCustomer())
                                    <flux:badge size="sm" color="purple">Customer</flux:badge>
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
                            'bg-blue-600 text-white' => $message->user_id === auth('agent')->id(),
                            'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' => $message->user_id !== auth('agent')->id() && !$message->isSystem(),
                            'bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400 italic' => $message->isSystem(),
                        ])>
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
                                            <source src="{{ $message->metadata['audio_url'] ?? '' }}">
                                            <source src="{{ $message->metadata['audio_url'] ?? '' }}" type="video/webm">
                                            <source src="{{ $message->metadata['audio_url'] ?? '' }}" type="audio/webm; codecs=opus">
                                            <source src="{{ $message->metadata['audio_url'] ?? '' }}" type="audio/webm">
                                            Your browser does not support audio playback.
                                        </audio>

                                        {{-- Error state --}}
                                        <div x-show="audioError" x-cloak class="flex items-center gap-2 text-xs {{ $message->user_id === auth('agent')->id() ? 'text-blue-200' : 'text-zinc-500' }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span>Audio unavailable</span>
                                            <a href="{{ $message->metadata['audio_url'] ?? '' }}" download class="underline hover:no-underline">Download</a>
                                        </div>
                                    </div>

                                    {{-- Duration indicator --}}
                                    @if (!empty($message->metadata['audio_duration']))
                                        <div class="text-xs {{ $message->user_id === auth('agent')->id() ? 'text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}">
                                            {{ gmdate('i:s', (int) $message->metadata['audio_duration']) }}
                                        </div>
                                    @endif

                                    {{-- Transcription --}}
                                    @if (!empty($message->metadata['transcription']))
                                        <div class="pt-1 border-t {{ $message->user_id === auth('agent')->id() ? 'border-blue-500/30' : 'border-zinc-200 dark:border-zinc-700' }}">
                                            <flux:text class="text-sm italic {{ $message->user_id === auth('agent')->id() ? 'text-blue-100' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                "{{ $message->metadata['transcription'] }}"
                                            </flux:text>
                                        </div>
                                    @elseif (($message->metadata['transcription_status'] ?? '') === 'pending')
                                        <div class="text-xs {{ $message->user_id === auth('agent')->id() ? 'text-blue-200' : 'text-zinc-500' }}">
                                            <span class="animate-pulse">Transcribing...</span>
                                        </div>
                                    @elseif (($message->metadata['transcription_status'] ?? '') === 'failed')
                                        <div class="text-xs {{ $message->user_id === auth('agent')->id() ? 'text-blue-200' : 'text-zinc-500' }}">
                                            Transcription unavailable
                                        </div>
                                    @endif

                                    {{-- Retention notice --}}
                                    <div class="text-xs {{ $message->user_id === auth('agent')->id() ? 'text-blue-200/60' : 'text-zinc-400 dark:text-zinc-500' }}">
                                        <flux:icon name="clock" class="inline h-3 w-3 mr-0.5" />
                                        Audio files are deleted after 30 days
                                    </div>
                                </div>
                            @else
                                <flux:text class="whitespace-pre-wrap break-words">{{ $message->content }}</flux:text>
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
                            'justify-end' => $message->user_id === auth('agent')->id(),
                        ])>
                            <flux:text class="text-xs text-zinc-400">
                                {{ $message->created_at->format('M j, g:i A') }}
                            </flux:text>
                            @if ($message->isEdited())
                                <flux:text class="text-xs text-zinc-400">(edited)</flux:text>
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
                                            Reply
                                        </flux:menu.item>
                                        @if ($message->user_id === auth('agent')->id())
                                            <flux:menu.item
                                                wire:click="deleteMessage({{ $message->id }})"
                                                wire:confirm="Are you sure you want to delete this message?"
                                                icon="trash"
                                                variant="danger"
                                            >
                                                Delete
                                            </flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>
                    </div>

                    {{-- Spacer for own messages --}}
                    @if ($message->user_id === auth('agent')->id())
                        <div class="shrink-0 w-8"></div>
                    @endif
                </div>
            @endforeach

            @if ($this->messages->isEmpty())
                <div class="flex items-center justify-center h-full">
                    <div class="text-center">
                        <flux:icon name="chat-bubble-left-right" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">No messages yet</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">
                            Start the conversation by sending a message below.
                        </flux:text>
                    </div>
                </div>
            @endif
        </div>

        {{-- Message composer --}}
        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4 mt-4 shrink-0">
            @livewire('agent.chat.message-composer', ['thread' => $thread], key('composer-' . $thread->id))
        </div>
    </div>
</div>
