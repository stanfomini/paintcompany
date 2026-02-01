<?php

namespace App\Livewire\Agent\Deals;

use App\Models\Deal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $stageFilter = '';

    public int $perPage = 15;

    /**
     * Default commission rate for agents (10%).
     */
    protected float $commissionRate = 0.10;

    #[On('deal-created')]
    #[On('deal-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStageFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Get the badge color for a stage.
     */
    public function getStageBadgeColor(string $stage): string
    {
        return match ($stage) {
            'lead' => 'blue',
            'quoted' => 'purple',
            'scheduled' => 'yellow',
            'in_progress' => 'orange',
            'completed' => 'green',
            'paid' => 'emerald',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }

    /**
     * Calculate commission for a deal value.
     */
    public function calculateCommission(?float $value): float
    {
        if ($value === null) {
            return 0;
        }

        return $value * $this->commissionRate;
    }

    public function render(): View
    {
        $deals = Deal::query()
            ->where('agent_id', auth('agent')->id())
            ->with(['customer'])
            ->search($this->search)
            ->when($this->stageFilter, function ($query) {
                return $query->byStage($this->stageFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.agent.deals.index', [
            'deals' => $deals,
            'stages' => Deal::STAGES,
            'commissionRate' => $this->commissionRate,
        ])->layout('components.layouts.agent', ['title' => 'My Deals']);
    }
}
