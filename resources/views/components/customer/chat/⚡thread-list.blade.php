<?php

use App\Models\ChatThread;
use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.customer', ['title' => 'Messages'])] class extends Component {
    #[On('thread-created')]
    public function refreshThreads(): void
    {
        unset($this->threads);
    }

    #[Computed]
    public function threads()
    {
        $user = auth('customer')->user();

        return ChatThread::query()
            ->where(function ($q) {
                $q->ofType(ChatThread::TYPE_DEAL)
                    ->orWhere('type', ChatThread::TYPE_DIRECT);
            })
            ->forUser($user)
            ->active()
            ->with(['latestMessage.user', 'deal'])
            ->latestActivity()
            ->get()
            ->map(function (ChatThread $thread) use ($user) {
                $thread->unread_count = $thread->getUnreadCountFor($user);
                $thread->display_name = $thread->getDisplayNameFor($user);

                return $thread;
            });
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Messages') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Communicate with our team about your projects.') }}</flux:text>
            </div>
            <livewire:customer.chat.create-thread />
        </div>

        @if ($this->threads->isNotEmpty())
            <div class="space-y-3">
                @foreach ($this->threads as $thread)
                    <a wire:key="thread-{{ $thread->id }}" href="{{ route('customer.chat.show', $thread) }}" class="block">
                        <div class="flex items-center gap-4 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <div class="flex-shrink-0">
                                @php $isDirect = $thread->type === \App\Models\ChatThread::TYPE_DIRECT; @endphp
                                <div class="w-12 h-12 rounded-full {{ $isDirect ? 'bg-teal-100 dark:bg-teal-900/30' : 'bg-zinc-200 dark:bg-zinc-700' }} flex items-center justify-center">
                                    <flux:icon name="{{ $isDirect ? 'user' : 'chat-bubble-left-right' }}" class="w-6 h-6 {{ $isDirect ? 'text-teal-500' : 'text-zinc-500' }}" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium truncate">{{ $thread->display_name }}</flux:text>
                                    @if ($isDirect)
                                        <flux:badge size="sm" color="zinc">{{ __('Direct') }}</flux:badge>
                                    @elseif ($thread->deal)
                                        <flux:badge size="sm" color="blue">{{ __('Project') }}</flux:badge>
                                    @endif
                                    @if ($thread->unread_count > 0)
                                        <flux:badge size="sm" color="red">{{ $thread->unread_count }}</flux:badge>
                                    @endif
                                </div>
                                @if ($thread->latestMessage)
                                    <flux:text class="text-sm text-zinc-500 truncate">
                                        {{ $thread->latestMessage->user?->name }}: {{ Str::limit($thread->latestMessage->content, 50) }}
                                    </flux:text>
                                @else
                                    <flux:text class="text-sm text-zinc-400 italic">{{ __('No messages yet') }}</flux:text>
                                @endif
                            </div>
                            <div class="flex-shrink-0 text-right">
                                @if ($thread->last_message_at)
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ $thread->last_message_at->diffForHumans() }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="text-center py-16 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <flux:icon name="chat-bubble-left-right" class="mx-auto h-16 w-16 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No Messages') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">{{ __('You don\'t have any active conversations yet.') }}</flux:text>
                <flux:text class="mt-1 text-zinc-500">{{ __('Click "New Message" to start a conversation with our team.') }}</flux:text>
            </div>
        @endif
    </div>
</div>
