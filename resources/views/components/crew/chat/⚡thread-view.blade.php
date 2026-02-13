<?php

use App\Models\ChatThread;
use App\Models\Job;
use App\Services\ChatService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('components.layouts.crew', ['title' => 'Conversation'])] class extends Component {
    public ChatThread $thread;

    #[Computed]
    public function messages()
    {
        return app(ChatService::class)->getMessagesForThread($this->thread, auth('crew')->user());
    }

    public function mount(ChatThread $thread): void
    {
        $user = auth('crew')->user();

        $hasAccess = false;

        if ($thread->type === ChatThread::TYPE_DEAL) {
            // For deal threads: crew must be dispatched to a job on this deal
            $dealId = $thread->deal_id;
            $hasAccess = Job::query()
                ->where('deal_id', $dealId)
                ->whereHas('crews', fn ($q) => $q->where('crew_user_id', $user->id))
                ->exists();
        } elseif ($thread->type === ChatThread::TYPE_DIRECT) {
            // For direct threads: crew must be a participant
            $hasAccess = $thread->hasParticipant($user);
        }

        if (! $hasAccess) {
            throw new NotFoundHttpException('Thread not found.');
        }

        $this->thread = $thread;

        // Ensure crew is added as participant (for deal threads)
        if (! $thread->hasParticipant($user)) {
            $thread->addParticipant($user);
        }

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
        $user = auth('crew')->user();
        app(ChatService::class)->markThreadAsRead($this->thread, $user);
    }

    public function onNewMessage(): void
    {
        unset($this->messages);
        app(ChatService::class)->markThreadAsRead($this->thread, auth('crew')->user());
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
            <flux:button variant="ghost" href="{{ route('crew.chat.index') }}" icon="arrow-left">
                {{ __('Back to Messages') }}
            </flux:button>
        </div>

        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
            <!-- Thread Header -->
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                <div class="flex items-center gap-3">
                    @php $isDirect = $thread->type === \App\Models\ChatThread::TYPE_DIRECT; @endphp
                    <div class="w-10 h-10 rounded-full {{ $isDirect ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-zinc-200 dark:bg-zinc-700' }} flex items-center justify-center">
                        <flux:icon name="{{ $isDirect ? 'user' : 'briefcase' }}" class="w-5 h-5 {{ $isDirect ? 'text-blue-500' : 'text-zinc-500' }}" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ $thread->getDisplayNameFor(auth('crew')->user()) }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">
                            {{ $isDirect ? __('Direct Message') : __('Job Conversation') }}
                        </flux:text>
                    </div>
                </div>
            </div>

            <!-- Messages Container -->
            <div class="h-[500px] overflow-y-auto p-4 space-y-4 bg-white dark:bg-zinc-900">
                @forelse ($this->messages as $message)
                    @php
                        $isOwnMessage = $message->user_id === auth('crew')->id();
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
                                    <div class="rounded-lg p-3 {{ $isOwnMessage ? 'bg-teal-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' }}">
                                        @if ($message->isAudio())
                                            @php
                                                $audioUrl = $message->metadata['audio_url'] ?? '';
                                                $audioMime = $message->metadata['audio_mime_type'] ?? null;
                                                $isM4a = $audioMime === 'audio/mp4' || str_ends_with($audioUrl, '.m4a');
                                            @endphp
                                            <div class="space-y-2">
                                                <audio controls class="w-full max-w-[280px]" preload="metadata">
                                                    @if ($isM4a)
                                                        <source src="{{ $audioUrl }}" type="audio/mp4">
                                                    @else
                                                        <source src="{{ $audioUrl }}" type="audio/webm; codecs=opus">
                                                        <source src="{{ $audioUrl }}" type="audio/webm">
                                                    @endif
                                                    <source src="{{ $audioUrl }}" type="audio/mp4">
                                                    <source src="{{ $audioUrl }}">
                                                </audio>
                                                @if (!empty($message->metadata['transcription']))
                                                    <div class="pt-1 border-t {{ $isOwnMessage ? 'border-teal-500/30' : 'border-zinc-200 dark:border-zinc-700' }}">
                                                        <flux:text class="text-sm italic {{ $isOwnMessage ? 'text-teal-100' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                            "{{ $message->metadata['transcription'] }}"
                                                        </flux:text>
                                                    </div>
                                                @endif
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
                <livewire:crew.chat.message-composer :thread="$thread" :key="'composer-'.$thread->id" />
            </div>
        </div>
    </div>
</div>
