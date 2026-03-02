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

        return [
            'echo-private:internal-jobs,.job.created' => 'refreshCount',
            'echo-private:internal-jobs,.stage.changed' => 'refreshCount',
            'job-stage-changed' => 'refreshCount',
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
        return Job::where('stage', 'estimate_accepted')->count();
    }
};
?>

<span wire:poll.60s.visible="refreshCount">
    @if ($this->count > 0)
        <span class="inline-flex items-center gap-1 bg-emerald-500 text-white text-[10px] font-semibold rounded-full px-1.5 py-0.5 leading-none">
            {{ $this->count > 99 ? '99+' : $this->count }}
            <span class="hidden sm:inline">{{ __('accepted') }}</span>
        </span>
    @endif
</span>
