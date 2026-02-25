<?php

use App\Models\ChatThread;
use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public Customer $customer;

    public bool $isOpen = false;

    public string $activeTab = 'all';

    #[On('open-communications-drawer')]
    public function open(): void
    {
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    #[Computed]
    public function filteredItems(): \Illuminate\Support\Collection
    {
        if ($this->activeTab === 'all') {
            return collect();
        }

        if ($this->activeTab === 'emails') {
            $seenThreads = [];

            return $this->customer->emailLinks()
                ->with('message')
                ->latest()
                ->limit(20)
                ->get()
                ->filter(function ($link) use (&$seenThreads) {
                    if (! $link->message) {
                        return false;
                    }
                    $threadId = $link->message->email_thread_id;
                    if ($threadId && isset($seenThreads[$threadId])) {
                        return false;
                    }
                    if ($threadId) {
                        $seenThreads[$threadId] = true;
                    }

                    return true;
                })
                ->map(fn ($link) => [
                    'type' => 'email',
                    'channel' => 'email',
                    'thread_type' => 'email',
                    'preview' => $link->message?->subject ?? 'No subject',
                    'sender' => $link->message?->from_address ?? 'Unknown',
                    'date' => $link->message?->created_at ?? $link->created_at,
                    'subject' => $link->message?->subject ?? 'Email',
                    'id' => $link->id,
                    'email_thread_id' => $link->message?->email_thread_id,
                ])
                ->values();
        }

        $dealIds = $this->customer->deals()->pluck('id')->toArray();

        $threads = ChatThread::query()
            ->where(function ($q) use ($dealIds) {
                $q->where('customer_id', $this->customer->id);

                if (! empty($dealIds)) {
                    $q->orWhereIn('deal_id', $dealIds);
                }

                if ($this->customer->user_id) {
                    $q->orWhereHas('participants', function ($pq) {
                        $pq->where('user_id', $this->customer->user_id)
                            ->whereNull('left_at');
                    });
                }
            })
            ->with(['latestMessage.user', 'deal'])
            ->latestActivity()
            ->limit(20)
            ->when($this->activeTab === 'messenger', fn ($q) => $q->where('channel', 'facebook'))
            ->when($this->activeTab === 'deals', fn ($q) => $q->where('type', ChatThread::TYPE_DEAL))
            ->when($this->activeTab === 'direct', fn ($q) => $q->whereIn('type', [ChatThread::TYPE_DIRECT, ChatThread::TYPE_SUPPORT])->where('channel', '!=', 'facebook'))
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

        return $threads;
    }

    #[Computed]
    public function tabs(): array
    {
        return [
            'all' => __('All'),
            'messenger' => __('Messenger'),
            'deals' => __('Deals'),
            'direct' => __('Direct'),
            'emails' => __('Emails'),
        ];
    }
}; ?>

<div>
    {{-- Backdrop --}}
    @if ($isOpen)
        <div
            x-data
            x-show="true"
            x-transition:enter="transition-opacity ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-40 bg-black/25 dark:bg-black/40"
            wire:click="close"
        ></div>
    @endif

    {{-- Drawer Panel --}}
    @if ($isOpen)
        <div
            x-data
            x-show="true"
            x-transition:enter="transform transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white dark:bg-zinc-900 shadow-xl flex flex-col"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('Communications') }}</flux:heading>
                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="close" />
            </div>

            {{-- Tab bar --}}
            <div class="flex items-center gap-1 px-4 py-2 border-b border-zinc-200 dark:border-zinc-700 overflow-x-auto">
                @foreach ($this->tabs as $key => $label)
                    <button
                        wire:click="setTab('{{ $key }}')"
                        wire:key="tab-{{ $key }}"
                        class="px-3 py-1.5 text-sm rounded-md whitespace-nowrap transition-colors {{ $activeTab === $key ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Content --}}
            <div class="flex-1 overflow-y-auto px-4 py-3">
                @if ($activeTab === 'all')
                    <livewire:customers.communication-timeline :customer="$customer" :limit="10" />
                @else
                    @if ($this->filteredItems->isEmpty())
                        <div class="text-center py-8">
                            <flux:icon name="{{ match($activeTab) {
                                'messenger' => 'chat-bubble-left',
                                'deals' => 'briefcase',
                                'direct' => 'chat-bubble-left-right',
                                'emails' => 'envelope',
                                default => 'chat-bubble-left-right',
                            } }}" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text size="sm" class="text-zinc-500">{{ __('No :type communications.', ['type' => strtolower($this->tabs[$activeTab])]) }}</flux:text>
                        </div>
                    @else
                        <div class="space-y-1">
                            @foreach ($this->filteredItems as $index => $item)
                                <div wire:key="filtered-{{ $index }}" class="flex items-start gap-3 p-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
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
                                    <div class="flex-1 min-w-0">
                                        <flux:text class="font-medium text-sm truncate">{{ $item['subject'] }}</flux:text>
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
                @endif
            </div>

            {{-- Footer --}}
            <div class="px-4 py-3 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button variant="ghost" size="sm" class="w-full" :href="$customer->user_id ? route('communications.index', ['contactId' => $customer->user_id]) : route('customers.communications', $customer)" icon-trailing="arrow-right" wire:navigate>
                    {{ __('View Full Page') }}
                </flux:button>
            </div>
        </div>
    @endif
</div>
