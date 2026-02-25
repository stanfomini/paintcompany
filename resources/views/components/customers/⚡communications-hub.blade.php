<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public Customer $customer;

    public string $activeTab = 'all';

    // Reply modal
    public bool $showReplyModal = false;
    public ?int $replyToMessageId = null;
    public ?int $replyAccountId = null;

    public string $replyTo = '';
    public string $replySubject = '';
    public string $replyBody = '';

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;

        // Clear computed caches when switching tabs
        unset(
            $this->allItems,
            $this->messengerThreads,
            $this->dealThreads,
            $this->directThreads,
            $this->emailLinks,
        );
    }

    /**
     * Build the base query to find all threads related to this customer.
     * Uses the same logic as the customer show page: deals + participant-based lookup.
     */
    private function customerThreadQuery()
    {
        $dealIds = $this->customer->deals()->pluck('id')->toArray();

        return ChatThread::query()
            ->where(function ($q) use ($dealIds) {
                // Threads linked via customer_id
                $q->where('customer_id', $this->customer->id);

                // Threads linked via deals
                if (! empty($dealIds)) {
                    $q->orWhereIn('deal_id', $dealIds);
                }

                // Threads where the customer's user account is a participant
                if ($this->customer->user_id) {
                    $q->orWhereHas('participants', function ($pq) {
                        $pq->where('user_id', $this->customer->user_id)
                            ->whereNull('left_at');
                    });
                }
            });
    }

    #[Computed]
    public function allItems()
    {
        $items = collect();

        $allThreads = $this->customerThreadQuery()
            ->with(['latestMessage.user', 'deal', 'customer', 'messengerConversation'])
            ->latestActivity()
            ->get();

        foreach ($allThreads as $thread) {
            $items->push([
                'type' => 'chat',
                'channel' => $thread->channel,
                'thread_type' => $thread->type,
                'icon' => $this->getThreadIcon($thread),
                'label' => $this->getThreadLabel($thread),
                'preview' => $thread->latestMessage
                    ? ($thread->latestMessage->user?->name ?? __('Unknown')).': '.Str::limit($thread->latestMessage->content, 80)
                    : __('No messages yet'),
                'sender' => $thread->latestMessage?->user?->name ?? null,
                'date' => $thread->last_message_at ?? $thread->created_at,
                'thread_id' => $thread->id,
                'url' => route('chat.show', $thread),
            ]);
        }

        // Email CRM links - show linked emails with deep-link to thread
        $emailLinks = $this->customer->emailLinks()
            ->with('message.thread')
            ->latest()
            ->get();

        // Group by thread to avoid duplicate rows per thread
        $seenThreads = [];
        foreach ($emailLinks as $link) {
            $email = $link->message;
            if (! $email) {
                continue;
            }
            $threadId = $email->email_thread_id;
            if ($threadId && isset($seenThreads[$threadId])) {
                continue;
            }
            if ($threadId) {
                $seenThreads[$threadId] = true;
            }
            $items->push([
                'type' => 'email',
                'channel' => 'email',
                'thread_type' => null,
                'icon' => 'envelope',
                'label' => $email->subject ?? __('(No subject)'),
                'preview' => Str::limit($email->snippet ?? $email->body_text ?? '', 80),
                'sender' => $email->from_name ?? $email->from_address,
                'date' => $email->sent_at ?? $email->created_at,
                'thread_id' => null,
                'url' => $threadId ? route('email.inbox', ['threadId' => $threadId]) : route('email.inbox'),
                'message_id' => $email->id,
                'direction' => $email->direction,
            ]);
        }

        return $items->sortByDesc('date')->values();
    }

    #[Computed]
    public function messengerThreads()
    {
        return $this->customerThreadQuery()
            ->ofChannel('facebook')
            ->with(['latestMessage.user', 'messengerConversation'])
            ->latestActivity()
            ->get();
    }

    #[Computed]
    public function dealThreads()
    {
        $dealIds = $this->customer->deals()->pluck('id');

        if ($dealIds->isEmpty()) {
            return collect();
        }

        return ChatThread::query()
            ->ofType('deal')
            ->whereIn('deal_id', $dealIds)
            ->with(['latestMessage.user', 'deal'])
            ->latestActivity()
            ->get();
    }

    #[Computed]
    public function directThreads()
    {
        if (! $this->customer->user_id) {
            return collect();
        }

        return ChatThread::query()
            ->where(function ($q) {
                $q->where('type', ChatThread::TYPE_DIRECT)
                    ->orWhere('type', ChatThread::TYPE_SUPPORT);
            })
            ->whereHas('participants', function ($pq) {
                $pq->where('user_id', $this->customer->user_id)
                    ->whereNull('left_at');
            })
            ->with(['latestMessage.user', 'activeParticipants.user'])
            ->latestActivity()
            ->get();
    }

    #[Computed]
    public function emailLinks()
    {
        // Group by email thread so we show one row per conversation, not per message
        $links = $this->customer->emailLinks()
            ->with('message.thread')
            ->latest()
            ->get();

        $seenThreads = [];

        return $links->filter(function ($link) use (&$seenThreads) {
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
        })->values();
    }

    public function openReplyModal(int $messageId): void
    {
        $message = EmailMessage::find($messageId);
        if (! $message) {
            return;
        }

        $this->replyToMessageId = $messageId;
        $this->replyTo = $message->from_address;
        $this->replySubject = str_starts_with(strtolower($message->subject), 're:')
            ? $message->subject
            : 'Re: ' . $message->subject;

        // Determine the reply account - prefer shared account, fallback to personal
        $account = $message->account;
        if ($account) {
            $this->replyAccountId = $account->id;
        } else {
            $personal = auth()->user()->emailAccount;
            $this->replyAccountId = $personal?->id;
        }

        // Quote original message
        $date = $message->sent_at?->format('M j, Y \a\t g:i A') ?? '';
        $from = $message->from_name ?? $message->from_address;
        $this->replyBody = '<br><br><div class="gmail_quote">'
            . '<p>On ' . $date . ', ' . $from . ' wrote:</p>'
            . '<blockquote style="border-left: 2px solid #ccc; padding-left: 12px; color: #666;">'
            . $message->body_html
            . '</blockquote></div>';

        // Append signature
        $replyAccount = $this->replyAccountId ? EmailAccount::find($this->replyAccountId) : null;
        if ($replyAccount?->signature_html) {
            $this->replyBody = '<br><br><div class="signature">--<br>' . $replyAccount->signature_html . '</div>' . $this->replyBody;
        }

        $this->showReplyModal = true;
    }

    public function sendReply(): void
    {
        if (! $this->replyTo || ! $this->replyAccountId) {
            return;
        }

        $account = EmailAccount::find($this->replyAccountId);
        if (! $account) {
            return;
        }

        $replyToMessage = $this->replyToMessageId ? EmailMessage::find($this->replyToMessageId) : null;

        app(\App\Services\EmailService::class)->send(
            account: $account,
            to: [['address' => $this->replyTo, 'name' => null]],
            subject: $this->replySubject,
            bodyHtml: $this->replyBody,
            replyTo: $replyToMessage,
        );

        $this->showReplyModal = false;
        $this->replyToMessageId = null;
        $this->replyTo = '';
        $this->replySubject = '';
        $this->replyBody = '';
        $this->replyAccountId = null;

        // Refresh data
        unset($this->allItems, $this->emailLinks);
    }

    public function closeReplyModal(): void
    {
        $this->showReplyModal = false;
        $this->replyToMessageId = null;
    }

    private function getThreadIcon(ChatThread $thread): string
    {
        if ($thread->channel === 'facebook') {
            return 'chat-bubble-oval-left';
        }

        return match ($thread->type) {
            'deal' => 'briefcase',
            'direct' => 'chat-bubble-left-right',
            'support' => 'lifebuoy',
            default => 'chat-bubble-left',
        };
    }

    private function getThreadLabel(ChatThread $thread): string
    {
        if ($thread->channel === 'facebook') {
            return __('Messenger');
        }

        return match ($thread->type) {
            'deal' => $thread->deal?->title ?? __('Deal Chat'),
            'direct' => $thread->subject ?? __('Direct Message'),
            'support' => $thread->subject ?? __('Support'),
            default => $thread->subject ?? __('Chat'),
        };
    }
};
?>

