<?php

use App\Models\ChatThread;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.agent', ['title' => 'Messages'])] class extends Component {
    public string $search = '';

    public string $filter = 'all';

    public function mount(): void
    {
        // Ensure agent is authenticated
        abort_unless(auth('agent')->check(), 403);
    }

    public function updatedSearch(): void
    {
        // Search triggers re-render automatically
    }

    public function updatedFilter(): void
    {
        // Filter change triggers re-render automatically
    }

    #[On('message-sent')]
    #[On('thread-created')]
    public function refreshThreads(): void
    {
        unset($this->threads);
    }

    #[Computed]
    public function threads(): Collection
    {
        $user = auth('agent')->user();
        $chatService = app(ChatService::class);

        $query = ChatThread::query()
            ->forUser($user)
            ->with(['latestMessage.user', 'deal', 'activeParticipants.user'])
            ->latestActivity();

        // Apply filter
        match ($this->filter) {
            'unread' => $query->whereHas('messages', function ($q) use ($user) {
                // Messages created after user's last read time
                $q->where('user_id', '!=', $user->id)
                    ->where('is_internal', false) // Agents can't see internal messages
                    ->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id));
            }),
            default => $query->active(),
        };

        $threads = $query->get();

        // Apply search filter
        if ($this->search) {
            $searchLower = strtolower($this->search);
            $threads = $threads->filter(function (ChatThread $thread) use ($user, $searchLower) {
                $displayName = strtolower($thread->getDisplayNameFor($user));
                $latestMessage = $thread->latestMessage?->content ?? '';

                return str_contains($displayName, $searchLower)
                    || str_contains(strtolower($latestMessage), $searchLower);
            });
        }

        // Add computed properties
        return $threads->map(function (ChatThread $thread) use ($user) {
            $thread->unread_count = $thread->getUnreadCountFor($user);
            $thread->display_name = $thread->getDisplayNameFor($user);

            return $thread;
        });
    }

    #[Computed]
    public function totalUnreadCount(): int
    {
        return app(ChatService::class)->getUnreadCountForUser(auth('agent')->user());
    }

    public function archiveThread(int $threadId): void
    {
        $thread = ChatThread::findOrFail($threadId);

        // Verify user is a participant
        abort_unless($thread->hasParticipant(auth('agent')->user()), 403);

        app(ChatService::class)->archiveThread($thread);

        $this->dispatch('thread-archived');
        unset($this->threads);
    }
}; ?>

<div wire:poll.30s>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <flux:heading size="xl">Messages</flux:heading>
                @if ($this->totalUnreadCount > 0)
                    <flux:badge color="red" size="sm">{{ $this->totalUnreadCount }} unread</flux:badge>
                @endif
            </div>
            <livewire:agent.chat.create-thread />
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Search conversations..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-40">
                <flux:select wire:model.live="filter">
                    <option value="all">All</option>
                    <option value="unread">Unread</option>
                </flux:select>
            </div>
        </div>

        @if ($this->threads->isEmpty())
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="chat-bubble-left-right" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">No conversations found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search)
                        Try adjusting your search criteria.
                    @elseif ($filter === 'unread')
                        You have no unread messages.
                    @else
                        Your conversations will appear here.
                    @endif
                </flux:text>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($this->threads as $thread)
                    <div
                        wire:key="thread-{{ $thread->id }}"
                        class="border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                    >
                        <a
                            href="{{ route('agent.chat.show', $thread) }}"
                            wire:navigate
                            class="block p-4"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3 min-w-0 flex-1">
                                    {{-- Avatar/Initials --}}
                                    <div class="shrink-0">
                                        @php
                                            $otherParticipant = $thread->activeParticipants
                                                ->where('user_id', '!=', auth('agent')->id())
                                                ->first()?->user;
                                        @endphp
                                        @if ($otherParticipant)
                                            <flux:avatar
                                                name="{{ $otherParticipant->name }}"
                                                size="md"
                                                class="bg-zinc-200 dark:bg-zinc-700"
                                            />
                                        @else
                                            <flux:avatar
                                                name="{{ $thread->display_name }}"
                                                size="md"
                                                class="bg-zinc-200 dark:bg-zinc-700"
                                            />
                                        @endif
                                    </div>

                                    {{-- Thread Info --}}
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <flux:text class="font-semibold truncate {{ $thread->unread_count > 0 ? 'text-zinc-900 dark:text-white' : 'text-zinc-700 dark:text-zinc-300' }}">
                                                {{ $thread->display_name }}
                                            </flux:text>
                                            @if ($thread->type === 'deal' && $thread->deal)
                                                <flux:badge size="sm" color="blue">Deal</flux:badge>
                                            @elseif ($thread->type === 'support')
                                                <flux:badge size="sm" color="purple">Support</flux:badge>
                                            @endif
                                        </div>

                                        @if ($thread->latestMessage)
                                            <div class="mt-1 flex items-center gap-2">
                                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 truncate {{ $thread->unread_count > 0 ? 'font-medium' : '' }}">
                                                    @if ($thread->latestMessage->user_id === auth('agent')->id())
                                                        <span class="text-zinc-400 dark:text-zinc-500">You:</span>
                                                    @else
                                                        <span class="text-zinc-400 dark:text-zinc-500">{{ $thread->latestMessage->user?->name }}:</span>
                                                    @endif
                                                    {{ Str::limit($thread->latestMessage->content, 60) }}
                                                </flux:text>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Right side: Time and unread count --}}
                                <div class="shrink-0 flex flex-col items-end gap-2">
                                    @if ($thread->last_message_at)
                                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                            {{ $thread->last_message_at->diffForHumans(short: true) }}
                                        </flux:text>
                                    @endif

                                    @if ($thread->unread_count > 0)
                                        <flux:badge color="red" size="sm">{{ $thread->unread_count }}</flux:badge>
                                    @endif
                                </div>
                            </div>
                        </a>

                        {{-- Actions dropdown --}}
                        <div class="px-4 pb-3 flex justify-end">
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    <flux:menu.item
                                        wire:click="archiveThread({{ $thread->id }})"
                                        icon="archive-box"
                                    >
                                        Archive
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
