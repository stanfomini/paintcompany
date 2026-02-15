<?php

use App\Models\Deal;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function getListeners(): array
    {
        if (! auth()->user()?->can('deals.view')) {
            return [];
        }

        return [
            'echo-private:internal-team,lead.created' => 'refreshCount',
            'deal-stage-changed' => 'refreshCount',
        ];
    }

    public function refreshCount(): void
    {
        unset($this->count);
    }

    #[Computed]
    public function count(): int
    {
        return Deal::where('stage', 'lead')->count();
    }
};
?>

<span wire:poll.30s>
    @if ($this->count > 0)
        <span class="inline-flex items-center gap-1 bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 text-[10px] font-semibold rounded-full px-1.5 py-0.5 leading-none">
            {{ $this->count > 99 ? '99+' : $this->count }}
            <span class="hidden sm:inline">{{ __('new') }}</span>
        </span>
    @endif
</span>
