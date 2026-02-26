<?php

use App\Models\EmailMessage;
use App\Services\ChatService;
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
        ];
    }

    public function refreshCounts(): void
    {
        unset($this->notificationCount, $this->messageCount);
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
        $count = 0;

        if ($user->can('chat.access')) {
            $count += app(ChatService::class)->getUnreadCountForUser($user);
        }

        if ($user->can('email.access')) {
            $account = $user->emailAccount;
            if ($account) {
                $count += EmailMessage::where('email_account_id', $account->id)
                    ->withLabel('inbox')
                    ->unread()
                    ->count();
            }
        }

        return $count;
    }
};
?>

<div wire:poll.30s.visible="refreshCounts">
    {{-- Footer action bar --}}
    <div class="fixed bottom-0 inset-x-0 z-40 lg:pl-64" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
        <div class="flex items-stretch bg-zinc-800 dark:bg-zinc-950">
            {{-- Notifications --}}
            <a href="{{ route('communications.index') }}" wire:navigate
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
            </a>

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

            {{-- Messages --}}
            <a href="{{ route('communications.index') }}" wire:navigate
               class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-blue-400 hover:bg-zinc-700 dark:hover:bg-zinc-900 transition-colors">
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
        </div>
    </div>
</div>
