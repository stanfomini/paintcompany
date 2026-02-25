<?php

use App\Models\EmailAccount;
use App\Models\EmailLabel;
use App\Models\EmailMessage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $currentFolder = 'inbox';

    public ?int $activeAccountId = null;

    #[On('sidebar-refresh')]
    public function refreshCounts(): void
    {
        unset($this->folders);
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
    public function availableAccounts()
    {
        return auth()->user()->allEmailAccounts();
    }

    #[Computed]
    public function folders(): array
    {
        $account = $this->account;
        if (! $account) {
            return [];
        }

        $counts = [
            'inbox' => EmailMessage::where('email_account_id', $account->id)->withLabel('inbox')->unread()->count(),
            'drafts' => EmailMessage::where('email_account_id', $account->id)->withLabel('drafts')->count(),
        ];

        return [
            ['slug' => 'inbox', 'name' => __('Inbox'), 'icon' => 'inbox', 'count' => $counts['inbox']],
            ['slug' => 'starred', 'name' => __('Starred'), 'icon' => 'star', 'count' => 0],
            ['slug' => 'sent', 'name' => __('Sent'), 'icon' => 'paper-airplane', 'count' => 0],
            ['slug' => 'drafts', 'name' => __('Drafts'), 'icon' => 'document', 'count' => $counts['drafts']],
            ['slug' => 'archive', 'name' => __('Archive'), 'icon' => 'archive-box', 'count' => 0],
            ['slug' => 'trash', 'name' => __('Trash'), 'icon' => 'trash', 'count' => 0],
        ];
    }

    public function updatedActiveAccountId($value): void
    {
        $this->dispatch('account-switched', accountId: (int) $value)->to('email.inbox');
        unset($this->account, $this->folders);
    }

    public function selectFolder(string $slug): void
    {
        $this->dispatch('folder-changed', folder: $slug);
        $this->dispatch('mobile-folders-close');
        $this->currentFolder = $slug;
    }

    public function compose(): void
    {
        $this->dispatch('start-compose')->to('email.inbox');
        $this->dispatch('mobile-folders-close');
    }
};
?>

<div class="flex h-full flex-col bg-zinc-50 dark:bg-zinc-900">
    {{-- Account Switcher (only shown when user has multiple accounts) --}}
    @if ($this->availableAccounts->count() > 1)
        <div class="px-3 pt-3 pb-1">
            <flux:select wire:model.live="activeAccountId" size="sm">
                @foreach ($this->availableAccounts as $acc)
                    <option value="{{ $acc->id }}">
                        {{ $acc->isShared() ? '(' . __('Shared') . ') ' : '' }}{{ $acc->address }}@{{ config('email.domain') }}
                    </option>
                @endforeach
            </flux:select>
        </div>
    @endif

    {{-- Compose Button --}}
    <div class="p-3">
        <flux:button variant="primary" class="w-full" icon="pencil-square"
            wire:click="compose">
            {{ __('Compose') }}
        </flux:button>
    </div>

    {{-- Folder List --}}
    <nav class="flex-1 space-y-0.5 px-2">
        @foreach ($this->folders as $folder)
            <button
                wire:click="selectFolder('{{ $folder['slug'] }}')"
                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors
                    {{ $currentFolder === $folder['slug']
                        ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                        : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}"
            >
                <flux:icon :name="$folder['icon']" class="size-4" />
                <span class="flex-1 text-left">{{ $folder['name'] }}</span>
                @if ($folder['count'] > 0)
                    <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/50 dark:text-blue-400">
                        {{ $folder['count'] }}
                    </span>
                @endif
            </button>
        @endforeach
    </nav>

    {{-- Account Info --}}
    @if ($this->account)
        <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                {{ $this->account->full_address }}
            </p>
        </div>
    @endif
</div>
