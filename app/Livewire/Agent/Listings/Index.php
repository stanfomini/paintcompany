<?php

namespace App\Livewire\Agent\Listings;

use App\Models\AgentListing;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.agent', ['title' => 'My Listings'])]
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', AgentListing::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[On('listing-created')]
    #[On('listing-updated')]
    public function refreshList(): void
    {
        // Component will re-render automatically
    }

    public function delete(int $id): void
    {
        $listing = AgentListing::findOrFail($id);

        $this->authorize('delete', $listing);

        $listing->delete();

        session()->flash('success', 'Listing archived successfully.');
    }

    public function render(): View
    {
        $agent = auth('agent')->user();

        $listings = AgentListing::query()
            ->forAgent($agent)
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->ofStatus($this->statusFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.agent.listings.index', [
            'listings' => $listings,
            'statuses' => AgentListing::STATUSES,
        ]);
    }
}
