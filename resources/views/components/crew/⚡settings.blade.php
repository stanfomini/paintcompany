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
        ]);

        session()->flash('success', 'Settings saved successfully.');
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

<div class="max-w-2xl mx-auto">
    <flux:heading size="xl" class="mb-2">Crew Settings</flux:heading>
    <flux:text class="text-zinc-500 mb-6">Manage your crew profile and preferences.</flux:text>

    @if (session('success'))
        <flux:callout variant="success" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- Company/Crew Info --}}
        <div class="p-6 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-4">
            <flux:heading size="sm">Crew Information</flux:heading>

            <flux:field>
                <flux:label>Company / Crew Name</flux:label>
                <flux:input wire:model="company_name" placeholder="e.g. Smith Painting Crew" />
                <flux:error name="company_name" />
            </flux:field>

            <flux:field>
                <flux:label>Phone Number</flux:label>
                <flux:input wire:model="phone" type="tel" placeholder="(555) 123-4567" />
                <flux:error name="phone" />
            </flux:field>

            <flux:field>
                <flux:label>Crew Size (Number of Painters)</flux:label>
                <flux:input wire:model="crew_size" type="number" min="1" max="50" />
                <flux:description>How many painters are on your crew? This determines which jobs you're eligible for.</flux:description>
                <flux:error name="crew_size" />
            </flux:field>

            <flux:field>
                <flux:label>Hourly Rate (Optional)</flux:label>
                <flux:input wire:model="hourly_rate" type="number" step="0.01" min="0" max="500" placeholder="25.00" />
                <flux:description>Your preferred hourly rate per painter.</flux:description>
                <flux:error name="hourly_rate" />
            </flux:field>
        </div>

        {{-- Specialties --}}
        <div class="p-6 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-4">
            <flux:heading size="sm">Specialties</flux:heading>
            <flux:text class="text-sm text-zinc-500">Select the types of work your crew specializes in.</flux:text>

            <div class="flex flex-wrap gap-2">
                @foreach ($availableSpecialties as $specialty)
                    <button
                        type="button"
                        wire:click="toggleSpecialty('{{ $specialty }}')"
                        class="px-3 py-1.5 text-sm rounded-full border transition-colors {{ in_array($specialty, $specialties) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border-zinc-300 dark:border-zinc-600 hover:border-blue-400' }}"
                    >
                        {{ $specialty }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Bio --}}
        <div class="p-6 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-4">
            <flux:heading size="sm">About Your Crew</flux:heading>

            <flux:field>
                <flux:label>Bio (Optional)</flux:label>
                <flux:textarea wire:model="bio" rows="4" placeholder="Tell us about your crew's experience, certifications, or what makes you stand out..." />
                <flux:error name="bio" />
            </flux:field>
        </div>

        {{-- Save Button --}}
        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">
                Save Settings
            </flux:button>
        </div>
    </form>
</div>
