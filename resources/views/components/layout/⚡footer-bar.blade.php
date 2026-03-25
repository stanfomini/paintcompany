<?php

use App\Models\EmailMessage;
use App\Services\ChatService;
use App\Services\MissedCallVoicemailService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->isInternal()) {
            abort(403);
        }
    }

    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:App.Models.User.{$userId},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated" => 'refreshCounts',
            "echo-private:chat.user.{$userId},.thread.created" => 'refreshCounts',
            "echo-private:chat.user.{$userId},.message.sent" => 'refreshCounts',
            "echo-private:email.user.{$userId},.email.received" => 'refreshCounts',
            'message-sent' => 'refreshCounts',
            'refresh-notification-counts' => 'refreshCounts',
        ];
    }

    public function refreshCounts(): void
    {
        unset($this->notificationCount, $this->messageCount, $this->emailCount, $this->missedCallCount);
    }

    #[Computed]
    public function notificationCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    #[Computed]
    public function messageCount(): int
    {
        $user = auth()->user();

        if (! $user->can('chat.access')) {
            return 0;
        }

        return app(ChatService::class)->getUnreadCountForUser($user);
    }

    #[Computed]
    public function missedCallCount(): int
    {
        $user = auth()->user();

        if (! $user->can('phone.make_calls')) {
            return 0;
        }

        return app(MissedCallVoicemailService::class)->getUnhandledCountForUser($user);
    }

    #[Computed]
    public function emailCount(): int
    {
        $user = auth()->user();

        if (! $user->can('email.access')) {
            return 0;
        }

        $accountIds = $user->allEmailAccounts()->pluck('id');

        if ($accountIds->isEmpty()) {
            return 0;
        }

        return EmailMessage::whereIn('email_account_id', $accountIds)
            ->withLabel('inbox')
            ->unread()
            ->count();
    }
};
?>

<div wire:poll.60s.visible>
    <div class="flex items-stretch bg-zinc-800 dark:bg-zinc-950">
        {{-- Notifications (toggles notification panel) --}}
        <button
            type="button"
            x-data
            x-on:click="$dispatch('toggle-notification-panel')"
            class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-amber-400 hover:bg-zinc-700 dark:hover:bg-zinc-900 transition-colors">
            <div class="relative">
                <flux:icon name="bell" variant="solid" class="size-5" />
                @if ($this->notificationCount > 0)
                    <span class="absolute -top-1.5 -right-2 bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 flex items-center justify-center px-0.5 font-bold">
                        {{ $this->notificationCount > 99 ? '99+' : $this->notificationCount }}
                    </span>
                @endif
            </div>
            <span class="sr-only sm:not-sr-only sm:inline">{{ __('Notifications') }}</span>
        </button>

        {{-- Record --}}
        @can('media.record')
            <button
                type="button"
                x-data
                x-on:click="$dispatch('open-quick-recorder')"
                class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-red-400 hover:bg-zinc-700 dark:hover:bg-zinc-900 transition-colors border-x border-zinc-700">
                <flux:icon name="video-camera" variant="solid" class="size-5" />
                <span class="sr-only sm:not-sr-only sm:inline">{{ __('Record') }}</span>
            </button>
        @endcan

        {{-- Phone --}}
        @can('phone.make_calls')
            <button
                type="button"
                x-data
                x-on:click="Livewire.dispatch('open-dial-pad')"
                class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-green-400 hover:bg-zinc-700 dark:hover:bg-zinc-900 transition-colors border-l border-zinc-700">
                <div class="relative">
                    <flux:icon name="phone" variant="solid" class="size-5" />
                    @if ($this->missedCallCount > 0)
                        <span class="absolute -top-1.5 -right-2 bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 flex items-center justify-center px-0.5 font-bold">
                            {{ $this->missedCallCount > 99 ? '99+' : $this->missedCallCount }}
                        </span>
                    @endif
                </div>
                <span class="sr-only sm:not-sr-only sm:inline">{{ __('Phone') }}</span>
            </button>
        @endcan

        {{-- Messages --}}
        <a href="{{ route('communications.index') }}" wire:navigate
           class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-blue-400 hover:bg-zinc-700 dark:hover:bg-zinc-900 transition-colors border-l border-zinc-700">
            <div class="relative">
                <flux:icon name="chat-bubble-left-right" variant="solid" class="size-5" />
                @if ($this->messageCount > 0)
                    <span class="absolute -top-1.5 -right-2 bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 flex items-center justify-center px-0.5 font-bold">
                        {{ $this->messageCount > 99 ? '99+' : $this->messageCount }}
                    </span>
                @endif
            </div>
            <span class="sr-only sm:not-sr-only sm:inline">{{ __('Messages') }}</span>
        </a>

        {{-- Email --}}
        @can('email.access')
            <a href="{{ route('email.inbox') }}" wire:navigate
               class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-emerald-400 hover:bg-zinc-700 dark:hover:bg-zinc-900 transition-colors border-l border-zinc-700">
                <div class="relative">
                    <flux:icon name="envelope" variant="solid" class="size-5" />
                    @if ($this->emailCount > 0)
                        <span class="absolute -top-1.5 -right-2 bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 flex items-center justify-center px-0.5 font-bold">
                            {{ $this->emailCount > 99 ? '99+' : $this->emailCount }}
                        </span>
                    @endif
                </div>
                <span class="sr-only sm:not-sr-only sm:inline">{{ __('Email') }}</span>
            </a>
        @endcan
    </div>
</div>
