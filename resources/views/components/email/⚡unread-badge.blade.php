<?php

use App\Models\EmailMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:email.user.{$userId},.email.received" => '$refresh',
            "echo-private:email.user.{$userId},.email.sent" => '$refresh',
        ];
    }

    #[Computed]
    public function count(): int
    {
        $account = auth()->user()?->emailAccount;
        if (! $account) {
            return 0;
        }

        return EmailMessage::where('email_account_id', $account->id)
            ->withLabel('inbox')
            ->unread()
            ->count();
    }
};
?>

<span>
    @if ($this->count > 0)
        <flux:badge size="sm" color="blue">{{ $this->count }}</flux:badge>
    @endif
</span>
