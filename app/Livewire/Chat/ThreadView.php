<?php

namespace App\Livewire\Chat;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class ThreadView extends Component
{
    public ChatThread $thread;

    public int $perPage = 50;

    public ?int $beforeMessageId = null;

    public bool $hasMoreMessages = false;

    public ?int $replyingToMessageId = null;

    public function mount(ChatThread $thread): void
    {
        // Verify user is a participant in this thread
        abort_unless($thread->hasParticipant(auth()->user()), 403);

        $this->thread = $thread->load(['deal', 'activeParticipants.user']);

        // Mark thread as read on view
        app(ChatService::class)->markThreadAsRead($thread, auth()->user());
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        // Clear the cache so messages re-load
        unset($this->messages);

        // Mark as read
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

            // Load older messages
            $olderMessages = app(ChatService::class)->getMessagesForThread(
                $this->thread,
                auth()->user(),
                $this->perPage,
                $this->beforeMessageId
            );

            $this->hasMoreMessages = $olderMessages->count() >= $this->perPage;
        }

        unset($this->messages);
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

    public function render(): View
    {
        // Check for more messages
        $oldestMessage = $this->messages->first();
        if ($oldestMessage) {
            $this->hasMoreMessages = $this->thread->messages()
                ->visibleTo(auth()->user())
                ->where('id', '<', $oldestMessage->id)
                ->exists();
        }

        return view('livewire.chat.thread-view')
            ->layout('layouts.app', ['title' => $this->displayName]);
    }
}
