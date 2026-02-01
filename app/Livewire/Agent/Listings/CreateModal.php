<?php

namespace App\Livewire\Agent\Listings;

use App\Models\AgentListing;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class CreateModal extends Component
{
    use AuthorizesRequests;

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

    public function openModal(): void
    {
        $this->authorize('create', AgentListing::class);

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
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
        $this->status = 'active';
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('create', AgentListing::class);

        $this->validate([
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

        $agent = auth('agent')->user();

        AgentListing::create([
            'agent_id' => $agent->id,
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

        $this->dispatch('listing-created');
        session()->flash('success', 'Listing created successfully.');
        $this->closeModal();
    }

    public function render(): View
    {
        return view('livewire.agent.listings.create-modal', [
            'statuses' => AgentListing::STATUSES,
        ]);
    }
}
