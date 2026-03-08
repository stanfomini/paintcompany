<?php

use App\Models\CompanySetting;
use App\Models\MessengerConversation;
use App\Services\MessengerLeadCreationService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.public')] class extends Component {
    public ?MessengerConversation $conversation = null;

    public bool $submitted = false;

    public bool $tokenExpired = false;

    #[Validate('required|string|max:100')]
    public string $first_name = '';

    #[Validate('nullable|string|max:100')]
    public string $last_name = '';

    #[Validate('nullable|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:30')]
    public string $phone = '';

    #[Validate('nullable|string|max:255')]
    public string $address = '';

    #[Validate('nullable|string|max:100')]
    public string $city = '';

    #[Validate('nullable|string|max:50')]
    public string $state = '';

    #[Validate('nullable|string|max:20')]
    public string $zip = '';

    #[Validate('nullable|string|max:2000')]
    public string $description = '';

    /** Honeypot field for spam protection */
    public string $website = '';

    public function mount(string $token): void
    {
        $conversation = MessengerConversation::where('booking_token', $token)->first();

        if (! $conversation || ! $conversation->isBookingTokenValid()) {
            $this->tokenExpired = true;

            return;
        }

        if ($conversation->customer_id) {
            $this->submitted = true;

            return;
        }

        $this->conversation = $conversation;

        // Pre-fill from collected data + FB profile fallback
        $collected = $conversation->getCollectedData();
        $fbName = $conversation->getFbName();

        $this->first_name = $collected['customer.first_name'] ?? $fbName['first_name'] ?? '';
        $this->last_name = $collected['customer.last_name'] ?? $fbName['last_name'] ?? '';
        $this->email = $collected['customer.email'] ?? '';
        $this->phone = $collected['customer.phone'] ?? '';
        $this->address = $collected['customer.address'] ?? '';
        $this->city = $collected['customer.city'] ?? '';
        $this->state = $collected['customer.state'] ?? '';
        $this->zip = $collected['customer.zip'] ?? '';
        $this->description = $collected['deal.description'] ?? '';
    }

    public function submit(): void
    {
        // Honeypot check
        if (! empty($this->website)) {
            return;
        }

        if (! $this->conversation || ! $this->conversation->isBookingTokenValid()) {
            $this->tokenExpired = true;

            return;
        }

        $this->validate();

        $service = app(MessengerLeadCreationService::class);
        $service->createFromManualConversion($this->conversation, [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'notes' => $this->description,
        ]);

        $this->conversation->invalidateBookingToken();
        $this->submitted = true;
    }
};

?>

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-900">
    <div class="max-w-2xl mx-auto px-4 py-8 sm:py-12">
        @php $company = CompanySetting::getInstance(); @endphp

        {{-- Header --}}
        <div class="text-center mb-8">
            @if ($company->logo_path)
                <img src="{{ Storage::url($company->logo_path) }}" alt="{{ $company->company_name }}" class="h-12 mx-auto mb-4" />
            @endif
            <flux:heading size="xl">{{ $company->company_name ?? 'Get a Free Estimate' }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">Complete the form below to get your free painting estimate.</flux:text>
        </div>

        @if ($tokenExpired)
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                <flux:icon name="clock" class="size-12 text-zinc-400 mx-auto mb-4" />
                <flux:heading size="lg">Link Expired</flux:heading>
                <flux:text class="mt-2 text-zinc-500">This booking link has expired or is no longer valid. Please contact us directly for a quote.</flux:text>
                @if ($company->phone)
                    <a href="tel:{{ $company->phone }}" class="mt-4 inline-flex items-center gap-2 text-blue-600 hover:text-blue-700">
                        <flux:icon name="phone" class="size-4" />
                        {{ $company->phone }}
                    </a>
                @endif
            </div>
        @elseif ($submitted)
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                <flux:icon name="check-circle" class="size-12 text-green-500 mx-auto mb-4" />
                <flux:heading size="lg">Thank You!</flux:heading>
                <flux:text class="mt-2 text-zinc-500">We've received your information and will be in touch shortly with your free estimate.</flux:text>
            </div>
        @else
            <form wire:submit="submit" class="bg-white dark:bg-zinc-800 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-700 p-6 sm:p-8 space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>First Name *</flux:label>
                        <flux:input wire:model="first_name" required />
                        <flux:error name="first_name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Last Name</flux:label>
                        <flux:input wire:model="last_name" />
                        <flux:error name="last_name" />
                    </flux:field>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Email</flux:label>
                        <flux:input wire:model="email" type="email" />
                        <flux:error name="email" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Phone</flux:label>
                        <flux:input wire:model="phone" type="tel" />
                        <flux:error name="phone" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Address</flux:label>
                    <flux:input wire:model="address" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>City</flux:label>
                        <flux:input wire:model="city" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>State</flux:label>
                        <flux:input wire:model="state" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Zip</flux:label>
                        <flux:input wire:model="zip" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>What do you need painted?</flux:label>
                    <flux:textarea wire:model="description" rows="3" />
                    <flux:error name="description" />
                </flux:field>

                {{-- Honeypot --}}
                <div class="hidden" aria-hidden="true">
                    <input type="text" wire:model="website" tabindex="-1" autocomplete="off" />
                </div>

                <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove>Get My Free Estimate</span>
                    <span wire:loading>Submitting...</span>
                </flux:button>
            </form>
        @endif
    </div>
</div>
