<?php

namespace App\Livewire\Agent\Listings;

use App\Models\AgentListing;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.agent', ['title' => 'Listing Details'])]
class Show extends Component
{
    use AuthorizesRequests;

    public AgentListing $agentListing;

    public bool $showDeleteModal = false;

    public function mount(AgentListing $agentListing): void
    {
        $this->authorize('view', $agentListing);

        $this->agentListing = $agentListing;
    }

    #[On('listing-updated')]
    public function refreshListing(): void
    {
        $this->agentListing->refresh();
    }

    public function confirmDelete(): void
    {
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->agentListing);

        $this->agentListing->delete();

        session()->flash('success', 'Listing archived successfully.');

        $this->redirect(route('agent.listings.index'), navigate: true);
    }

    public function render(): View
    {
        $referredCustomersCount = $this->agentListing->referredCustomers()->count();

        return view('livewire.agent.listings.show', [
            'listing' => $this->agentListing,
            'referredCustomersCount' => $referredCustomersCount,
        ]);
    }
}
