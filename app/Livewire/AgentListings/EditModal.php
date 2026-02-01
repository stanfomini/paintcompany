<?php

namespace App\Livewire\AgentListings;

use App\Models\AgentListing;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class EditModal extends Component
{
    public ?AgentListing $listing = null;

    public ?int $agentId = null;

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $status = 'active';

    public ?string $listingPrice = null;

    public string $description = '';

    public string $notes = '';

    public ?string $openHouseDate = null;

    public bool $showModal = false;

    #[On('edit-listing')]
    public function openModal(int $listingId): void
    {
        $this->listing = AgentListing::findOrFail($listingId);

        $this->agentId = $this->listing->agent_id;
        $this->address = $this->listing->address;
        $this->city = $this->listing->city;
        $this->state = $this->listing->state;
        $this->zip = $this->listing->zip;
        $this->status = $this->listing->status;
        $this->listingPrice = $this->listing->listing_price;
        $this->description = $this->listing->description ?? '';
        $this->notes = $this->listing->notes ?? '';
        $this->openHouseDate = $this->listing->open_house_date?->format('Y-m-d\TH:i');

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->listing = null;
        $this->reset([
            'agentId',
            'address',
            'city',
            'state',
            'zip',
            'status',
            'listingPrice',
            'description',
            'notes',
            'openHouseDate',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'agentId' => ['required', 'exists:users,id'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:2'],
            'zip' => ['required', 'string', 'max:10'],
            'status' => ['required', 'in:active,pending,sold,inactive'],
            'listingPrice' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'openHouseDate' => ['nullable', 'date'],
        ]);

        $this->listing->update([
            'agent_id' => $this->agentId,
            'address' => $this->address,
            'city' => $this->city,
            'state' => strtoupper($this->state),
            'zip' => $this->zip,
            'status' => $this->status,
            'listing_price' => $this->listingPrice ?: null,
            'description' => $this->description ?: null,
            'notes' => $this->notes ?: null,
            'open_house_date' => $this->openHouseDate ?: null,
        ]);

        $this->dispatch('listing-updated');
        session()->flash('success', 'Agent listing updated successfully.');
        $this->closeModal();
    }

    public function render(): View
    {
        $agents = User::query()
            ->where('type', 'agent')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.agent-listings.edit-modal', [
            'agents' => $agents,
            'statuses' => AgentListing::STATUSES,
        ]);
    }
}
