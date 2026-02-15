<?php

use App\Models\Job;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function getListeners(): array
    {
        if (! auth()->user()?->can('jobs.view')) {
            return [];
        }

        $userId = auth()->id();

        return [
            "echo-private:App.Models.User.{$userId},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated" => 'handleNotification',
            'job-stage-changed' => 'refreshCount',
        ];
    }

    public function handleNotification(array $data): void
    {
        if (($data['type'] ?? '') === 'App\\Notifications\\JobCreated') {
            unset($this->count);
        }
    }

    public function refreshCount(): void
    {
        unset($this->count);
    }

    #[Computed]
    public function count(): int
    {
        return Job::where('stage', 'estimate_accepted')->count();
    }
};
?>

<span wire:poll.30s>
    @if ($this->count > 0)
        <span class="inline-flex items-center gap-1 bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300 text-[10px] font-semibold rounded-full px-1.5 py-0.5 leading-none">
            {{ $this->count > 99 ? '99+' : $this->count }}
            <span class="hidden sm:inline">{{ __('accepted') }}</span>
        </span>
    @endif
</span>
