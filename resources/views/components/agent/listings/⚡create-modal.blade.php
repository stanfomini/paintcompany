<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AgentListingSubmitted;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use AuthorizesRequests;

    #[Computed]
    public function statuses(): array
    {
        return [
            'active' => __('Active'),
            'pending' => __('Pending'),
            'sold' => __('Sold'),
            'inactive' => __('Inactive'),
        ];
    }

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $status = 'active';

    public ?string $listingPrice = null;

    public string $description = '';

    public string $notes = '';

    public ?string $openHouseDate = null;

    public ?int $bedrooms = null;

    public ?int $sqft = null;

    public string $intent = 'selling';

    public bool $showModal = false;

    public function openModal(): void
    {
        $this->authorize('create', Property::class);

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
            'bedrooms',
            'sqft',
            'intent',
        ]);
        $this->status = 'active';
        $this->intent = 'selling';
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('create', Property::class);

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
            'bedrooms' => ['nullable', 'integer', 'min:1', 'max:10'],
            'sqft' => ['nullable', 'integer', 'min:100', 'max:50000'],
            'intent' => ['required', 'in:selling,buying'],
        ]);

        $agent = auth('agent')->user();

        $property = Property::create([
            'agent_id' => $agent->id,
            'source' => 'agent_referral',
            'address' => $this->address,
            'city' => $this->city,
            'state' => strtoupper($this->state),
            'zip' => $this->zip,
            'listing_status' => $this->status,
            'listing_price' => $this->listingPrice ?: null,
            'square_footage' => $this->sqft ?: null,
            'description' => $this->description ?: null,
            'notes' => $this->notes ?: null,
            'open_house_date' => $this->openHouseDate ?: null,
        ]);

        $deal = Deal::create([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'title' => $this->address . ', ' . $this->city,
            'stage' => 'lead',
            'estimated_value' => $this->listingPrice ?: null,
            'listing_url' => null,
            'agent_intent' => $this->intent,
            'listing_value' => $this->listingPrice ?: null,
            'listing_bedrooms' => $this->bedrooms ?: null,
            'listing_sqft' => $this->sqft ?: null,
            'roi_scope' => 'interior',
        ]);

        Estimate::create([
            'deal_id' => $deal->id,
            'created_by' => $agent->id,
            'title' => 'Paint Estimate - ' . $this->address,
            'status' => 'draft',
            'project_type' => 'interior',
        ]);

        // Notify internal team
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new AgentListingSubmitted($deal, $agent));
        }

        $this->dispatch('listing-created');
        session()->flash('success', __('Listing submitted! Our team will prepare your estimate.'));
        $this->closeModal();
    }
}; ?>

<div>
    <flux:button wire:click="openModal" icon="plus">
        {{ __('Add Listing') }}
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">{{ __('Add New Listing') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Address') }}</flux:label>
                    <flux:input wire:model="address" :placeholder="__('123 Main St')" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('City') }}</flux:label>
                        <flux:input wire:model="city" :placeholder="__('Denver')" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:input wire:model="state" maxlength="2" :placeholder="__('CO')" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ZIP') }}</flux:label>
                        <flux:input wire:model="zip" placeholder="80202" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Listing Price') }}</flux:label>
                        <flux:input wire:model="listingPrice" type="number" step="0.01" placeholder="350000" />
                        <flux:error name="listingPrice" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Status') }}</flux:label>
                        <flux:select wire:model="status">
                            @foreach ($this->statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="status" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Bedrooms') }}</flux:label>
                        <flux:select wire:model="bedrooms">
                            <option value="">{{ __('Select...') }}</option>
                            @for ($i = 1; $i <= 8; $i++)
                                <option value="{{ $i }}">{{ $i }}</option>
                            @endfor
                        </flux:select>
                        <flux:error name="bedrooms" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Approx. Square Footage') }}</flux:label>
                        <flux:input wire:model="sqft" type="number" placeholder="2000" />
                        <flux:error name="sqft" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('What is this for?') }}</flux:label>
                    <div class="flex gap-4 mt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="intent" value="selling" class="text-emerald-600 focus:ring-emerald-500" />
                            <span class="text-sm">{{ __('Selling this property') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="intent" value="buying" class="text-emerald-600 focus:ring-emerald-500" />
                            <span class="text-sm">{{ __('Buyer interested') }}</span>
                        </label>
                    </div>
                    <flux:error name="intent" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Open House Date/Time') }}</flux:label>
                    <flux:input wire:model="openHouseDate" type="datetime-local" />
                    <flux:error name="openHouseDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:textarea wire:model="description" rows="3" :placeholder="__('Property description...')" />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="2" :placeholder="__('Internal notes...')" />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Submit Listing') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
