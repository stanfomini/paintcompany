<?php

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $threadId;

    public ?int $activeAccountId = null;

    public function mount(int $threadId, ?int $activeAccountId = null): void
    {
        $this->threadId = $threadId;
        $this->activeAccountId = $activeAccountId;
        $this->markThreadAsRead();
    }

    #[Computed]
    public function thread(): ?EmailThread
    {
        return EmailThread::with(['messages' => function ($q) {
            $q->with(['attachments', 'crmLinks.linkable'])
                ->orderBy('sent_at', 'asc');
        }])->find($this->threadId);
    }

    #[Computed]
    public function account()
    {
        if ($this->activeAccountId) {
            return EmailAccount::find($this->activeAccountId);
        }

        return auth()->user()->emailAccount;
    }

    public function markThreadAsRead(): void
    {
        $account = $this->account;
        if (! $account) {
            return;
        }

        EmailMessage::where('email_thread_id', $this->threadId)
            ->where('email_account_id', $account->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    public function toggleStar(int $messageId): void
    {
        $message = EmailMessage::find($messageId);
        $message?->toggleStar();
    }

    public function reply(int $messageId): void
    {
        $this->dispatch('compose-reply', messageId: $messageId, mode: 'reply')->to('email.inbox');
    }

    public function replyAll(int $messageId): void
    {
        $this->dispatch('compose-reply', messageId: $messageId, mode: 'reply-all')->to('email.inbox');
    }

    public function forward(int $messageId): void
    {
        $this->dispatch('compose-reply', messageId: $messageId, mode: 'forward')->to('email.inbox');
    }

    public function trash(int $messageId): void
    {
        $message = EmailMessage::find($messageId);
        if ($message) {
            app(\App\Services\EmailService::class)->moveToTrash($message);
            $this->dispatch('thread-selected', threadId: 0)->to('email.inbox');
        }
    }
};
?>

<div class="flex h-full flex-col">
    @if ($this->thread)
        {{-- Thread Header --}}
        <div class="border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-700 sm:px-4 sm:py-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="min-w-0 truncate text-base font-semibold text-zinc-900 dark:text-white sm:text-lg">
                    {{ $this->thread->subject }}
                </h2>
                <div class="flex shrink-0 items-center gap-1">
                    <flux:button size="xs" icon="arrow-uturn-left" wire:click="reply({{ $this->thread->messages->last()?->id }})" title="{{ __('Reply') }}" />
                    <flux:button size="xs" icon="arrow-uturn-right" wire:click="forward({{ $this->thread->messages->last()?->id }})" title="{{ __('Forward') }}" />
                    <flux:button size="xs" icon="trash" variant="danger" wire:click="trash({{ $this->thread->messages->last()?->id }})" title="{{ __('Delete') }}" class="hidden sm:inline-flex" />
                </div>
            </div>
        </div>

        {{-- Messages --}}
        <div class="flex-1 space-y-3 overflow-y-auto p-3 sm:space-y-4 sm:p-4">
            @foreach ($this->thread->messages as $message)
                <div wire:key="msg-{{ $message->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700"
                    x-data="{ expanded: {{ $loop->last ? 'true' : 'false' }} }">

                    {{-- Message Header (clickable to expand/collapse) --}}
                    <div class="flex cursor-pointer items-start gap-2 px-3 py-2.5 sm:items-center sm:gap-3 sm:px-4 sm:py-3" @click="expanded = !expanded">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-medium text-blue-700 dark:bg-blue-900/50 dark:text-blue-400 sm:mt-0">
                            {{ strtoupper(substr($message->from_name ?? $message->from_address, 0, 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $message->from_name ?? $message->from_address }}
                                </span>
                                <span class="shrink-0 text-xs text-zinc-500">{{ $message->sent_at?->format('M j, g:i A') }}</span>
                            </div>
                            <span class="hidden text-xs text-zinc-500 sm:inline">&lt;{{ $message->from_address }}&gt;</span>
                            <p class="truncate text-xs text-zinc-500" x-show="!expanded">
                                {{ $message->snippet }}
                            </p>
                            <p class="truncate text-xs text-zinc-500 sm:truncate-none" x-show="expanded">
                                {{ __('To') }}: {{ collect($message->to_addresses)->pluck('address')->implode(', ') }}
                                @if ($message->cc_addresses)
                                    | {{ __('CC') }}: {{ collect($message->cc_addresses)->pluck('address')->implode(', ') }}
                                @endif
                            </p>
                        </div>
                        <button wire:click.stop="toggleStar({{ $message->id }})" class="shrink-0 p-1">
                            <flux:icon name="star" class="size-4 {{ $message->is_starred ? 'text-yellow-400' : 'text-zinc-300 dark:text-zinc-600' }}"
                                :variant="$message->is_starred ? 'solid' : 'outline'" />
                        </button>
                    </div>

                    {{-- Message Body --}}
                    <div x-show="expanded" x-collapse class="border-t border-zinc-100 px-3 py-3 dark:border-zinc-800 sm:px-4">
                        <div class="prose prose-sm dark:prose-invert max-w-none overflow-x-auto">
                            {!! $message->body_html !!}
                        </div>

                        {{-- Attachments --}}
                        @if ($message->attachments->count() > 0)
                            <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                                <p class="mb-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                    {{ __('Attachments') }} ({{ $message->attachments->count() }})
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($message->attachments->where('is_inline', false) as $attachment)
                                        <a href="{{ route('email.attachment.download', $attachment) }}"
                                            class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-xs hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                            <flux:icon name="paper-clip" class="size-3.5" />
                                            <span class="max-w-[150px] truncate">{{ $attachment->original_filename }}</span>
                                            <span class="text-zinc-400">({{ number_format($attachment->file_size / 1024) }}KB)</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- CRM Links --}}
                        @if ($message->crmLinks->count() > 0)
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($message->crmLinks as $link)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                        <flux:icon name="link" class="size-3" />
                                        {{ class_basename($link->linkable_type) }}: {{ $link->linkable?->name ?? $link->linkable?->title ?? '#' . $link->linkable_id }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Per-message Reply Actions --}}
                        <div class="mt-3 flex flex-wrap gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <flux:button size="xs" icon="arrow-uturn-left" wire:click="reply({{ $message->id }})">{{ __('Reply') }}</flux:button>
                            <flux:button size="xs" icon="arrows-pointing-out" wire:click="replyAll({{ $message->id }})">{{ __('Reply All') }}</flux:button>
                            <flux:button size="xs" icon="arrow-uturn-right" wire:click="forward({{ $message->id }})">{{ __('Forward') }}</flux:button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex flex-1 items-center justify-center text-zinc-400">
            {{ __('Thread not found') }}
        </div>
    @endif
</div>
