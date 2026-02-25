<?php

use App\Services\ChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /** @var array<int> Thread IDs with open windows */
    public array $openThreadIds = [];

    /** @var array<int> Thread IDs that are minimized */
    public array $minimizedIds = [];

    /** Whether the thread picker panel is open */
    public bool $pickerOpen = false;

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
            'close-chat-window' => 'closeThread',
            'minimize-chat-window' => 'minimizeThread',
            'open-chat-thread' => 'openThread',
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
        $unreadThreads = $allThreads->where('unread_count', '>', 0)->take(10);

        if ($unreadThreads->count() >= 10) {
            return $unreadThreads;
        }

        // Fill remaining slots with recent threads
        $unreadIds = $unreadThreads->pluck('id');
        $recentThreads = $allThreads
            ->whereNotIn('id', $unreadIds)
            ->take(10 - $unreadThreads->count());

        return $unreadThreads->concat($recentThreads);
    }

    public function openThread(int $threadId): void
    {
        // Validate user has access
        $thread = \App\Models\ChatThread::find($threadId);
        if (! $thread || ! $thread->hasParticipant(auth()->user())) {
            return;
        }

        // Remove from minimized if it was there
        $this->minimizedIds = array_values(array_diff($this->minimizedIds, [$threadId]));

        // Don't add duplicates
        if (in_array($threadId, $this->openThreadIds)) {
            return;
        }

        // If at max (4), minimize the oldest open window
        if (count($this->openThreadIds) >= 4) {
            $oldest = array_shift($this->openThreadIds);
            $this->minimizedIds[] = $oldest;
        }

        $this->openThreadIds[] = $threadId;

        // Close the picker
        $this->pickerOpen = false;

        // Mark as read
        app(ChatService::class)->markThreadAsRead($thread, auth()->user());
        unset($this->unreadCount);
        unset($this->threads);
    }

    public function closeThread(int $threadId): void
    {
        $this->openThreadIds = array_values(array_diff($this->openThreadIds, [$threadId]));
        $this->minimizedIds = array_values(array_diff($this->minimizedIds, [$threadId]));
    }

    public function minimizeThread(int $threadId): void
    {
        if (in_array($threadId, $this->openThreadIds)) {
            $this->openThreadIds = array_values(array_diff($this->openThreadIds, [$threadId]));
            if (! in_array($threadId, $this->minimizedIds)) {
                $this->minimizedIds[] = $threadId;
            }
        }
    }

    public function restoreThread(int $threadId): void
    {
        if (in_array($threadId, $this->minimizedIds)) {
            $this->minimizedIds = array_values(array_diff($this->minimizedIds, [$threadId]));

            // If at max, minimize the oldest
            if (count($this->openThreadIds) >= 4) {
                $oldest = array_shift($this->openThreadIds);
                $this->minimizedIds[] = $oldest;
            }

            $this->openThreadIds[] = $threadId;
        }
    }

    public function togglePicker(): void
    {
        $this->pickerOpen = ! $this->pickerOpen;

        if ($this->pickerOpen) {
            unset($this->unreadCount);
            unset($this->threads);
        }
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
    }

    /**
     * Restore window state from localStorage on hard refresh.
     *
     * @param  array<int>  $openIds
     * @param  array<int>  $minimizedIds
     */
    public function restoreState(array $openIds, array $minimizedIds): void
    {
        $user = auth()->user();
        $validOpen = [];
        $validMinimized = [];

        foreach ($openIds as $id) {
            $thread = \App\Models\ChatThread::find($id);
            if ($thread && $thread->hasParticipant($user)) {
                $validOpen[] = $id;
            }
        }

        foreach ($minimizedIds as $id) {
            $thread = \App\Models\ChatThread::find($id);
            if ($thread && $thread->hasParticipant($user) && ! in_array($id, $validOpen)) {
                $validMinimized[] = $id;
            }
        }

        $this->openThreadIds = array_slice($validOpen, 0, 4);
        $this->minimizedIds = $validMinimized;
    }

    /**
     * Get display name for a thread by ID (for minimized bars).
     */
    public function getThreadDisplayName(int $threadId): string
    {
        $thread = \App\Models\ChatThread::find($threadId);

        return $thread ? $thread->getDisplayNameFor(auth()->user()) : 'Chat';
    }
};
?>

