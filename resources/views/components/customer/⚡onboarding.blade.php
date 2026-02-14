<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.customer', ['title' => 'Welcome'])] class extends Component {
    public int $step = 1;

    public bool $isNewProject = false;

    public bool $isAgentSourced = false;

    public string $agentName = '';

    public string $propertyAddress = '';

    // Step 1: Contact
    public string $phone = '';

    public string $preferredContact = '';

    // Step 2: Property
    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $propertyType = 'residential';

    // Step 3: Project
    public string $timeline = '';

    public string $projectNotes = '';

    // Step 4: Estimate Preference
    public string $estimatePreference = '';

    #[Computed]
    public function customer(): ?Customer
    {
        return Customer::where('user_id', auth('customer')->id())->first();
    }

    public function mount(): void
    {
        $user = auth('customer')->user();
        $this->isNewProject = request()->boolean('new_project');

        // If not needing onboarding and not requesting a new project, redirect to dashboard
        if (! $user->needs_onboarding && ! $this->isNewProject) {
            $this->redirect(route('customer.dashboard'));

            return;
        }

        // Detect agent-sourced customer (deal already exists with agent_intent and property)
        if ($user->needs_onboarding && $this->customer) {
            $agentDeal = Deal::where('customer_id', $this->customer->id)
                ->whereNotNull('agent_intent')
                ->whereNotNull('property_id')
                ->with(['agent', 'property'])
                ->first();

            if ($agentDeal) {
                $this->isAgentSourced = true;
                $this->agentName = $agentDeal->agent?->name ?? '';
                $this->propertyAddress = $agentDeal->property?->address ?? '';
            }
        }

        // For returning customers requesting a new project, skip to step 2 (property)
        if ($this->isNewProject && ! $user->needs_onboarding) {
            $this->step = 2;
        }

        // Agent-sourced customers only need step 1, skip query param overrides
        if (! $this->isAgentSourced) {
            // Accept ?step=N query param
            $requestedStep = (int) request()->query('step', 0);
            if ($requestedStep >= 1 && $requestedStep <= 4) {
                $this->step = $requestedStep;
            }

            // Accept ?preference= query param
            $preference = request()->query('preference');
            if (in_array($preference, ['virtual', 'in_person'])) {
                $this->estimatePreference = $preference;
            }
        }

        // Pre-fill from existing customer data
        if ($this->customer) {
            $this->phone = $this->customer->phone ?? '';
            if (! $this->isNewProject) {
                $this->address = $this->customer->address ?? '';
                $this->city = $this->customer->city ?? '';
                $this->state = $this->customer->state ?? '';
                $this->zip = $this->customer->zip ?? '';
            }
        }

        // Prefill from landing page session data (fills empty fields only)
        $prefill = session('landing_page_prefill', []);
        if (! empty($prefill)) {
            if (empty($this->phone) && ! empty($prefill['phone'])) {
                $this->phone = $prefill['phone'];
            }
            if (empty($this->address) && ! empty($prefill['address'])) {
                $this->address = $prefill['address'];
            }
            if (empty($this->city) && ! empty($prefill['city'])) {
                $this->city = $prefill['city'];
            }
            if (empty($this->state) && ! empty($prefill['state'])) {
                $this->state = $prefill['state'];
            }
            if (empty($this->zip) && ! empty($prefill['zip'])) {
                $this->zip = $prefill['zip'];
            }
            if (empty($this->estimatePreference) && ! empty($prefill['estimate_preference'])) {
                $this->estimatePreference = $prefill['estimate_preference'];
            }
            if (empty($this->projectNotes) && ! empty($prefill['description'])) {
                $this->projectNotes = $prefill['description'];
            }

            // Clear session after reading
            session()->forget('landing_page_prefill');
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'phone' => ['nullable', 'string', 'max:50'],
                'preferredContact' => ['nullable', 'string', 'in:phone,email,text'],
            ]);
        }

        if ($this->step === 2) {
            $this->validate([
                'address' => ['nullable', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:100'],
                'state' => ['nullable', 'string', 'max:50'],
                'zip' => ['nullable', 'string', 'max:20'],
                'propertyType' => ['required', 'in:residential,commercial,industrial'],
            ]);
        }

        if ($this->step === 3) {
            $this->validate([
                'timeline' => ['nullable', 'string', 'in:flexible,within_2_weeks,asap'],
                'projectNotes' => ['nullable', 'string', 'max:5000'],
            ]);
        }

        $this->step = min($this->step + 1, 4);
    }

    public function previousStep(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function selectEstimatePreference(string $preference): void
    {
        if (! in_array($preference, ['virtual', 'in_person'])) {
            return;
        }

        $this->estimatePreference = $preference;
        $this->complete();
    }

    public function completeAgentSourced(): void
    {
        $this->validate([
            'phone' => ['nullable', 'string', 'max:50'],
            'preferredContact' => ['nullable', 'string', 'in:phone,email,text'],
        ]);

        $customer = $this->customer;

        if ($customer) {
            $updates = [];
            if ($this->phone) {
                $updates['phone'] = $this->phone;
            }
            if ($this->preferredContact) {
                $updates['estimate_preference'] = $this->preferredContact;
            }
            if (! empty($updates)) {
                $customer->update($updates);
            }
        }

        $user = auth('customer')->user();
        $user->update(['needs_onboarding' => false]);

        session()->flash('success', __('Welcome! Your project portal is ready.'));

        $this->redirect(route('customer.dashboard'));
    }

    public function complete(): void
    {
        $customer = $this->customer;

        if ($customer) {
            // Update customer contact info
            $updates = [];
            if ($this->phone) {
                $updates['phone'] = $this->phone;
            }

            // Update address if provided and different from existing
            if ($this->address && $this->city) {
                $updates['address'] = $this->address;
                $updates['city'] = $this->city;
                $updates['state'] = $this->state ?: null;
                $updates['zip'] = $this->zip ?: null;
            }

            if ($this->projectNotes) {
                $label = $this->isNewProject ? 'Project notes' : 'Onboarding notes';
                $updates['notes'] = $customer->notes
                    ? $customer->notes."\n\n{$label}: ".$this->projectNotes
                    : $this->projectNotes;
            }

            if ($this->estimatePreference) {
                $updates['estimate_preference'] = $this->estimatePreference;
            }

            if (! empty($updates)) {
                $customer->update($updates);
            }

            // Create property if address provided and no existing property matches
            $property = null;
            if ($this->address && $this->city) {
                $existingProperty = $customer->properties()
                    ->where('address', $this->address)
                    ->where('city', $this->city)
                    ->first();

                if (! $existingProperty) {
                    $property = Property::create([
                        'customer_id' => $customer->id,
                        'address' => $this->address,
                        'city' => $this->city,
                        'state' => $this->state ?: '',
                        'zip' => $this->zip ?: '',
                        'type' => $this->propertyType,
                    ]);
                } else {
                    $property = $existingProperty;
                }
            }

            if ($this->isNewProject) {
                $this->createNewProjectDealAndEstimate($customer, $property);
            } else {
                $this->createDealAndEstimate($customer);
            }
        }

        // Mark onboarding complete
        $user = auth('customer')->user();
        $user->update(['needs_onboarding' => false]);

        session()->flash('success', $this->isNewProject
            ? __('Your project request has been submitted! Our team will be in touch soon.')
            : __('Welcome! Your project portal is ready.'));

        $this->redirect(route('customer.dashboard'));
    }

    public function skip(): void
    {
        $user = auth('customer')->user();
        $user->update(['needs_onboarding' => false]);
        $this->redirect(route('customer.dashboard'));
    }

    private function createNewProjectDealAndEstimate(Customer $customer, ?Property $property): void
    {
        $createdBy = $customer->assigned_to ?? User::where('type', 'internal')->first()?->id;

        if (! $createdBy) {
            return;
        }

        $deal = Deal::create([
            'customer_id' => $customer->id,
            'property_id' => $property?->id,
            'title' => $property
                ? $property->address.' - '.$customer->first_name.' '.$customer->last_name
                : 'Painting Estimate - '.$customer->first_name.' '.$customer->last_name,
            'description' => $this->projectNotes ?: null,
            'stage' => 'lead',
            'created_by' => $createdBy,
        ]);

        Estimate::create([
            'deal_id' => $deal->id,
            'created_by' => $createdBy,
            'title' => $deal->title,
            'status' => 'draft',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        // Notify internal users
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new \App\Notifications\NewProjectRequested($deal, $customer));
        }
    }

    private function createDealAndEstimate(Customer $customer): void
    {
        $createdBy = $customer->assigned_to ?? User::where('type', 'internal')->first()?->id;

        if (! $createdBy) {
            return;
        }

        // Find or create a lead-stage deal
        $deal = $customer->deals()->where('stage', 'lead')->first();

        if (! $deal) {
            $deal = Deal::create([
                'customer_id' => $customer->id,
                'property_id' => $customer->properties()->latest()->first()?->id,
                'title' => 'Painting Estimate - '.$customer->first_name.' '.$customer->last_name,
                'stage' => 'lead',
                'created_by' => $createdBy,
            ]);
        }

        // Create a draft estimate if the deal has none
        if ($deal->estimates()->count() === 0) {
            Estimate::create([
                'deal_id' => $deal->id,
                'created_by' => $createdBy,
                'title' => $deal->title,
                'status' => 'draft',
                'estimator_mode' => Estimate::MODE_TEMPLATE,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
            ]);
        }
    }
}; ?>

