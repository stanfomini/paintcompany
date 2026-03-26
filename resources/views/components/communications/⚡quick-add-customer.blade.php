<?php

use App\Models\ProjectPreview;
use App\Notifications\CustomerPreviewNotification;
use App\Services\LeadDeduplicationService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public bool $showSuccessModal = false;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $description = '';

    public string $estimate_preference = 'virtual';

    public ?int $createdCustomerId = null;

    #[On('open-quick-add-customer')]
    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'first_name', 'last_name', 'email', 'phone',
            'address', 'city', 'state', 'zip',
            'description', 'estimate_preference', 'createdCustomerId',
        ]);
        $this->estimate_preference = 'virtual';
        $this->resetValidation();
    }

    public function submitQuickAdd(): void
    {
        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'estimate_preference' => ['required', 'in:virtual,in_person'],
        ]);

        $dedup = app(LeadDeduplicationService::class);

        $result = $dedup->resolveOrCreate(
            customerData: [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'zip' => $validated['zip'] ?? null,
                'source' => 'communications_hub',
                'estimate_preference' => $validated['estimate_preference'],
                'status' => 'active',
                'created_by' => auth()->id(),
            ],
            dealData: [
                'title' => 'Painting Estimate - '.trim($validated['first_name'].' '.$validated['last_name']),
                'description' => $validated['description'] ?? null,
                'stage' => 'lead',
                'lead_source' => 'communications_hub',
                'created_by' => auth()->id(),
            ],
            options: [
                'create_estimate' => true,
                'source_label' => 'Communications Hub Quick Add',
            ],
        );

        $customer = $result->customer;
        $deal = $result->deal;

        // Link property to deal if auto-created by Customer::saved hook
        if (! $deal->property_id && ! empty($validated['address']) && ! empty($validated['city'])) {
            $property = $customer->properties()
                ->where('address', $validated['address'])
                ->where('city', $validated['city'])
                ->first();
            if ($property) {
                $deal->update(['property_id' => $property->id]);
            }
        }

        // Send preview page email (not invite — customers access via preview page)
        try {
            $preview = ProjectPreview::where('customer_id', $customer->id)
                ->where('deal_id', $deal->id)
                ->first();

            if (! $preview) {
                $preview = ProjectPreview::create([
                    'customer_id' => $customer->id,
                    'deal_id' => $deal->id,
                    'estimate_id' => $deal->estimates()->first()?->id,
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                ]);
            }

            $customer->notify(new CustomerPreviewNotification(
                preview: $preview,
                customerName: $validated['first_name'],
            ));
        } catch (\Exception $e) {
            Log::warning('Quick-add customer: preview notification failed', ['error' => $e->getMessage()]);
        }

        $this->createdCustomerId = $customer->id;
        $this->showModal = false;
        $this->showSuccessModal = true;
    }

    public function goToCustomer(): void
    {
        $this->redirect(route('customers.show', $this->createdCustomerId));
    }

    public function stayOnHub(): void
    {
        $this->showSuccessModal = false;
        $this->dispatch('customer-quick-added');
        $this->reset([
            'first_name', 'last_name', 'email', 'phone',
            'address', 'city', 'state', 'zip',
            'description', 'estimate_preference', 'createdCustomerId',
        ]);
        $this->estimate_preference = 'virtual';
    }
};
?>

<div>
    {{-- Quick Add Customer Form Modal --}}
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Quick Add Customer') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ __('Create a customer with a deal and estimate. They\'ll receive an invitation email and welcome SMS.') }}
                </flux:text>
            </div>

            <form wire:submit="submitQuickAdd" class="space-y-4">
                {{-- Name --}}
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:input wire:model="first_name" label="{{ __('First Name') }}" placeholder="First name" required />
                        @error('first_name') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:input wire:model="last_name" label="{{ __('Last Name') }}" placeholder="Last name" required />
                        @error('last_name') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                {{-- Contact --}}
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:input wire:model="email" type="email" label="{{ __('Email') }}" placeholder="email@example.com" required />
                        @error('email') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:input wire:model="phone" label="{{ __('Phone') }}" placeholder="(555) 123-4567" />
                        @error('phone') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                {{-- Address --}}
                <flux:field>
                    <flux:input wire:model="address" label="{{ __('Address') }}" placeholder="123 Main St" />
                    @error('address') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                </flux:field>

                <div class="grid grid-cols-3 gap-3">
                    <flux:field>
                        <flux:input wire:model="city" label="{{ __('City') }}" placeholder="Denver" />
                        @error('city') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:input wire:model="state" label="{{ __('State') }}" placeholder="CO" />
                        @error('state') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:input wire:model="zip" label="{{ __('Zip') }}" placeholder="80202" />
                        @error('zip') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                {{-- Description --}}
                <flux:field>
                    <flux:textarea wire:model="description" label="{{ __('Project Description') }}" placeholder="Brief description of the painting project..." rows="3" />
                    @error('description') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                </flux:field>

                {{-- Estimate Preference --}}
                <flux:field>
                    <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Estimate Preference') }}</flux:text>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="estimate_preference" value="virtual" class="text-blue-600 focus:ring-blue-500" />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Virtual') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="estimate_preference" value="in_person" class="text-blue-600 focus:ring-blue-500" />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('In-Person') }}</span>
                        </label>
                    </div>
                    @error('estimate_preference') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
                </flux:field>

                {{-- Actions --}}
                <div class="flex justify-end gap-3 pt-4">
                    <flux:button type="button" wire:click="closeModal" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitQuickAdd">{{ __('Create Customer') }}</span>
                        <span wire:loading wire:target="submitQuickAdd">{{ __('Creating...') }}</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Success Modal --}}
    <flux:modal wire:model="showSuccessModal" class="max-w-sm">
        <div class="text-center space-y-4 py-2">
            <div class="mx-auto w-12 h-12 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <flux:icon name="check" class="w-6 h-6 text-green-600 dark:text-green-400" />
            </div>
            <div>
                <flux:heading size="lg">{{ __('Customer Created!') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ __('Preview page email sent. Welcome SMS will be delivered shortly.') }}
                </flux:text>
            </div>
            <div class="flex flex-col gap-2 pt-2">
                <flux:button wire:click="goToCustomer" variant="primary" class="w-full">
                    {{ __('Go to Customer Page') }}
                </flux:button>
                <flux:button wire:click="stayOnHub" variant="ghost" class="w-full">
                    {{ __('Stay Here') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
