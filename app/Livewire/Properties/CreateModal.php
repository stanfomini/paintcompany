<?php

namespace App\Livewire\Properties;

use App\Models\Customer;
use App\Models\Property;
use App\Services\ListingScraperService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateModal extends Component
{
    public string $customerId = '';

    public string $name = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $type = 'residential';

    public string $squareFootage = '';

    public string $yearBuilt = '';

    public string $notes = '';

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
            'customerId',
            'name',
            'address',
            'city',
            'state',
            'zip',
            'type',
            'squareFootage',
            'yearBuilt',
            'notes',
            'importUrl',
            'importError',
            'importSource',
            'importedPhotos',
        ]);
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
            $this->name = $data['name'] ?? '';
            $this->type = $data['type'] ?? 'residential';
            $this->squareFootage = (string) ($data['square_footage'] ?? '');
            $this->yearBuilt = (string) ($data['year_built'] ?? '');
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
            if ($data['lot_size'] ?? null) {
                $noteParts[] = "Lot size: {$data['lot_size']}";
            }
            if ($data['listing_price'] ?? null) {
                $noteParts[] = 'Listing price: $'.number_format($data['listing_price']);
            }
            if ($data['description'] ?? null) {
                $noteParts[] = "\n\nDescription:\n{$data['description']}";
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

    public function getCustomersProperty(): Collection
    {
        return Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['required', 'exists:customers,id'],
            'address' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:50'],
            'zip' => ['required', 'string', 'max:20'],
            'type' => ['required', 'string', 'in:residential,commercial,industrial'],
            'squareFootage' => ['nullable', 'integer', 'min:1'],
            'yearBuilt' => ['nullable', 'integer', 'min:1800', 'max:'.date('Y')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        Property::create([
            'customer_id' => $this->customerId,
            'name' => $this->name ?: null,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'type' => $this->type,
            'square_footage' => $this->squareFootage ?: null,
            'year_built' => $this->yearBuilt ?: null,
            'notes' => $this->notes ?: null,
        ]);

        $this->dispatch('property-created');
        session()->flash('success', 'Property created successfully.');
        $this->closeModal();
    }

    public function render()
    {
        return view('livewire.properties.create-modal');
    }
}
