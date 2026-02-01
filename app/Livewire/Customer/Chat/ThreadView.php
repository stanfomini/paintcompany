<?php

namespace App\Livewire\Customer\Chat;

use App\Models\ChatThread;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('components.layouts.customer', ['title' => 'Conversation'])]
class ThreadView extends Component
{
    public ChatThread $thread;

    public function mount(ChatThread $thread): void
    {
        $user = auth('customer')->user();

        // Authorization: thread must be deal type
        if ($thread->type !== ChatThread::TYPE_DEAL) {
            throw new NotFoundHttpException('Thread not found.');
        }

        // Authorization: customer must be a participant
        if (! $thread->hasParticipant($user)) {
            throw new NotFoundHttpException('Thread not found.');
        }

        $this->thread = $thread;

        // Mark messages as read on view
        app(ChatService::class)->markThreadAsRead($thread, $user);
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        $user = auth('customer')->user();
        app(ChatService::class)->markThreadAsRead($this->thread, $user);
    }

    public function render(): View
    {
        $user = auth('customer')->user();

        // Load messages visible to customer (no internal messages)
        $messages = app(ChatService::class)->getMessagesForThread($this->thread, $user);

        return view('livewire.customer.chat.thread-view', [
            'messages' => $messages,
        ]);
    }
}
