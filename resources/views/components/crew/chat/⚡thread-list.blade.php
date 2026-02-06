<?php

use App\Models\ChatThread;
use App\Models\Job;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.crew', ['title' => 'Messages'])] class extends Component {
    #[On('thread-created')]
    public function refreshThreads(): void
    {
        unset($this->threads);
    }

    #[Computed]
    public function threads()
    {
        $user = auth('crew')->user();

        // Get deal IDs for jobs dispatched to this crew
        $dealIds = Job::query()
            ->whereHas('crews', fn ($q) => $q->where('crew_user_id', $user->id))
            ->pluck('deal_id');

        // Get deal threads for assigned jobs
        $dealThreads = collect();
        if ($dealIds->isNotEmpty()) {
            $dealThreads = ChatThread::query()
                ->ofType(ChatThread::TYPE_DEAL)
                ->whereIn('deal_id', $dealIds)
                ->active()
                ->with(['latestMessage.user', 'deal'])
                ->get();
        }

        // Get direct message threads where crew is a participant
        $directThreads = ChatThread::query()
            ->ofType(ChatThread::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->active()
            ->with(['latestMessage.user', 'participants.user'])
            ->get();

        // Merge and sort by latest activity
        return $dealThreads->merge($directThreads)
            ->sortByDesc('last_message_at')
            ->map(function (ChatThread $thread) use ($user) {
                // Ensure crew is added as participant if not already (for deal threads)
                if (! $thread->hasParticipant($user)) {
                    $thread->addParticipant($user);
                }
                $thread->unread_count = $thread->getUnreadCountFor($user);
                $thread->display_name = $thread->getDisplayNameFor($user);
                $thread->is_direct = $thread->type === ChatThread::TYPE_DIRECT;

                return $thread;
            });
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Messages') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Communicate with the team about your jobs.') }}</flux:text>
            </div>
            <livewire:crew.chat.create-thread />
        </div>

        @if ($this->threads->isNotEmpty())
            <div class="space-y-3">
                @foreach ($this->threads as $thread)
                    <a wire:key="thread-{{ $thread->id }}" href="{{ route('crew.chat.show', $thread) }}" class="block">
                        <div class="flex items-center gap-4 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 rounded-full {{ $thread->is_direct ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-zinc-200 dark:bg-zinc-700' }} flex items-center justify-center">
                                    <flux:icon name="{{ $thread->is_direct ? 'user' : 'briefcase' }}" class="w-6 h-6 {{ $thread->is_direct ? 'text-blue-500' : 'text-zinc-500' }}" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium truncate">{{ $thread->display_name }}</flux:text>
                                    @if ($thread->unread_count > 0)
                                        <flux:badge size="sm" color="blue">{{ $thread->unread_count }}</flux:badge>
                                    @endif
                                    @if ($thread->is_direct)
                                        <flux:badge size="sm" color="sky">Direct</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">Job</flux:badge>
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
                <flux:heading size="lg" class="mt-4">{{ __('No Messages Yet') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500 max-w-sm mx-auto">
                    {{ __('Start a conversation with the team or wait to be assigned to jobs.') }}
                </flux:text>
                <div class="mt-4">
                    <livewire:crew.chat.create-thread />
                </div>
            </div>
        @endif
    </div>
</div>
