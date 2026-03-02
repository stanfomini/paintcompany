<?php

use App\Models\EmailMessage;
use App\Services\ChatService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:chat.user.{$userId},.thread.created" => 'refreshCount',
            "echo-private:chat.user.{$userId},.message.sent" => 'refreshCount',
            "echo-private:email.user.{$userId},.email.received" => 'refreshCount',
            'message-sent' => 'refreshCount',
            'refresh-notification-counts' => 'refreshCount',
        ];
    }

    public function refreshCount(): void
    {
        unset($this->totalCount);
    }

    #[Computed]
    public function totalCount(): int
    {
        $user = auth()->user();
        $count = 0;

        // Chat unread
        if ($user->can('chat.access')) {
            $count += app(ChatService::class)->getUnreadCountForUser($user);
        }

        // Email unread (all accounts including shared)
        if ($user->can('email.access')) {
            $accountIds = $user->allEmailAccounts()->pluck('id');
            if ($accountIds->isNotEmpty()) {
                $count += EmailMessage::whereIn('email_account_id', $accountIds)
                    ->withLabel('inbox')
                    ->unread()
                    ->count();
            }
        }

        return $count;
    }
};
?>

<span wire:poll.60s.visible="refreshCount">
    @if ($this->totalCount > 0)
        <span class="bg-red-500 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-medium">
            {{ $this->totalCount > 99 ? '99+' : $this->totalCount }}
        </span>
    @endif
</span>
