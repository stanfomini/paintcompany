<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Services\ChatService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $dealId;

    public string $messageContent = '';

    public function mount(int $dealId): void
    {
        $this->dealId = $dealId;

        $this->ensureCustomerCanAccessDeal();
    }

    #[Computed]
    public function thread(): ?ChatThread
    {
        $deal = Deal::find($this->dealId);

        return $deal?->chatThread;
    }

    #[Computed]
    public function messages()
    {
        if (! $this->thread) {
            return collect();
        }

        return $this->thread->messages()
            ->with(['user'])
            ->where(function ($q) {
                $q->where('is_internal', false)->orWhereNull('is_internal');
            })
            ->latest()
            ->take(10)
            ->get()
            ->reverse()
            ->values();
    }

    public function sendMessage(): void
    {
        $content = trim($this->messageContent);
        if (empty($content)) {
            return;
        }

        $this->ensureCustomerCanAccessDeal();

        $user = auth('customer')->user();
        $deal = Deal::findOrFail($this->dealId);
        $thread = $deal->getOrCreateChatThread();

        if (! $thread->hasParticipant($user)) {
            $thread->addParticipant($user);
        }

        app(ChatService::class)->sendMessage($thread, $user, $content, ['is_internal' => false]);

        $this->messageContent = '';
        unset($this->messages, $this->thread);
    }

    public function getListeners(): array
    {
        $threadId = $this->thread?->id;
        if (! $threadId) {
            return [];
        }

        return [
            "echo-private:chat.thread.{$threadId},.message.sent" => 'onNewMessage',
        ];
    }

    public function onNewMessage(): void
    {
        unset($this->messages, $this->thread);

        $thread = $this->thread;
        if ($thread) {
            app(ChatService::class)->markThreadAsRead($thread, auth('customer')->user());
        }
    }

    private function ensureCustomerCanAccessDeal(): void
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();
        $deal = Deal::findOrFail($this->dealId);

        abort_unless($customer && $deal->customerCanAccess($customer), 403);
    }
}; ?>

<div
    x-data="{
        scrollToBottom() {
            const container = this.$refs.messagesContainer;
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        }
    }"
    x-init="$nextTick(() => scrollToBottom())"
>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden bg-white dark:bg-zinc-900">
        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
            <div class="flex items-center gap-2">
                <flux:icon name="chat-bubble-left-right" class="w-5 h-5 text-zinc-500 dark:text-zinc-400" />
                <flux:heading size="sm">{{ __('Project Chat') }}</flux:heading>
            </div>
            @if ($this->thread)
                <flux:button
                    variant="ghost"
                    size="sm"
                    href="{{ route('customer.chat.show', $this->thread) }}"
                    icon-trailing="arrow-top-right-on-square"
                >
                    {{ __('View All') }}
                </flux:button>
            @endif
        </div>

        {{-- Messages Area --}}
        <div
            x-ref="messagesContainer"
            @message-sent-in-embed.window="$nextTick(() => scrollToBottom())"
            class="max-h-[300px] min-h-[150px] overflow-y-auto p-3 space-y-3"
            x-init="
                const observer = new MutationObserver(() => $nextTick(() => scrollToBottom()));
                observer.observe($el, { childList: true, subtree: true });
            "
        >
            @if ($this->messages->isEmpty())
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <flux:icon name="chat-bubble-left-right" class="w-10 h-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Say hello to your team!') }}
                    </flux:text>
                </div>
            @else
                @foreach ($this->messages as $message)
                    <div
                        wire:key="embed-msg-{{ $message->id }}"
                        @class([
                            'flex gap-2',
                            'flex-row-reverse' => $message->user_id === auth('customer')->id(),
                        ])
                    >
                        {{-- Avatar for other users --}}
                        @if ($message->user_id !== auth('customer')->id() && ! $message->isSystem())
                            <div class="shrink-0 mt-1">
                                <flux:avatar
                                    name="{{ $message->user?->name ?? __('Unknown') }}"
                                    size="xs"
                                />
                            </div>
                        @endif

                        {{-- Message bubble --}}
                        <div @class([
                            'max-w-[75%] min-w-0',
                            'items-end' => $message->user_id === auth('customer')->id(),
                        ])>
                            {{-- Sender name --}}
                            @if ($message->user_id !== auth('customer')->id() && ! $message->isSystem())
                                <div class="flex items-center gap-1.5 mb-0.5">
                                    <flux:text class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        {{ $message->user?->name ?? __('Unknown') }}
                                    </flux:text>
                                    @if ($message->user?->isInternal())
                                        <flux:badge size="sm" color="blue">{{ __('Staff') }}</flux:badge>
                                    @endif
                                </div>
                            @endif

                            <div @class([
                                'rounded-lg px-3 py-2 text-sm',
                                'bg-blue-50 text-zinc-800 dark:bg-blue-600 dark:text-white' => $message->user_id === auth('customer')->id() && ! $message->isSystem(),
                                'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' => $message->user_id !== auth('customer')->id() && ! $message->isSystem(),
                                'bg-zinc-200/60 dark:bg-zinc-700/60 text-zinc-500 dark:text-zinc-400 italic text-center text-xs' => $message->isSystem(),
                            ])>
                                <flux:text class="whitespace-pre-wrap break-words text-sm">{{ $message->content }}</flux:text>
                            </div>

                            {{-- Timestamp --}}
                            <div @class([
                                'mt-0.5',
                                'text-right' => $message->user_id === auth('customer')->id(),
                            ])>
                                <flux:text class="text-[10px] text-zinc-400 dark:text-zinc-500">
                                    {{ $message->created_at->diffForHumans(short: true) }}
                                </flux:text>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Composer --}}
        <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-3">
            <form wire:submit="sendMessage" class="flex items-end gap-2">
                <div class="grow">
                    <flux:input
                        wire:model="messageContent"
                        placeholder="{{ __('Type a message...') }}"
                        autocomplete="off"
                        class="min-h-[44px]"
                    />
                </div>
                <flux:button
                    type="submit"
                    variant="primary"
                    size="sm"
                    icon="paper-airplane"
                    wire:loading.attr="disabled"
                    class="min-h-[44px] min-w-[44px]"
                >
                    <span class="sr-only">{{ __('Send') }}</span>
                </flux:button>
            </form>
        </div>
    </div>
</div>
