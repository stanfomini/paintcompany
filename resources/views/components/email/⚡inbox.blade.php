<?php

use App\Models\EmailAccount;
use App\Models\EmailLabel;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('email.access')) {
            abort(403);
        }
    }

    #[Url]
    public string $folder = 'inbox';

    #[Url]
    public ?int $threadId = null;

    public bool $composing = false;

    public ?int $replyToId = null;

    public string $composeMode = 'new'; // new, reply, reply-all, forward

    public bool $showNewEmailToast = false;

    public ?string $newEmailFrom = null;

    public ?string $newEmailSubject = null;

    public function mount(): void
    {
        $account = $this->account;

        // Redirect to setup if no email account
        if (! $account) {
            $this->redirect(route('email.setup'));

            return;
        }
    }

    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            'email-sent' => 'onEmailAction',
            'email-drafted' => 'onEmailAction',
            'thread-selected' => 'onThreadSelected',
            'folder-changed' => 'onFolderChanged',
            'start-compose' => 'onStartCompose',
            'compose-reply' => 'onComposeReply',
            "echo-private:email.user.{$userId},.email.received" => 'onEmailReceived',
            "echo-private:email.user.{$userId},.email.sent" => 'onEmailSentBroadcast',
        ];
    }

    #[Computed]
    public function account(): ?EmailAccount
    {
        return auth()->user()->emailAccount;
    }

    #[Computed]
    public function unreadCount(): int
    {
        if (! $this->account) {
            return 0;
        }

        return EmailMessage::where('email_account_id', $this->account->id)
            ->withLabel('inbox')
            ->unread()
            ->count();
    }

    public function onEmailReceived(array $event = []): void
    {
        $this->newEmailFrom = $event['from_name'] ?? $event['from_address'] ?? 'Someone';
        $this->newEmailSubject = $event['subject'] ?? 'New email';
        $this->showNewEmailToast = true;

        $this->dispatch('email-list-refresh');
        $this->dispatch('sidebar-refresh');
    }

    public function onEmailSentBroadcast(array $event = []): void
    {
        $this->dispatch('email-list-refresh');
        $this->dispatch('sidebar-refresh');
    }

    public function dismissToast(): void
    {
        $this->showNewEmailToast = false;
    }

    public function selectThread(int $threadId): void
    {
        $this->threadId = $threadId;
        $this->composing = false;
    }

    public function startCompose(string $mode = 'new', ?int $replyToId = null): void
    {
        $this->composing = true;
        $this->composeMode = $mode;
        $this->replyToId = $replyToId;
        $this->threadId = null;
    }

    public function closeCompose(): void
    {
        $this->composing = false;
        $this->threadId = null;
        $this->replyToId = null;
        $this->composeMode = 'new';
    }

    public function onEmailAction(): void
    {
        $this->composing = false;
        $this->replyToId = null;
    }

    public function onThreadSelected(int $threadId): void
    {
        $this->selectThread($threadId);
    }

    public function onFolderChanged(string $folder): void
    {
        $this->setFolder($folder);
    }

    public function onStartCompose(): void
    {
        $this->startCompose('new');
    }

    public function onComposeReply(int $messageId, string $mode = 'reply'): void
    {
        $this->startCompose($mode, $messageId);
    }

    public function setFolder(string $folder): void
    {
        $this->folder = $folder;
        $this->threadId = null;
        $this->composing = false;
    }
};
?>

<div class="relative flex h-[calc(100vh-4rem)] flex-col overflow-hidden md:h-[calc(100vh-5rem)] md:flex-row" x-data="{ toastVisible: $wire.entangle('showNewEmailToast'), showMobileFolders: false }">
    {{-- Mobile Top Bar (visible on small screens when viewing inbox list) --}}
    @if (! $threadId && ! $composing)
        <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-700 md:hidden">
            <button @click="showMobileFolders = !showMobileFolders" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                <flux:icon name="bars-3" class="size-5" />
                <span class="capitalize">{{ $folder }}</span>
            </button>
            <flux:button size="sm" icon="pencil-square" variant="primary" wire:click="startCompose('new')">
                {{ __('Compose') }}
            </flux:button>
        </div>
    @endif

    {{-- Mobile Folder Dropdown --}}
    <div x-show="showMobileFolders" x-transition @click.away="showMobileFolders = false"
        x-on:mobile-folders-close.window="showMobileFolders = false"
        class="absolute inset-x-0 top-12 z-40 border-b border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900 md:hidden" x-cloak>
        @livewire('email.folder-sidebar', ['currentFolder' => $folder], key('sidebar-mobile'))
    </div>

    {{-- Desktop Folder Sidebar --}}
    <div class="hidden w-56 shrink-0 border-r border-zinc-200 dark:border-zinc-700 lg:block">
        @livewire('email.folder-sidebar', ['currentFolder' => $folder], key('sidebar'))
    </div>

    {{-- Email List --}}
    <div class="min-h-0 w-full flex-1 shrink-0 border-r border-zinc-200 dark:border-zinc-700 md:w-80 md:flex-none lg:w-96 {{ $threadId || $composing ? 'hidden md:block' : '' }}">
        @livewire('email.email-list', ['folder' => $folder, 'selectedThreadId' => $threadId], key('list-' . $folder))
    </div>

    {{-- Reading Pane / Compose --}}
    <div class="hidden min-h-0 flex-1 flex-col md:flex {{ $threadId || $composing ? '!flex' : '' }}">
        {{-- Mobile back bar (visible on small screens when viewing thread/compose) --}}
        <div class="flex items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700 md:hidden">
            <flux:button size="sm" icon="arrow-left" wire:click="closeCompose" />
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                @if ($composing)
                    {{ __('Back') }}
                @else
                    {{ __('Back to Inbox') }}
                @endif
            </span>
        </div>

        @if ($composing)
            @livewire('email.compose-form', [
                'mode' => $composeMode,
                'replyToId' => $replyToId,
            ], key('compose-' . $replyToId . '-' . $composeMode))
        @elseif ($threadId)
            @livewire('email.thread-view', ['threadId' => $threadId], key('thread-' . $threadId))
        @else
            <div class="flex flex-1 items-center justify-center text-zinc-400 dark:text-zinc-500">
                <div class="text-center">
                    <flux:icon name="envelope" class="mx-auto mb-3 size-12" />
                    <p>{{ __('Select an email to read') }}</p>
                </div>
            </div>
        @endif
    </div>

    {{-- New Email Toast --}}
    <div
        x-show="toastVisible"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-full opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-full opacity-0"
        x-init="$watch('toastVisible', v => { if (v) setTimeout(() => { toastVisible = false; $wire.dismissToast() }, 5000) })"
        class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border border-blue-200 bg-white p-4 shadow-lg dark:border-blue-800 dark:bg-zinc-800"
        x-cloak
    >
        <div class="flex items-start gap-3">
            <div class="shrink-0 rounded-full bg-blue-100 p-1.5 dark:bg-blue-900/50">
                <flux:icon name="envelope" class="size-4 text-blue-600 dark:text-blue-400" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('New Email') }}</p>
                <p class="truncate text-sm text-zinc-600 dark:text-zinc-400">{{ $newEmailFrom }}</p>
                <p class="truncate text-xs text-zinc-500 dark:text-zinc-500">{{ $newEmailSubject }}</p>
            </div>
            <button @click="toastVisible = false; $wire.dismissToast()" class="shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                <flux:icon name="x-mark" class="size-4" />
            </button>
        </div>
    </div>
</div>
