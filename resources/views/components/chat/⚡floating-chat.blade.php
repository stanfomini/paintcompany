<?php

use App\Services\ChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $open = false;

    public function boot(): void
    {
        if (! auth()->user()?->can('chat.access')) {
            abort(403);
        }
    }

    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:chat.user.{$userId},.thread.created" => 'onNewThread',
            'message-sent' => 'onMessageSent',
        ];
    }

    public function onNewThread(): void
    {
        unset($this->unreadCount);
        unset($this->threads);
    }

    public function onMessageSent(): void
    {
        unset($this->unreadCount);
        unset($this->threads);
    }

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;

        if ($this->open) {
            unset($this->unreadCount);
            unset($this->threads);
        }
    }

    public function close(): void
    {
        $this->open = false;
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(ChatService::class)->getUnreadCountForUser(auth()->user());
    }

    #[Computed]
    public function threads(): Collection
    {
        $user = auth()->user();
        $allThreads = app(ChatService::class)->getThreadsForUser($user);

        // Prefer threads with unread messages
        $unreadThreads = $allThreads->where('unread_count', '>', 0)->take(5);

        if ($unreadThreads->count() >= 5) {
            return $unreadThreads;
        }

        // Fill remaining slots with recent threads
        $unreadIds = $unreadThreads->pluck('id');
        $recentThreads = $allThreads
            ->whereNotIn('id', $unreadIds)
            ->take(5 - $unreadThreads->count());

        return $unreadThreads->concat($recentThreads);
    }
};
?>

<div wire:poll.30s class="fixed bottom-6 right-6 z-50 flex flex-col items-end gap-3">
    {{-- Messages Panel (above the FAB button) --}}
    @if ($open)
        <div
            x-data
            x-init="$nextTick(() => $el.focus())"
            @click.outside="$wire.set('open', false)"
            class="w-80 max-h-[calc(100vh-8rem)] flex flex-col bg-white dark:bg-zinc-800 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden"
        >
            {{-- Panel Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 shrink-0">
                <flux:heading size="sm">{{ __('Messages') }}</flux:heading>
                <button
                    wire:click="close"
                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
                    aria-label="{{ __('Close messages') }}"
                >
                    <flux:icon name="x-mark" class="size-5" />
                </button>
            </div>

            {{-- Thread List --}}
            <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->threads as $thread)
                    <a
                        wire:key="floating-thread-{{ $thread->id }}"
                        href="{{ route('chat.show', $thread) }}"
                        class="block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold truncate {{ $thread->unread_count > 0 ? 'text-zinc-900 dark:text-white' : 'text-zinc-700 dark:text-zinc-300' }}">
                                        {{ $thread->display_name }}
                                    </span>
                                    @if ($thread->unread_count > 0)
                                        <span class="bg-red-500 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-medium shrink-0">
                                            {{ $thread->unread_count }}
                                        </span>
                                    @endif
                                </div>

                                @if ($thread->latestMessage)
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 truncate {{ $thread->unread_count > 0 ? 'font-medium' : '' }}">
                                        {{ Str::limit($thread->latestMessage->content, 40) }}
                                    </p>
                                @endif
                            </div>

                            @if ($thread->last_message_at)
                                <span class="text-xs text-zinc-400 dark:text-zinc-500 whitespace-nowrap shrink-0">
                                    {{ $thread->last_message_at->diffForHumans(short: true) }}
                                </span>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="px-4 py-8 text-center">
                        <flux:icon name="chat-bubble-left-right" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No conversations yet') }}</p>
                    </div>
                @endforelse
            </div>

            {{-- Panel Footer --}}
            <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-2.5 shrink-0">
                <a
                    href="{{ route('chat.index') }}"
                    class="block text-center text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors"
                >
                    {{ __('View all messages') }}
                </a>
            </div>
        </div>
    @endif

    {{-- Floating Action Button --}}
    <div class="relative">
        <button
            wire:click="toggleOpen"
            class="w-14 h-14 rounded-full bg-blue-600 hover:bg-blue-700 text-white shadow-lg flex items-center justify-center transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-zinc-900"
            aria-label="{{ __('Toggle messages') }}"
        >
            <flux:icon name="chat-bubble-left-right" class="size-6" />
        </button>

        {{-- Unread Badge --}}
        @if ($this->unreadCount > 0)
            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-medium pointer-events-none">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </div>
</div>
