<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use App\Services\ListingScraperService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
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

    // Optional agent/listing fields
    public string $agentId = '';

    public string $source = '';

    public string $listingStatus = '';

    public string $listingPrice = '';

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
            'agentId',
            'source',
            'listingStatus',
            'listingPrice',
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

    #[Computed]
    public function agents(): Collection
    {
        return User::where('type', 'agent')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['nullable', 'exists:customers,id'],
            'address' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:50'],
            'zip' => ['required', 'string', 'max:20'],
            'type' => ['required', 'string', 'in:residential,commercial,industrial'],
            'squareFootage' => ['nullable', 'integer', 'min:1'],
            'yearBuilt' => ['nullable', 'integer', 'min:1800', 'max:'.date('Y')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'agentId' => ['nullable', 'exists:users,id'],
            'source' => ['nullable', 'string', 'in:direct,agent_referral,open_house,manual'],
            'listingStatus' => ['nullable', 'string', 'in:active,pending,sold,inactive'],
            'listingPrice' => ['nullable', 'numeric', 'min:0'],
        ]);

        Property::create([
            'customer_id' => $this->customerId ?: null,
            'name' => $this->name ?: null,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'type' => $this->type,
            'square_footage' => $this->squareFootage ?: null,
            'year_built' => $this->yearBuilt ?: null,
            'notes' => $this->notes ?: null,
            'agent_id' => $this->agentId ?: null,
            'source' => $this->source ?: null,
            'listing_status' => $this->listingStatus ?: null,
            'listing_price' => $this->listingPrice ?: null,
        ]);

        $this->dispatch('property-created');
        session()->flash('success', 'Property created successfully.');
        $this->closeModal();
    }
}; ?>

<div>
    <flux:button wire:click="openModal" icon="plus">
        Add Property
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Add New Property</flux:heading>

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
                    <flux:label>Customer</flux:label>
                    <flux:select wire:model="customerId">
                        <option value="">None (agent listing)</option>
                        @foreach ($this->customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->first_name }} {{ $customer->last_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="customerId" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Agent (optional)</flux:label>
                        <flux:select wire:model="agentId">
                            <option value="">None</option>
                            @foreach ($this->agents as $agent)
                                <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="agentId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Source (optional)</flux:label>
                        <flux:select wire:model="source">
                            <option value="">None</option>
                            @foreach (\App\Models\Property::SOURCES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="source" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Listing Status (optional)</flux:label>
                        <flux:select wire:model="listingStatus">
                            <option value="">None</option>
                            @foreach (\App\Models\Property::LISTING_STATUSES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="listingStatus" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Listing Price (optional)</flux:label>
                        <flux:input wire:model="listingPrice" type="number" step="0.01" placeholder="0.00" />
                        <flux:error name="listingPrice" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Property Name (optional)</flux:label>
                    <flux:input wire:model="name" placeholder="e.g., Main Office Building" />
                    <flux:error name="name" />
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
                        <flux:input wire:model="state" placeholder="CA" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>ZIP</flux:label>
                        <flux:input wire:model="zip" placeholder="90001" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Property Type</flux:label>
                    <flux:select wire:model="type">
                        <option value="residential">Residential</option>
                        <option value="commercial">Commercial</option>
                        <option value="industrial">Industrial</option>
                    </flux:select>
                    <flux:error name="type" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Square Footage</flux:label>
                        <flux:input wire:model="squareFootage" type="number" placeholder="2500" />
                        <flux:error name="squareFootage" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Year Built</flux:label>
                        <flux:input wire:model="yearBuilt" type="number" placeholder="1990" />
                        <flux:error name="yearBuilt" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="3" placeholder="Any additional notes..." />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Create Property
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
