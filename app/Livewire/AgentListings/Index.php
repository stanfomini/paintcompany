<?php

namespace App\Livewire\AgentListings;

use App\Models\AgentListing;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public ?int $agentFilter = null;

    public int $perPage = 15;

    #[On('listing-created')]
    #[On('listing-updated')]
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

    public function updatedAgentFilter(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $listing = AgentListing::findOrFail($id);
        $listing->delete();

        session()->flash('success', 'Listing archived successfully.');
    }

    public function render(): View
    {
        $listings = AgentListing::query()
            ->with('agent')
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->ofStatus($this->statusFilter);
            })
            ->when($this->agentFilter, function ($query) {
                return $query->where('agent_id', $this->agentFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.agent-listings.index', [
            'listings' => $listings,
            'statuses' => AgentListing::STATUSES,
        ])->layout('layouts.app', ['title' => 'Agent Listings']);
    }
}
