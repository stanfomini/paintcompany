<?php

namespace App\Livewire\Agent\Chat;

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

            // Load older messages
            $olderMessages = app(ChatService::class)->getMessagesForThread(
                $this->thread,
                auth('agent')->user(),
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

    public function render(): View
    {
        $user = auth('agent')->user();

        // Check for more messages
        $oldestMessage = $this->messages->first();
        if ($oldestMessage) {
            $this->hasMoreMessages = $this->thread->messages()
                ->visibleTo($user)
                ->where('id', '<', $oldestMessage->id)
                ->exists();
        }

        return view('livewire.agent.chat.thread-view')
            ->layout('components.layouts.agent', ['title' => $this->displayName]);
    }
}
