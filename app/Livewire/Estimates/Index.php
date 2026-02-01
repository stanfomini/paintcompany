<?php

namespace App\Livewire\Estimates;

use App\Models\Deal;
use App\Models\Estimate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public ?int $dealFilter = null;

    public int $perPage = 15;

    #[On('estimate-created')]
    #[On('estimate-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDealFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $estimates = Estimate::query()
            ->with(['deal.customer', 'createdBy'])
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->byStatus($this->statusFilter);
            })
            ->when($this->dealFilter, function ($query) {
                return $query->where('deal_id', $this->dealFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        $deals = Deal::query()
            ->with('customer')
            ->orderBy('title')
            ->get();

        return view('livewire.estimates.index', [
            'estimates' => $estimates,
            'deals' => $deals,
            'statuses' => Estimate::STATUSES,
        ])->layout('layouts.app', ['title' => 'Estimates']);
    }
}
