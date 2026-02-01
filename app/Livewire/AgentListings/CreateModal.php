<?php

namespace App\Livewire\AgentListings;

use App\Models\AgentListing;
use App\Models\User;
use App\Services\ListingScraperService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateModal extends Component
{
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

    // URL Import fields
    public string $importUrl = '';

    public bool $isImporting = false;

    public ?string $importError = null;

    public ?string $importSource = null;

    /** @var array<string> */
    public array $importedPhotos = [];

    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
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
            'importUrl',
            'importError',
            'importSource',
            'importedPhotos',
        ]);
        $this->status = 'active';
        $this->isImporting = false;
        $this->resetValidation();
    }

    /**
     * Check if scraping service is available.
     */
    #[Computed]
    public function scrapingAvailable(): bool
    {
        return app(ListingScraperService::class)->isAvailable();
    }

    /**
     * Import data from a listing URL.
     */
    public function importFromUrl(): void
    {
        $this->importError = null;

        if (empty($this->importUrl)) {
            $this->importError = 'Please enter a listing URL.';

            return;
        }

        $this->isImporting = true;

        try {
            $scraper = app(ListingScraperService::class);
            $result = $scraper->scrapeAndMap($this->importUrl);

            if (! $result['success']) {
                $this->importError = $result['error'];
                $this->isImporting = false;

                return;
            }

            $data = $result['data'];

            // Populate form fields
            $this->address = $data['address'] ?? '';
            $this->city = $data['city'] ?? '';
            $this->state = $data['state'] ?? '';
            $this->zip = $data['zip'] ?? '';
            $this->listingPrice = (string) ($data['listing_price'] ?? '');
            $this->description = $data['description'] ?? '';
            $this->importSource = $data['source'] ?? null;
            $this->importedPhotos = $data['photos'] ?? [];

            // Add scraped info to notes
            $noteParts = [];
            if ($data['bedrooms'] ?? null) {
                $noteParts[] = "Bedrooms: {$data['bedrooms']}";
            }
            if ($data['bathrooms'] ?? null) {
                $noteParts[] = "Bathrooms: {$data['bathrooms']}";
            }
            if ($data['square_footage'] ?? null) {
                $noteParts[] = 'Square footage: '.number_format($data['square_footage']);
            }
            if ($data['lot_size'] ?? null) {
                $noteParts[] = "Lot size: {$data['lot_size']}";
            }
            if ($data['year_built'] ?? null) {
                $noteParts[] = "Year built: {$data['year_built']}";
            }
            if ($data['mls_number'] ?? null) {
                $noteParts[] = "MLS #: {$data['mls_number']}";
            }
            if (! empty($noteParts)) {
                $this->notes = "Imported from {$data['source']}\n".implode("\n", $noteParts);
            }

            $this->dispatch('import-success', message: 'Data imported successfully! Review and adjust the fields as needed.');
        } catch (\Exception $e) {
            $this->importError = 'An error occurred while importing. Please try again.';
        } finally {
            $this->isImporting = false;
        }
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

        AgentListing::create([
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

        $this->dispatch('listing-created');
        session()->flash('success', 'Agent listing created successfully.');
        $this->closeModal();
    }

    public function render(): View
    {
        $agents = User::query()
            ->where('type', 'agent')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.agent-listings.create-modal', [
            'agents' => $agents,
            'statuses' => AgentListing::STATUSES,
        ]);
    }
}
