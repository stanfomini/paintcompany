<?php

use App\Models\Property;
use App\Models\Customer;
use App\Notifications\NewOpenHouseSignup;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component {
    public Property $property;

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $notes = '';

    public string $honeypot = '';

    public bool $submitted = false;

    public function mount(Property $property): void
    {
        if ($property->listing_status !== 'active') {
            abort(404);
        }

        $this->property = $property;
    }

    public function submit(): void
    {
        // Honeypot check - if filled, silently reject
        if (! empty($this->honeypot)) {
            $this->submitted = true;

            return;
        }

        $this->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'firstName.required' => __('Please enter your first name.'),
            'lastName.required' => __('Please enter your last name.'),
            'email.required' => __('Please enter your email address.'),
            'email.email' => __('Please enter a valid email address.'),
        ]);

        $customer = Customer::create([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'city' => $this->city ?: null,
            'state' => $this->state ?: null,
            'zip' => $this->zip ?: null,
            'notes' => $this->notes ?: null,
            'referred_by' => $this->property->agent_id,
            'property_id' => $this->property->id,
            'source' => 'open_house',
            'status' => 'active',
            'created_by' => $this->property->agent_id,
        ]);

        // Notify the agent about the new sign-up
        $this->property->agent->notify(new NewOpenHouseSignup($customer, $this->property));

        $this->submitted = true;
    }
}; ?>

<div class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-lg">
        <div class="text-center mb-8">
            <flux:heading size="2xl" class="mb-2">{{ config('app.name') }}</flux:heading>
            <flux:text class="text-zinc-600 dark:text-zinc-400">
                {{ __('Open House Sign-Up') }}
            </flux:text>
        </div>

        <flux:card class="p-6">
            @if ($submitted)
                <div class="text-center py-8">
                    <flux:icon name="check-circle" class="w-16 h-16 mx-auto text-green-500 mb-4" />
                    <flux:heading size="xl" class="mb-3">{{ __('Thank You!') }}</flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400 mb-4">
                        {{ __('We appreciate you visiting our open house at:') }}
                    </flux:text>
                    <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">
                        {{ $property->full_address }}
                    </flux:text>
                    <flux:text class="text-zinc-500 dark:text-zinc-400 mt-6 text-sm">
                        @if ($property->agent)
                            {{ __(':name will be in touch soon.', ['name' => $property->agent->name]) }}
                        @else
                            {{ __('Our team will be in touch soon.') }}
                        @endif
                    </flux:text>
                </div>
            @else
                <div class="mb-6">
                    <flux:heading size="lg" class="mb-2">{{ $property->full_address }}</flux:heading>
                    @if ($property->agent)
                        <flux:text class="text-zinc-600 dark:text-zinc-400">
                            {{ __('Agent') }}: {{ $property->agent->name }}
                        </flux:text>
                    @endif
                    @if ($property->listing_price)
                        <flux:text class="text-zinc-600 dark:text-zinc-400 mt-1">
                            {{ __('Listed at') }}: ${{ number_format($property->listing_price, 0) }}
                        </flux:text>
                    @endif
                </div>

                <flux:separator class="my-6" />

                <form wire:submit="submit" class="space-y-4">
                    <!-- Honeypot field (hidden from real users) -->
                    <div class="hidden" aria-hidden="true">
                        <label for="website">Website</label>
                        <input type="text" name="website" id="website" wire:model="honeypot" tabindex="-1" autocomplete="off" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:field>
                            <flux:input
                                wire:model="firstName"
                                type="text"
                                label="{{ __('First Name') }}"
                                placeholder="{{ __('Your first name') }}"
                                required
                                autofocus
                            />
                            @error('firstName')
                                <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                            @enderror
                        </flux:field>

                        <flux:field>
                            <flux:input
                                wire:model="lastName"
                                type="text"
                                label="{{ __('Last Name') }}"
                                placeholder="{{ __('Your last name') }}"
                                required
                            />
                            @error('lastName')
                                <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                            @enderror
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:input
                            wire:model="email"
                            type="email"
                            label="{{ __('Email') }}"
                            placeholder="your.email@example.com"
                            required
                        />
                        @error('email')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:input
                            wire:model="phone"
                            type="tel"
                            label="{{ __('Phone (optional)') }}"
                            placeholder="(555) 123-4567"
                        />
                        @error('phone')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:separator class="my-4" />

                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-2">
                        {{ __('Your current address (optional)') }}
                    </flux:text>

                    <flux:field>
                        <flux:input
                            wire:model="address"
                            type="text"
                            :label="__('Address')"
                            :placeholder="__('123 Main Street')"
                        />
                        @error('address')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <flux:field>
                            <flux:input
                                wire:model="city"
                                type="text"
                                :label="__('City')"
                                :placeholder="__('City')"
                            />
                            @error('city')
                                <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                            @enderror
                        </flux:field>

                        <flux:field>
                            <flux:input
                                wire:model="state"
                                type="text"
                                :label="__('State')"
                                :placeholder="__('State')"
                            />
                            @error('state')
                                <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                            @enderror
                        </flux:field>

                        <flux:field class="col-span-2 sm:col-span-1">
                            <flux:input
                                wire:model="zip"
                                type="text"
                                :label="__('Zip')"
                                :placeholder="__('Zip')"
                            />
                            @error('zip')
                                <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                            @enderror
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:textarea
                            wire:model="notes"
                            :label="__('Notes / Comments (optional)')"
                            :placeholder="__('Any questions or comments about the property?')"
                            rows="3"
                        />
                        @error('notes')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:button type="submit" variant="primary" class="w-full justify-center mt-6">
                        <span wire:loading.remove wire:target="submit">{{ __('Sign Up') }}</span>
                        <span wire:loading wire:target="submit">{{ __('Submitting...') }}</span>
                    </flux:button>
                </form>
            @endif
        </flux:card>

        <flux:text size="sm" class="text-center mt-6 text-zinc-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
        </flux:text>
    </div>
</div>
