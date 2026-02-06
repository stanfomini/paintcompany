<?php

use App\Models\ChatThread;
use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.customer', ['title' => 'Messages'])] class extends Component {
    #[Computed]
    public function threads()
    {
        $user = auth('customer')->user();

        $customer = Customer::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $customer) {
            return collect();
        }

        return ChatThread::query()
            ->ofType(ChatThread::TYPE_DEAL)
            ->forUser($user)
            ->active()
            ->with(['latestMessage.user', 'deal'])
            ->latestActivity()
            ->get()
            ->map(function (ChatThread $thread) use ($user) {
                $thread->unread_count = $thread->getUnreadCountFor($user);
                $thread->display_name = $thread->getDisplayNameFor($user);

                return $thread;
            });
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <flux:heading size="xl">{{ __('Messages') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Communicate with our team about your projects.') }}</flux:text>
        </div>

        @if ($this->threads->isNotEmpty())
            <div class="space-y-3">
                @foreach ($this->threads as $thread)
                    <a wire:key="thread-{{ $thread->id }}" href="{{ route('customer.chat.show', $thread) }}" class="block">
                        <div class="flex items-center gap-4 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                    <flux:icon name="chat-bubble-left-right" class="w-6 h-6 text-zinc-500" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium truncate">{{ $thread->display_name }}</flux:text>
                                    @if ($thread->unread_count > 0)
                                        <flux:badge size="sm" color="blue">{{ $thread->unread_count }}</flux:badge>
                                    @endif
                                </div>
                                @if ($thread->latestMessage)
                                    <flux:text class="text-sm text-zinc-500 truncate">
                                        {{ $thread->latestMessage->user?->name }}: {{ Str::limit($thread->latestMessage->content, 50) }}
                                    </flux:text>
                                @else
                                    <flux:text class="text-sm text-zinc-400 italic">{{ __('No messages yet') }}</flux:text>
                                @endif
                            </div>
                            <div class="flex-shrink-0 text-right">
                                @if ($thread->last_message_at)
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ $thread->last_message_at->diffForHumans() }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="text-center py-16 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <flux:icon name="chat-bubble-left-right" class="mx-auto h-16 w-16 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No Messages') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">{{ __('You don\'t have any active conversations yet.') }}</flux:text>
                <flux:text class="mt-1 text-zinc-500">{{ __('Messages will appear here once your project discussions begin.') }}</flux:text>
            </div>
        @endif
    </div>
</div>
