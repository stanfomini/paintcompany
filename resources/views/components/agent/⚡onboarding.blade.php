<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AgentListingSubmitted;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.agent', ['title' => 'Welcome'])] class extends Component {
    public int $step = 1;

    // Step 1: Profile
    public string $companyName = '';

    public string $phone = '';

    public string $licenseNumber = '';

    // Step 2: Listing
    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public ?string $listingPrice = null;

    public string $intent = 'selling';

    public function mount(): void
    {
        $user = auth('agent')->user();

        if (! $user->needs_onboarding) {
            $this->redirect(route('agent.dashboard'));

            return;
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'companyName' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'licenseNumber' => ['nullable', 'string', 'max:100'],
            ]);

            $this->saveProfile();
        }

        $this->step = min($this->step + 1, 3);
    }

    public function previousStep(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function saveListing(): void
    {
        $this->validate([
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:2'],
            'zip' => ['required', 'string', 'max:10'],
            'listingPrice' => ['nullable', 'numeric', 'min:0'],
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
            'listing_status' => 'active',
            'listing_price' => $this->listingPrice ?: null,
        ]);

        $deal = Deal::create([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'title' => $this->address.', '.$this->city,
            'stage' => 'lead',
            'estimated_value' => $this->listingPrice ?: null,
            'agent_intent' => $this->intent,
            'listing_value' => $this->listingPrice ?: null,
            'roi_scope' => 'interior',
        ]);

        Estimate::create([
            'deal_id' => $deal->id,
            'created_by' => $agent->id,
            'title' => 'Paint Estimate - '.$this->address,
            'status' => 'draft',
            'project_type' => 'interior',
        ]);

        // Notify internal team
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new AgentListingSubmitted($deal, $agent));
        }

        $this->step = 3;
    }

    public function skipListing(): void
    {
        $this->step = 3;
    }

    public function complete(): void
    {
        $user = auth('agent')->user();
        $user->update(['needs_onboarding' => false]);

        session()->flash('success', __('Welcome! Your agent portal is ready.'));
        $this->redirect(route('agent.dashboard'));
    }

    private function saveProfile(): void
    {
        $agent = auth('agent')->user();
        $profile = $agent->agentProfile;

        if (! $profile) {
            return;
        }

        $updates = [];
        if ($this->companyName) {
            $updates['company_name'] = $this->companyName;
        }
        if ($this->phone) {
            $updates['phone'] = $this->phone;
        }
        if ($this->licenseNumber) {
            $updates['license_number'] = $this->licenseNumber;
        }

        if (! empty($updates)) {
            $profile->update($updates);
        }
    }
}; ?>

<div class="max-w-2xl mx-auto py-8 px-4">
    <div class="text-center mb-8">
        <flux:heading size="xl" class="mb-2">{{ __('Welcome to Apex Negotiations') }}</flux:heading>
        <flux:text class="text-zinc-500">{{ __('Let\'s get your agent account set up in just a few steps.') }}</flux:text>
    </div>

    {{-- Progress Steps --}}
    <div class="flex items-center justify-center gap-2 mb-8">
        @for ($i = 1; $i <= 3; $i++)
            <div class="flex items-center">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium {{ $step >= $i ? 'bg-emerald-500 text-white' : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-500' }}">
                    @if ($step > $i)
                        <flux:icon name="check" class="w-4 h-4" />
                    @else
                        {{ $i }}
                    @endif
                </div>
                @if ($i < 3)
                    <div class="w-12 h-0.5 {{ $step > $i ? 'bg-emerald-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                @endif
            </div>
        @endfor
    </div>

    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
        {{-- Step 1: Complete Profile --}}
        @if ($step === 1)
            <flux:heading size="lg" class="mb-1">{{ __('Complete Your Profile') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('Tell us a bit about yourself so we can personalize your experience.') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Company / Brokerage Name') }}</flux:label>
                    <flux:input wire:model="companyName" :placeholder="__('RE/MAX Premier')" />
                    <flux:error name="companyName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Phone Number') }}</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('License Number') }} <span class="text-zinc-400 font-normal">{{ __('(optional)') }}</span></flux:label>
                    <flux:input wire:model="licenseNumber" placeholder="FA.100012345" />
                    <flux:error name="licenseNumber" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
            </div>
        @endif

        {{-- Step 2: Add First Listing --}}
        @if ($step === 2)
            <flux:heading size="lg" class="mb-1">{{ __('Add Your First Listing') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('Submit a property and we\'ll prepare a paint estimate for your client.') }}</flux:text>

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

                <flux:field>
                    <flux:label>{{ __('Listing Price') }}</flux:label>
                    <flux:input wire:model="listingPrice" type="number" step="0.01" placeholder="350000" />
                    <flux:error name="listingPrice" />
                </flux:field>

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
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <div class="flex gap-3">
                    <flux:button variant="ghost" wire:click="skipListing">{{ __('Skip for Now') }}</flux:button>
                    <flux:button variant="primary" wire:click="saveListing">{{ __('Submit Listing') }}</flux:button>
                </div>
            </div>
        @endif

        {{-- Step 3: How It Works --}}
        @if ($step === 3)
            <flux:heading size="lg" class="mb-1">{{ __('How It Works') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('Here\'s how Apex Negotiations helps you close more deals.') }}</flux:text>

            <div class="space-y-4">
                <div class="flex items-start gap-4 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
                        <flux:icon name="home" class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Add Listings') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Submit your listings and we\'ll create professional paint estimates.') }}</p>
                    </div>
                </div>

                <div class="flex items-start gap-4 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
                        <flux:icon name="document-text" class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Get Estimates') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Our team prepares detailed, professional estimates for your properties.') }}</p>
                    </div>
                </div>

                <div class="flex items-start gap-4 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
                        <flux:icon name="share" class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Share with Clients') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Pass estimates to your clients with one click - they sign digitally.') }}</p>
                    </div>
                </div>

                <div class="flex items-start gap-4 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
                        <flux:icon name="currency-dollar" class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Earn Referrals') }}</p>
                        <p class="text-sm text-zinc-500">{{ __('Track your referrals and earn for every signed job.') }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="complete">{{ __('You\'re All Set!') }}</flux:button>
            </div>
        @endif
    </div>
</div>
