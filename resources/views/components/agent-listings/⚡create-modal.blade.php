<?php

use App\Models\AgentListing;
use App\Models\User;
use App\Services\ListingScraperService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
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

    #[Computed]
    public function agents()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function statuses(): array
    {
        return AgentListing::STATUSES;
    }

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
}; ?>

<div>
    <flux:button wire:click="openModal" icon="plus">
        Add Listing
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Add Agent Listing</flux:heading>

            <div class="space-y-4">
                @if ($this->scrapingAvailable)
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Import from Listing URL
                        </div>
                        <div class="flex gap-2">
                            <flux:input
                                wire:model="importUrl"
                                placeholder="https://zillow.com/homedetails/..."
                                class="flex-1"
                            />
                            <flux:button
                                type="button"
                                wire:click="importFromUrl"
                                wire:loading.attr="disabled"
                                wire:target="importFromUrl"
                            >
                                <span wire:loading.remove wire:target="importFromUrl">Import</span>
                                <span wire:loading wire:target="importFromUrl">Importing...</span>
                            </flux:button>
                        </div>
                        @if ($importError)
                            <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $importError }}</p>
                        @endif
                        @if ($importSource)
                            <p class="mt-2 text-sm text-green-600 dark:text-green-400">
                                Data imported from {{ ucfirst($importSource) }}. Review the fields below.
                            </p>
                        @endif
                    </div>
                @endif

                <flux:field>
                    <flux:label>Agent</flux:label>
                    <flux:select wire:model="agentId">
                        <option value="">Select Agent...</option>
                        @foreach ($this->agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="agentId" />
                </flux:field>

                <flux:field>
                    <flux:label>Address</flux:label>
                    <flux:input wire:model="address" placeholder="123 Main St" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>City</flux:label>
                        <flux:input wire:model="city" placeholder="Los Angeles" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>State</flux:label>
                        <flux:input wire:model="state" maxlength="2" placeholder="CA" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>ZIP</flux:label>
                        <flux:input wire:model="zip" placeholder="90001" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Listing Price</flux:label>
                        <flux:input wire:model="listingPrice" type="number" step="0.01" placeholder="350000" />
                        <flux:error name="listingPrice" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select wire:model="status">
                            @foreach ($this->statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="status" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Open House Date/Time</flux:label>
                    <flux:input wire:model="openHouseDate" type="datetime-local" />
                    <flux:error name="openHouseDate" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea wire:model="description" rows="3" placeholder="Property description for proposals..." />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>Internal Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="2" placeholder="Internal notes..." />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Create Listing
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
