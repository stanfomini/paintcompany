<?php

use App\Models\CrewProfile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.crew', ['title' => 'Crew Settings'])] class extends Component {
    #[Validate('required|string|max:255')]
    public string $company_name = '';

    #[Validate('nullable|string|max:50')]
    public string $phone = '';

    #[Validate('required|integer|min:1|max:50')]
    public int $crew_size = 1;

    #[Validate('nullable|numeric|min:0|max:500')]
    public string $hourly_rate = '';

    #[Validate('nullable|string|max:1000')]
    public string $bio = '';

    #[Validate('nullable|exists:service_categories,id')]
    public ?int $serviceCategoryId = null;

    /** @var array<string> */
    public array $specialties = [];

    public array $availableSpecialties = [
        'Interior Painting',
        'Exterior Painting',
        'Cabinet Refinishing',
        'Deck Staining',
        'Pressure Washing',
        'Drywall Repair',
        'Wallpaper Removal',
        'Popcorn Ceiling Removal',
        'Epoxy Flooring',
        'Commercial',
        'Residential',
    ];

    public function mount(): void
    {
        $user = auth('crew')->user();
        $profile = $user->crewProfile;

        $this->company_name = $profile->company_name ?? $user->name;
        $this->phone = $profile->phone ?? '';
        $this->crew_size = $profile->crew_size ?? 1;
        $this->hourly_rate = $profile->hourly_rate ? (string) $profile->hourly_rate : '';
        $this->bio = $profile->bio ?? '';
        $this->specialties = $profile->specialties ?? [];
        $this->serviceCategoryId = $profile->service_category_id;
    }

    public function save(): void
    {
        $this->validate();

        $user = auth('crew')->user();
        $profile = $user->crewProfile;

        $profile->update([
            'company_name' => $this->company_name,
            'phone' => $this->phone ?: null,
            'crew_size' => $this->crew_size,
            'hourly_rate' => $this->hourly_rate !== '' ? $this->hourly_rate : null,
            'bio' => $this->bio ?: null,
            'specialties' => $this->specialties,
            'service_category_id' => $this->serviceCategoryId,
        ]);

        session()->flash('success', __('Settings saved successfully.'));
    }

    public function toggleSpecialty(string $specialty): void
    {
        if (in_array($specialty, $this->specialties)) {
            $this->specialties = array_values(array_diff($this->specialties, [$specialty]));
        } else {
            $this->specialties[] = $specialty;
        }
    }
}; ?>

<section class="w-full">
    <div class="relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <x-user-settings-layout route-prefix="crew.settings" user-type="crew" :heading="__('Crew Profile')" :subheading="__('Manage your crew profile and preferences')" full-width>
    @if (session('success'))
        <flux:callout variant="success" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- Company/Crew Info --}}
        <div class="p-6 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-4">
            <flux:heading size="sm">{{ __('Crew Information') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Company / Crew Name') }}</flux:label>
                <flux:input wire:model="company_name" :placeholder="__('e.g. Smith Painting Crew')" />
                <flux:error name="company_name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Phone Number') }}</flux:label>
                <flux:input wire:model="phone" type="tel" :placeholder="__('(555) 123-4567')" />
                <flux:error name="phone" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Service Category') }}</flux:label>
                <flux:select wire:model="serviceCategoryId" :placeholder="__('Select your service...')">
                    @foreach (\App\Models\ServiceCategory::active()->ordered()->get() as $category)
                        <flux:select.option value="{{ $category->id }}">{{ __($category->name) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>{{ __('What type of work does your crew perform? This determines which jobs you\'re eligible for.') }}</flux:description>
                <flux:error name="serviceCategoryId" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Crew Size (Number of Painters)') }}</flux:label>
                <flux:input wire:model="crew_size" type="number" min="1" max="50" />
                <flux:description>{{ __('How many painters are on your crew? This determines which jobs you\'re eligible for.') }}</flux:description>
                <flux:error name="crew_size" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Hourly Rate (Optional)') }}</flux:label>
                <flux:input wire:model="hourly_rate" type="number" step="0.01" min="0" max="500" placeholder="25.00" />
                <flux:description>{{ __('Your preferred hourly rate per painter.') }}</flux:description>
                <flux:error name="hourly_rate" />
            </flux:field>
        </div>

        {{-- Specialties --}}
        <div class="p-6 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-4">
            <flux:heading size="sm">{{ __('Specialties') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">{{ __('Select the types of work your crew specializes in.') }}</flux:text>

            <div class="flex flex-wrap gap-2">
                @foreach ($availableSpecialties as $specialty)
                    <button
                        type="button"
                        wire:click="toggleSpecialty('{{ $specialty }}')"
                        class="px-3 py-1.5 text-sm rounded-full border transition-colors {{ in_array($specialty, $specialties) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border-zinc-300 dark:border-zinc-600 hover:border-blue-400' }}"
                    >
                        {{ __($specialty) }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Bio --}}
        <div class="p-6 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-4">
            <flux:heading size="sm">{{ __('About Your Crew') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Bio (Optional)') }}</flux:label>
                <flux:textarea wire:model="bio" rows="4" :placeholder="__('Tell us about your crew\'s experience, certifications, or what makes you stand out...')" />
                <flux:error name="bio" />
            </flux:field>
        </div>

        {{-- Save Button --}}
        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">
                {{ __('Save Settings') }}
            </flux:button>
        </div>
    </form>
    </x-user-settings-layout>
</section>
