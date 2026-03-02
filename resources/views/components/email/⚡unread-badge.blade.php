<?php

use App\Models\EmailMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function getListeners(): array
    {
        if (! auth()->user()?->can('email.access')) {
            return [];
        }

        $userId = auth()->id();

        return [
            "echo-private:email.user.{$userId},.email.received" => 'refreshCount',
            "echo-private:email.user.{$userId},.email.sent" => 'refreshCount',
            'refresh-notification-counts' => 'refreshCount',
        ];
    }

    public function refreshCount(): void
    {
        unset($this->count);
    }

    #[Computed]
    public function count(): int
    {
        $user = auth()->user();
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

<span wire:poll.60s.visible="refreshCount">
    @if ($this->count > 0)
        <span class="bg-red-500 text-white text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-medium">
            {{ $this->count > 99 ? '99+' : $this->count }}
        </span>
    @endif
</span>
