<?php

namespace App\Livewire\Agent\Chat;

use App\Models\ChatThread;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class ThreadList extends Component
{
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

    public function render(): View
    {
        return view('livewire.agent.chat.thread-list')
            ->layout('components.layouts.agent', ['title' => 'Messages']);
    }
}