<div class="max-w-2xl mx-auto py-8 px-4">
    <div class="text-center mb-8">
        @if ($isAgentSourced)
            <flux:heading size="xl" class="mb-2">{{ __('Welcome to Your Project Portal') }}</flux:heading>
            <flux:text class="text-zinc-500">
                @if ($agentName)
                    {{ __(':name has set up a project for your property', ['name' => $agentName]) }}{{ $propertyAddress ? ' ' . __('at :address', ['address' => $propertyAddress]) : '' }}.
                @else
                    {{ __('Your agent has set up a project for your property') }}{{ $propertyAddress ? ' ' . __('at :address', ['address' => $propertyAddress]) : '' }}.
                @endif
                {{ __('Just confirm your contact info and you\'re all set.') }}
            </flux:text>
        @elseif ($isNewProject)
            <flux:heading size="xl" class="mb-2">{{ __('Request a New Estimate') }}</flux:heading>
            <flux:text class="text-zinc-500">{{ __('Tell us about your new project and we\'ll get you a quote.') }}</flux:text>
        @else
            <flux:heading size="xl" class="mb-2">{{ __('Welcome to Your Project Portal') }}</flux:heading>
            <flux:text class="text-zinc-500">{{ __('Let\'s get a few things set up so we can serve you better.') }}</flux:text>
        @endif
    </div>

    @if ($isAgentSourced)
        {{-- Agent-sourced: single step --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="lg" class="mb-1">{{ __('Confirm Your Contact Info') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('How can we best reach you?') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Phone Number') }}</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Preferred Contact Method') }}</flux:label>
                    <div class="flex gap-4 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="preferredContact" value="phone" class="text-blue-600" />
                            <span class="text-sm">{{ __('Phone') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="preferredContact" value="email" class="text-blue-600" />
                            <span class="text-sm">{{ __('Email') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="preferredContact" value="text" class="text-blue-600" />
                            <span class="text-sm">{{ __('Text') }}</span>
                        </label>
                    </div>
                    <flux:error name="preferredContact" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="skip">{{ __('Skip for now') }}</flux:button>
                <flux:button variant="primary" wire:click="completeAgentSourced">{{ __('Get Started') }}</flux:button>
            </div>
        </div>
    @else

    {{-- Progress Steps --}}
    <div class="flex items-center justify-center gap-2 mb-8">
        @for ($i = 1; $i <= 4; $i++)
            <div class="flex items-center">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium {{ $step >= $i ? 'bg-blue-500 text-white' : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-500' }}">
                    @if ($step > $i)
                        <flux:icon name="check" class="w-4 h-4" />
                    @else
                        {{ $i }}
                    @endif
                </div>
                @if ($i < 4)
                    <div class="w-12 h-0.5 {{ $step > $i ? 'bg-blue-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                @endif
            </div>
        @endfor
    </div>

    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
        {{-- Step 1: Contact --}}
        @if ($step === 1)
            <flux:heading size="lg" class="mb-1">{{ __('Confirm Your Contact Info') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('How can we best reach you?') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Phone Number') }}</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Preferred Contact Method') }}</flux:label>
                    <div class="flex gap-4 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="preferredContact" value="phone" class="text-blue-600" />
                            <span class="text-sm">{{ __('Phone') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="preferredContact" value="email" class="text-blue-600" />
                            <span class="text-sm">{{ __('Email') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="preferredContact" value="text" class="text-blue-600" />
                            <span class="text-sm">{{ __('Text') }}</span>
                        </label>
                    </div>
                    <flux:error name="preferredContact" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="skip">{{ __('Skip for now') }}</flux:button>
                <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
            </div>
        @endif

        {{-- Step 2: Property --}}
        @if ($step === 2)
            <flux:heading size="lg" class="mb-1">{{ __('Property Details') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('Where is the project located?') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Address') }}</flux:label>
                    <flux:input wire:model="address" placeholder="123 Main St" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('City') }}</flux:label>
                        <flux:input wire:model="city" placeholder="Denver" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:input wire:model="state" placeholder="CO" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ZIP') }}</flux:label>
                        <flux:input wire:model="zip" placeholder="80202" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Property Type') }}</flux:label>
                    <flux:select wire:model="propertyType">
                        <option value="residential">{{ __('Residential') }}</option>
                        <option value="commercial">{{ __('Commercial') }}</option>
                        <option value="industrial">{{ __('Industrial') }}</option>
                    </flux:select>
                    <flux:error name="propertyType" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
            </div>
        @endif

        {{-- Step 3: Project --}}
        @if ($step === 3)
            <flux:heading size="lg" class="mb-1">{{ __('Project Info') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('Tell us about your timeline and any details.') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Timeline') }}</flux:label>
                    <div class="flex flex-col gap-2 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="timeline" value="flexible" class="text-blue-600" />
                            <span class="text-sm">{{ __('Flexible - no rush') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="timeline" value="within_2_weeks" class="text-blue-600" />
                            <span class="text-sm">{{ __('Within 2 weeks') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="timeline" value="asap" class="text-blue-600" />
                            <span class="text-sm">{{ __('As soon as possible') }}</span>
                        </label>
                    </div>
                    <flux:error name="timeline" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Anything else we should know?') }}</flux:label>
                    <flux:textarea wire:model="projectNotes" rows="3" :placeholder="__('Color preferences, special requests, areas of concern...')" />
                    <flux:error name="projectNotes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
            </div>
        @endif

        {{-- Step 4: Estimate Preference --}}
        @if ($step === 4)
            <flux:heading size="lg" class="mb-1">{{ __('How Would You Like Your Estimate?') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('Choose how you\'d prefer us to assess your project.') }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <button
                    wire:click="selectEstimatePreference('virtual')"
                    class="group relative flex flex-col items-center gap-3 rounded-lg border-2 p-6 text-center transition-all hover:border-blue-500 hover:shadow-md {{ $estimatePreference === 'virtual' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-zinc-200 dark:border-zinc-700' }}"
                >
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/50">
                        <flux:icon name="video-camera" class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Virtual Estimate') }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Video walkthrough from your phone - fast and convenient') }}</p>
                    </div>
                </button>

                <button
                    wire:click="selectEstimatePreference('in_person')"
                    class="group relative flex flex-col items-center gap-3 rounded-lg border-2 p-6 text-center transition-all hover:border-blue-500 hover:shadow-md {{ $estimatePreference === 'in_person' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-zinc-200 dark:border-zinc-700' }}"
                >
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/50">
                        <flux:icon name="home" class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ __('In-Person Estimate') }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('We\'ll visit your property for a detailed assessment') }}</p>
                    </div>
                </button>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <flux:button variant="ghost" wire:click="complete">{{ __('Skip for now') }}</flux:button>
            </div>
        @endif
    </div>

    @endif {{-- end @else (non agent-sourced) --}}
</div>
