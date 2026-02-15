<?php

use App\Models\ChatThread;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ChatThread $thread;

    public function boot(): void
    {
        if (! auth()->user()?->can('chat.access')) {
            abort(403);
        }
    }

    public function mount(int $threadId): void
    {
        $this->thread = ChatThread::findOrFail($threadId);
        abort_unless($this->thread->hasParticipant(auth()->user()), 403);
    }

    #[Computed]
    public function displayName(): string
    {
        return $this->thread->getDisplayNameFor(auth()->user());
    }

    #[Computed]
    public function unreadCount(): int
    {
        return $this->thread->getUnreadCountFor(auth()->user());
    }

    public function close(): void
    {
        $this->dispatch('close-chat-window', threadId: $this->thread->id);
    }

    public function minimize(): void
    {
        $this->dispatch('minimize-chat-window', threadId: $this->thread->id);
    }
};
?>

<div class="w-80 bg-white dark:bg-zinc-800 rounded-t-xl shadow-2xl border border-zinc-200 dark:border-zinc-700 flex flex-col overflow-hidden"
     style="height: 420px;">
    {{-- Header bar --}}
    <div class="flex items-center justify-between px-3 py-2 bg-blue-600 text-white shrink-0">
        <button wire:click="minimize" class="flex items-center gap-2 min-w-0 flex-1 text-left">
            <span class="text-sm font-medium truncate">{{ $this->displayName }}</span>
            @if ($this->unreadCount > 0)
                <span class="bg-red-500 text-white text-xs rounded-full min-w-4 h-4 flex items-center justify-center px-1 font-medium shrink-0">
                    {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
                </span>
            @endif
        </button>
        <div class="flex items-center gap-1 shrink-0">
            <button wire:click="minimize"
                    class="p-1 rounded hover:bg-blue-500 transition-colors"
                    title="{{ __('Minimize') }}">
                <flux:icon name="minus" class="size-4" />
            </button>
            <button wire:click="close"
                    class="p-1 rounded hover:bg-blue-500 transition-colors"
                    title="{{ __('Close') }}">
                <flux:icon name="x-mark" class="size-4" />
            </button>
        </div>
    </div>

    {{-- Messages + Composer (reuse existing embedded thread-view) --}}
    <div class="flex-1 flex flex-col overflow-hidden">
        @livewire('chat.thread-view', ['thread' => $thread, 'embedded' => true], key('tv-' . $thread->id))
    </div>
</div>
