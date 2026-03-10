<?php

use App\Services\ChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $pickerOpen = false;

    public function getListeners(): array
    {
        $userId = auth('customer')->id();

        return [
            "echo-private:chat.user.{$userId},.thread.created" => 'onNewThread',
            "echo-private:chat.user.{$userId},.message.sent" => 'onMessageSent',
            'message-sent' => 'onMessageSent',
        ];
    }

    public function onNewThread(): void
    {
        unset($this->unreadCount, $this->threads);
    }

    public function onMessageSent(): void
    {
        unset($this->unreadCount, $this->threads);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(ChatService::class)->getUnreadCountForUser(auth('customer')->user());
    }

    #[Computed]
    public function threads(): Collection
    {
        $user = auth('customer')->user();
        $allThreads = app(ChatService::class)->getThreadsForUser($user);

        $unreadThreads = $allThreads->where('unread_count', '>', 0)->take(10);

        if ($unreadThreads->count() >= 10) {
            return $unreadThreads;
        }

        $unreadIds = $unreadThreads->pluck('id');
        $recentThreads = $allThreads
            ->whereNotIn('id', $unreadIds)
            ->take(10 - $unreadThreads->count());

        return $unreadThreads->concat($recentThreads);
    }

    public function togglePicker(): void
    {
        $this->pickerOpen = ! $this->pickerOpen;

        if ($this->pickerOpen) {
            unset($this->unreadCount, $this->threads);
        }
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
    }
}; ?>

<div
    class="fixed bottom-0 right-0 z-50 pr-4"
    style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px)); padding-right: calc(1rem + env(safe-area-inset-right, 0px))"
    x-data
    @click.outside="$wire.set('pickerOpen', false)"
>
    {{-- Thread Picker Panel --}}
    @if ($pickerOpen)
        <div class="absolute bottom-full right-0 mb-2 w-80 max-w-[calc(100vw-2rem)] max-h-[calc(100vh-8rem)] flex flex-col bg-white dark:bg-zinc-800 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 shrink-0">
                <flux:heading size="sm">{{ __('Messages') }}</flux:heading>
                <button
                    wire:click="closePicker"
                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
                    aria-label="{{ __('Close messages') }}"
                >
                    <flux:icon name="x-mark" class="size-5" />
                </button>
            </div>

            {{-- Thread List --}}
            <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-700">
                @if ($this->threads->isNotEmpty())
                    @foreach ($this->threads as $thread)
                        <a
                            wire:key="picker-thread-{{ $thread->id }}"
                            href="{{ route('customer.chat.show', $thread) }}"
                            wire:navigate
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
                    @endforeach
                @else
                    <div class="px-4 py-8 text-center">
                        <flux:icon name="chat-bubble-left-right" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No conversations yet') }}</p>
                    </div>
                @endif
            </div>

            {{-- Footer --}}
            <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-2.5 shrink-0">
                <a
                    href="{{ route('customer.chat.index') }}"
                    wire:navigate
                    class="block text-center text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors"
                >
                    {{ __('View all messages') }}
                </a>
            </div>
        </div>
    @endif

    {{-- FAB Button --}}
    <div class="relative">
        <button
            wire:click="togglePicker"
            class="w-14 h-14 rounded-full bg-blue-600 hover:bg-blue-700 text-white shadow-lg flex items-center justify-center transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-zinc-900"
            aria-label="{{ __('Toggle messages') }}"
        >
            <flux:icon name="chat-bubble-left-right" class="size-6" />
        </button>

        @if ($this->unreadCount > 0)
            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-medium pointer-events-none">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </div>
</div>
