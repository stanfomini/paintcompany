<?php

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $folder = 'inbox';

    public ?int $selectedThreadId = null;

    public ?int $activeAccountId = null;

    public string $search = '';

    public array $selectedIds = [];

    public bool $selectAll = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function account()
    {
        if ($this->activeAccountId) {
            return EmailAccount::find($this->activeAccountId);
        }

        return auth()->user()->emailAccount;
    }

    #[Computed]
    public function threads()
    {
        $account = $this->account;
        if (! $account) {
            return collect();
        }

        $query = EmailThread::where('email_account_id', $account->id)
            ->withLabel($this->folder)
            ->with(['latestMessage'])
            ->withCount(['messages as unread_count' => function ($q) {
                $q->where('is_read', false)->where('direction', 'inbound');
            }])
            ->orderByDesc('last_message_at');

        if ($this->search) {
            $query->whereHas('messages', function ($q) {
                $q->search($this->search);
            });
        }

        return $query->paginate(30);
    }

    public function selectThread(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        $this->dispatch('thread-selected', threadId: $threadId)->to('email.inbox');
    }

    public function toggleSelect(int $threadId): void
    {
        if (in_array($threadId, $this->selectedIds)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$threadId]));
        } else {
            $this->selectedIds[] = $threadId;
        }
    }

    public function bulkMarkRead(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        EmailMessage::whereIn('email_thread_id', $this->selectedIds)
            ->where('email_account_id', $this->account->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        $this->selectedIds = [];
    }

    public function bulkTrash(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $emailService = app(\App\Services\EmailService::class);

        $messages = EmailMessage::whereIn('email_thread_id', $this->selectedIds)
            ->where('email_account_id', $this->account->id)
            ->get();

        foreach ($messages as $message) {
            $emailService->moveToTrash($message);
        }

        $this->selectedIds = [];
    }

    public function bulkArchive(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $inboxLabel = \App\Models\EmailLabel::where('slug', 'inbox')->whereNull('email_account_id')->first();
        $archiveLabel = \App\Models\EmailLabel::where('slug', 'archive')->whereNull('email_account_id')->first();

        if (! $inboxLabel || ! $archiveLabel) {
            return;
        }

        $messages = EmailMessage::whereIn('email_thread_id', $this->selectedIds)
            ->where('email_account_id', $this->account->id)
            ->get();

        foreach ($messages as $message) {
            $message->labels()->detach($inboxLabel->id);
            $message->labels()->syncWithoutDetaching([$archiveLabel->id]);
        }

        $this->selectedIds = [];
    }

    #[On('folder-changed')]
    public function onFolderChanged(string $folder): void
    {
        $this->folder = $folder;
        $this->selectedThreadId = null;
        $this->selectedIds = [];
        $this->resetPage();
    }

    #[On('email-list-refresh')]
    public function refreshList(): void
    {
        unset($this->threads);
    }
};
?>

<div class="flex h-full flex-col">
    {{-- Search Bar --}}
    <div class="border-b border-zinc-200 p-3 dark:border-zinc-700">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
            placeholder="{{ __('Search emails...') }}" size="sm" />
    </div>

    {{-- Bulk Actions --}}
    @if (count($selectedIds) > 0)
        <div class="flex items-center gap-2 border-b border-zinc-200 bg-blue-50 px-3 py-2 dark:border-zinc-700 dark:bg-blue-900/20">
            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ count($selectedIds) }} {{ __('selected') }}</span>
            <flux:button size="xs" wire:click="bulkMarkRead">{{ __('Mark Read') }}</flux:button>
            <flux:button size="xs" wire:click="bulkArchive">{{ __('Archive') }}</flux:button>
            <flux:button size="xs" variant="danger" wire:click="bulkTrash">{{ __('Delete') }}</flux:button>
        </div>
    @endif

    {{-- Email List --}}
    <div class="flex-1 overflow-y-auto">
        @forelse ($this->threads as $thread)
            @php $latest = $thread->latestMessage; @endphp
            <div
                wire:key="thread-{{ $thread->id }}"
                wire:click="selectThread({{ $thread->id }})"
                class="flex cursor-pointer items-start gap-3 border-b border-zinc-100 px-3 py-3 transition-colors dark:border-zinc-800
                    {{ $selectedThreadId === $thread->id
                        ? 'bg-blue-50 dark:bg-blue-900/20'
                        : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}
                    {{ $thread->unread_count > 0 ? 'border-l-2 border-l-blue-500' : '' }}"
            >
                {{-- Checkbox --}}
                <div class="pt-1" wire:click.stop="toggleSelect({{ $thread->id }})">
                    <flux:checkbox :checked="in_array($thread->id, $selectedIds)" />
                </div>

                <div class="min-w-0 flex-1">
                    {{-- Sender & Time --}}
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm {{ $thread->unread_count > 0 ? 'font-semibold text-zinc-900 dark:text-white' : 'text-zinc-700 dark:text-zinc-300' }}">
                            {{ $latest?->from_name ?? $latest?->from_address ?? __('Unknown') }}
                        </span>
                        <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $latest?->sent_at?->shortRelativeDiffForHumans() }}
                        </span>
                    </div>

                    {{-- Subject --}}
                    <p class="truncate text-sm {{ $thread->unread_count > 0 ? 'font-medium text-zinc-800 dark:text-zinc-200' : 'text-zinc-600 dark:text-zinc-400' }}">
                        {{ $thread->subject }}
                    </p>

                    {{-- Snippet --}}
                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-500">
                        {{ $latest?->snippet }}
                    </p>
                </div>

                {{-- Star --}}
                @if ($latest?->is_starred)
                    <flux:icon name="star" class="size-4 shrink-0 text-yellow-400" variant="solid" />
                @endif
            </div>
        @empty
            <div class="flex items-center justify-center py-12 text-zinc-400 dark:text-zinc-500">
                <div class="text-center">
                    <flux:icon name="inbox" class="mx-auto mb-2 size-8" />
                    <p class="text-sm">{{ __('No emails') }}</p>
                </div>
            </div>
        @endforelse

        {{-- Pagination --}}
        <div class="p-3">
            {{ $this->threads->links() }}
        </div>
    </div>
</div>