<div>
    <div class="max-w-5xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center gap-2 sm:gap-3 mb-6">
            <flux:button variant="ghost" :href="route('customers.show', $customer)" icon="arrow-left" wire:navigate class="shrink-0" size="sm">
                <span class="hidden sm:inline">{{ __('Back') }}</span>
            </flux:button>
            <div class="min-w-0">
                <flux:heading size="xl" class="truncate">{{ __('Communications') }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500 truncate">{{ $customer->full_name }}</flux:text>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="flex items-center gap-1 border-b border-zinc-200 dark:border-zinc-700 mb-6 overflow-x-auto">
            @php
                $tabs = [
                    'all' => __('View All'),
                    'messenger' => __('Messenger'),
                    'deals' => __('Deal Chats'),
                    'direct' => __('Direct'),
                    'emails' => __('Emails'),
                ];
            @endphp
            @foreach ($tabs as $key => $label)
                <button
                    wire:click="setTab('{{ $key }}')"
                    wire:key="tab-{{ $key }}"
                    class="px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors {{ $activeTab === $key ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Tab Content --}}
        <div>
            @if ($activeTab === 'all')
                @if ($this->allItems->isEmpty())
                    <div class="text-center py-12">
                        <flux:icon name="chat-bubble-left-right" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('No communications found for this customer.') }}</flux:text>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($this->allItems as $index => $item)
                            <div
                                wire:key="all-{{ $index }}"
                                class="flex items-start gap-3 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                            >
                                <div class="shrink-0 mt-0.5">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center {{ $item['type'] === 'email' ? 'bg-amber-100 dark:bg-amber-900/30' : ($item['channel'] === 'facebook' ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-zinc-100 dark:bg-zinc-800') }}">
                                        <flux:icon :name="$item['icon']" class="w-4 h-4 {{ $item['type'] === 'email' ? 'text-amber-600 dark:text-amber-400' : ($item['channel'] === 'facebook' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500') }}" />
                                    </div>
                                </div>
                                <a href="{{ $item['url'] }}" wire:navigate class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium text-sm">{{ $item['label'] }}</flux:text>
                                        <flux:badge size="sm" color="{{ $item['type'] === 'email' ? 'amber' : ($item['channel'] === 'facebook' ? 'blue' : 'zinc') }}">
                                            {{ $item['type'] === 'email' ? __('Email') : ($item['channel'] === 'facebook' ? __('Messenger') : ucfirst($item['thread_type'] ?? __('Chat'))) }}
                                        </flux:badge>
                                    </div>
                                    <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">{{ $item['preview'] }}</flux:text>
                                </a>
                                <div class="shrink-0 text-right">
                                    @if ($item['date'])
                                        <flux:text size="xs" class="text-zinc-400">{{ $item['date']->diffForHumans() }}</flux:text>
                                    @endif
                                    @if ($item['sender'])
                                        <flux:text size="xs" class="text-zinc-400 mt-0.5">{{ $item['sender'] }}</flux:text>
                                    @endif
                                    @if ($item['type'] === 'email' && isset($item['message_id']) && ($item['direction'] ?? null) === 'inbound')
                                        <flux:button size="xs" icon="arrow-uturn-left" wire:click.prevent="openReplyModal({{ $item['message_id'] }})" class="mt-1">
                                            {{ __('Reply') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

            @elseif ($activeTab === 'messenger')
                @if ($this->messengerThreads->isEmpty())
                    <div class="text-center py-12">
                        <flux:icon name="chat-bubble-oval-left" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('No Messenger conversations with this customer.') }}</flux:text>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($this->messengerThreads as $thread)
                            <a
                                href="{{ route('chat.show', $thread) }}"
                                wire:navigate
                                wire:key="messenger-{{ $thread->id }}"
                                class="flex items-start gap-3 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                            >
                                <div class="shrink-0 mt-0.5">
                                    <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                                        <flux:icon name="chat-bubble-oval-left" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium text-sm">{{ $thread->subject ?? $customer->full_name }}</flux:text>
                                        @if ($thread->messengerConversation?->bot_enabled)
                                            <flux:badge size="sm" color="purple">{{ __('Bot Active') }}</flux:badge>
                                        @endif
                                    </div>
                                    @if ($thread->latestMessage)
                                        <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">
                                            {{ $thread->latestMessage->user?->name ?? __('Unknown') }}: {{ Str::limit($thread->latestMessage->content, 80) }}
                                        </flux:text>
                                    @else
                                        <flux:text size="sm" class="text-zinc-400 mt-0.5">{{ __('No messages yet') }}</flux:text>
                                    @endif
                                </div>
                                <div class="shrink-0">
                                    <flux:text size="xs" class="text-zinc-400">
                                        {{ ($thread->last_message_at ?? $thread->created_at)->diffForHumans() }}
                                    </flux:text>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

            @elseif ($activeTab === 'deals')
                @if ($this->dealThreads->isEmpty())
                    <div class="text-center py-12">
                        <flux:icon name="briefcase" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('No deal chat threads for this customer.') }}</flux:text>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($this->dealThreads as $thread)
                            <a
                                href="{{ route('chat.show', $thread) }}"
                                wire:navigate
                                wire:key="deal-thread-{{ $thread->id }}"
                                class="flex items-start gap-3 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                            >
                                <div class="shrink-0 mt-0.5">
                                    <div class="w-8 h-8 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                                        <flux:icon name="briefcase" class="w-4 h-4 text-purple-600 dark:text-purple-400" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium text-sm">{{ $thread->deal?->title ?? __('Deal Chat') }}</flux:text>
                                        @if ($thread->deal?->stage)
                                            <flux:badge size="sm" color="zinc">{{ $thread->deal->stage_label }}</flux:badge>
                                        @endif
                                    </div>
                                    @if ($thread->latestMessage)
                                        <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">
                                            {{ $thread->latestMessage->user?->name ?? __('Unknown') }}: {{ Str::limit($thread->latestMessage->content, 80) }}
                                        </flux:text>
                                    @else
                                        <flux:text size="sm" class="text-zinc-400 mt-0.5">{{ __('No messages yet') }}</flux:text>
                                    @endif
                                </div>
                                <div class="shrink-0">
                                    <flux:text size="xs" class="text-zinc-400">
                                        {{ ($thread->last_message_at ?? $thread->created_at)->diffForHumans() }}
                                    </flux:text>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

            @elseif ($activeTab === 'direct')
                @if (! $customer->user_id)
                    <div class="text-center py-12">
                        <flux:icon name="chat-bubble-left-right" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('This customer does not have a portal account. Direct messages require a linked user.') }}</flux:text>
                    </div>
                @elseif ($this->directThreads->isEmpty())
                    <div class="text-center py-12">
                        <flux:icon name="chat-bubble-left-right" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('No direct message threads for this customer.') }}</flux:text>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($this->directThreads as $thread)
                            <a
                                href="{{ route('chat.show', $thread) }}"
                                wire:navigate
                                wire:key="direct-{{ $thread->id }}"
                                class="flex items-start gap-3 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                            >
                                <div class="shrink-0 mt-0.5">
                                    <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                        <flux:icon name="chat-bubble-left-right" class="w-4 h-4 text-green-600 dark:text-green-400" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="font-medium text-sm">
                                        {{ $thread->getDisplayNameFor(auth()->user()) }}
                                    </flux:text>
                                    @if ($thread->latestMessage)
                                        <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">
                                            {{ $thread->latestMessage->user?->name ?? __('Unknown') }}: {{ Str::limit($thread->latestMessage->content, 80) }}
                                        </flux:text>
                                    @else
                                        <flux:text size="sm" class="text-zinc-400 mt-0.5">{{ __('No messages yet') }}</flux:text>
                                    @endif
                                </div>
                                <div class="shrink-0">
                                    <flux:text size="xs" class="text-zinc-400">
                                        {{ ($thread->last_message_at ?? $thread->created_at)->diffForHumans() }}
                                    </flux:text>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

            @elseif ($activeTab === 'emails')
                @if ($this->emailLinks->isEmpty())
                    <div class="text-center py-12">
                        <flux:icon name="envelope" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('No emails linked to this customer.') }}</flux:text>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($this->emailLinks as $link)
                            @if ($link->message)
                                <div
                                    wire:key="email-{{ $link->id }}"
                                    class="flex items-start gap-3 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                                >
                                    <div class="shrink-0 mt-0.5">
                                        <div class="w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                                            <flux:icon name="envelope" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                                        </div>
                                    </div>
                                    <a href="{{ $link->message->email_thread_id ? route('email.inbox', ['threadId' => $link->message->email_thread_id]) : route('email.inbox') }}" wire:navigate class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <flux:text class="font-medium text-sm">{{ $link->message->subject ?? __('(No subject)') }}</flux:text>
                                            <flux:badge size="sm" color="{{ $link->message->direction === 'inbound' ? 'green' : 'blue' }}">
                                                {{ $link->message->direction === 'inbound' ? __('Received') : __('Sent') }}
                                            </flux:badge>
                                        </div>
                                        <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">
                                            {{ Str::limit($link->message->snippet ?? $link->message->body_text ?? '', 80) }}
                                        </flux:text>
                                    </a>
                                    <div class="shrink-0 text-right">
                                        <flux:text size="xs" class="text-zinc-400">
                                            {{ ($link->message->sent_at ?? $link->message->created_at)->diffForHumans() }}
                                        </flux:text>
                                        <flux:text size="xs" class="text-zinc-400 mt-0.5">
                                            {{ $link->message->from_name ?? $link->message->from_address }}
                                        </flux:text>
                                        @if ($link->message->direction === 'inbound')
                                            <flux:button size="xs" icon="arrow-uturn-left" wire:click="openReplyModal({{ $link->message->id }})" class="mt-1">
                                                {{ __('Reply') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- Reply Modal --}}
    <flux:modal wire:model="showReplyModal" class="max-w-5xl w-[75vw]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Reply') }}</flux:heading>

            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <label class="w-12 shrink-0 text-sm text-zinc-500">{{ __('To') }}</label>
                    <flux:input wire:model="replyTo" size="sm" readonly />
                </div>
                <div class="flex items-center gap-2">
                    <label class="w-12 shrink-0 text-sm text-zinc-500">{{ __('Subject') }}</label>
                    <flux:input wire:model="replySubject" size="sm" />
                </div>
            </div>

            <div>
                <flux:textarea wire:model="replyBody" rows="12" />
            </div>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeReplyModal" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="sendReply" variant="primary" icon="paper-airplane">
                    {{ __('Send Reply') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
