<?php

use App\Models\ChatThread;
use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Customer $customer;

    public int $limit = 20;

    #[Computed]
    public function timelineItems(): \Illuminate\Support\Collection
    {
        $threads = ChatThread::query()
            ->where(function ($q) {
                $q->where('customer_id', $this->customer->id)
                    ->orWhereIn('deal_id', $this->customer->deals()->pluck('id'));
            })
            ->with(['latestMessage.user', 'deal'])
            ->latestActivity()
            ->limit($this->limit)
            ->get()
            ->map(fn ($thread) => [
                'type' => 'chat',
                'channel' => $thread->channel ?? 'internal',
                'thread_type' => $thread->type,
                'preview' => $thread->latestMessage?->content ?? 'No messages',
                'sender' => $thread->latestMessage?->user?->name ?? 'System',
                'date' => $thread->last_message_at ?? $thread->created_at,
                'subject' => $thread->subject ?? $thread->deal?->title ?? 'Chat',
                'id' => $thread->id,
            ]);

        $emails = $this->customer->emailLinks()
            ->with('message')
            ->latest()
            ->limit($this->limit)
            ->get()
            ->map(fn ($link) => [
                'type' => 'email',
                'channel' => 'email',
                'thread_type' => 'email',
                'preview' => $link->message?->subject ?? 'No subject',
                'sender' => $link->message?->from_address ?? 'Unknown',
                'date' => $link->message?->created_at ?? $link->created_at,
                'subject' => $link->message?->subject ?? 'Email',
                'id' => $link->id,
            ]);

        return $threads->concat($emails)
            ->sortByDesc('date')
            ->take($this->limit)
            ->values();
    }
}; ?>

<div>
    @if ($this->timelineItems->isEmpty())
        <div class="text-center py-8">
            <flux:icon name="chat-bubble-left-right" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
            <flux:text size="sm" class="text-zinc-500">{{ __('No communications yet.') }}</flux:text>
        </div>
    @else
        <div class="space-y-1">
            @foreach ($this->timelineItems as $index => $item)
                <div wire:key="timeline-{{ $index }}" class="flex items-start gap-3 p-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                    {{-- Channel icon --}}
                    <div class="shrink-0 mt-0.5 w-7 h-7 rounded-full flex items-center justify-center {{ match($item['channel']) {
                        'facebook' => 'bg-blue-100 dark:bg-blue-900/30',
                        'email' => 'bg-amber-100 dark:bg-amber-900/30',
                        default => 'bg-zinc-100 dark:bg-zinc-800',
                    } }}">
                        @if ($item['channel'] === 'facebook')
                            <svg class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        @elseif ($item['channel'] === 'email')
                            <flux:icon name="envelope" class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                        @else
                            <flux:icon name="chat-bubble-left-right" class="w-3.5 h-3.5 text-zinc-500 dark:text-zinc-400" />
                        @endif
                    </div>

                    {{-- Content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <flux:text class="font-medium text-sm truncate">{{ $item['subject'] }}</flux:text>
                            <flux:badge size="sm" color="{{ match($item['channel']) {
                                'facebook' => 'blue',
                                'email' => 'amber',
                                default => 'zinc',
                            } }}">
                                {{ match($item['channel']) {
                                    'facebook' => 'Messenger',
                                    'email' => 'Email',
                                    default => ucfirst($item['thread_type']),
                                } }}
                            </flux:badge>
                        </div>
                        <flux:text size="xs" class="text-zinc-500 truncate mt-0.5">
                            {{ \Illuminate\Support\Str::limit($item['preview'], 80) }}
                        </flux:text>
                        <div class="flex items-center gap-2 mt-0.5">
                            <flux:text size="xs" class="text-zinc-400">{{ $item['sender'] }}</flux:text>
                            @if ($item['date'])
                                <flux:text size="xs" class="text-zinc-400">&middot; {{ $item['date']->diffForHumans() }}</flux:text>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