<div
    wire:poll.30s
    x-data="{
        init() {
            // On hard refresh (not wire:navigate), restore state from localStorage
            // Livewire persist handles wire:navigate persistence automatically
            if ($wire.openThreadIds.length === 0 && $wire.minimizedIds.length === 0) {
                try {
                    const savedOpen = JSON.parse(localStorage.getItem('chat_dock_open') || '[]');
                    const savedMinimized = JSON.parse(localStorage.getItem('chat_dock_minimized') || '[]');
                    if (savedOpen.length > 0 || savedMinimized.length > 0) {
                        $wire.restoreState(savedOpen, savedMinimized);
                    }
                } catch (err) {}
            }
        }
    }"
    x-effect="
        localStorage.setItem('chat_dock_open', JSON.stringify($wire.openThreadIds));
        localStorage.setItem('chat_dock_minimized', JSON.stringify($wire.minimizedIds));
    "
    class="fixed bottom-0 right-0 z-50 pr-4" style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px)); padding-right: calc(1rem + env(safe-area-inset-right, 0px))"
>
    {{-- ======================= --}}
    {{-- DESKTOP: Chat windows   --}}
    {{-- ======================= --}}
    <div class="hidden lg:flex items-end gap-1">
        {{-- Open chat windows (stack left of FAB) --}}
        @foreach ($openThreadIds as $threadId)
            <div wire:key="chat-window-{{ $threadId }}">
                @livewire('chat.chat-window', ['threadId' => $threadId], key('cw-' . $threadId))
            </div>
        @endforeach

        {{-- Minimized windows --}}
        @foreach ($minimizedIds as $threadId)
            <div
                wire:key="minimized-{{ $threadId }}"
                class="w-56 bg-white dark:bg-zinc-800 rounded-t-xl shadow-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:shadow-xl transition-shadow"
                wire:click="restoreThread({{ $threadId }})"
            >
                <div class="flex items-center justify-between px-3 py-2 bg-blue-600 text-white rounded-t-xl">
                    <span class="text-sm font-medium truncate">{{ $this->getThreadDisplayName($threadId) }}</span>
                    <div class="flex items-center gap-1 shrink-0">
                        <button wire:click.stop="restoreThread({{ $threadId }})"
                                class="p-0.5 rounded hover:bg-blue-500 transition-colors"
                                title="{{ __('Restore') }}">
                            <flux:icon name="chevron-up" class="size-4" />
                        </button>
                        <button wire:click.stop="closeThread({{ $threadId }})"
                                class="p-0.5 rounded hover:bg-blue-500 transition-colors"
                                title="{{ __('Close') }}">
                            <flux:icon name="x-mark" class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
        @endforeach

        {{-- Thread Picker Panel + FAB --}}
        <div class="relative" x-data @click.outside="$wire.set('pickerOpen', false)">
            {{-- Thread picker panel (above FAB) --}}
            @if ($pickerOpen)
                <div class="absolute bottom-full right-0 mb-2 w-80 max-h-[calc(100vh-8rem)] flex flex-col bg-white dark:bg-zinc-800 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    {{-- Panel Header --}}
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
                                <button
                                    wire:key="picker-thread-{{ $thread->id }}"
                                    wire:click="openThread({{ $thread->id }})"
                                    type="button"
                                    class="w-full text-left block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors"
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
                                </button>
                            @endforeach
                        @else
                            <div class="px-4 py-8 text-center">
                                <flux:icon name="chat-bubble-left-right" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No conversations yet') }}</p>
                            </div>
                        @endif
                    </div>

                    {{-- Panel Footer --}}
                    <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-2.5 shrink-0">
                        <a
                            href="{{ route('communications.index') }}"
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

                {{-- Unread Badge --}}
                @if ($this->unreadCount > 0)
                    <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-medium pointer-events-none">
                        {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- ========================= --}}
    {{-- MOBILE: FAB + link picker --}}
    {{-- ========================= --}}
    <div class="lg:hidden">
        <div class="relative" x-data @click.outside="$wire.set('pickerOpen', false)">
            {{-- Mobile picker panel (compact, above FAB) --}}
            @if ($pickerOpen)
                <div class="absolute bottom-full right-0 mb-2 w-80 max-h-96 flex flex-col bg-white dark:bg-zinc-800 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    {{-- Header --}}
                    <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 shrink-0">
                        <flux:heading size="sm">{{ __('Messages') }}</flux:heading>
                        <button
                            wire:click="closePicker"
                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
                        >
                            <flux:icon name="x-mark" class="size-5" />
                        </button>
                    </div>

                    {{-- Thread list (links to full-page chat) --}}
                    <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-700">
                        @if ($this->threads->isNotEmpty())
                            @foreach ($this->threads as $thread)
                                <a
                                    wire:key="mobile-thread-{{ $thread->id }}"
                                    href="{{ route('chat.show', $thread) }}"
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
                            href="{{ route('communications.index') }}"
                            wire:navigate
                            class="block text-center text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors"
                        >
                            {{ __('View all messages') }}
                        </a>
                    </div>
                </div>
            @endif

            {{-- Mobile FAB --}}
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
    </div>
</div>
