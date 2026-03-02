<?php

use App\Services\ChatService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
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
            "echo-private:chat.user.{$userId},.thread.created" => 'refreshCount',
            "echo-private:chat.user.{$userId},.message.sent" => 'refreshCount',
            'message-sent' => 'refreshCount',
            'refresh-notification-counts' => 'refreshCount',
        ];
    }

    public function refreshCount(): void
    {
        unset($this->unreadCount);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(ChatService::class)->getUnreadCountForUser(auth()->user());
    }
};
?>

<span wire:poll.60s.visible="refreshCount">
    @if ($this->unreadCount > 0)
        <span class="bg-red-500 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-medium">
            {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
        </span>
    @endif
</span>
