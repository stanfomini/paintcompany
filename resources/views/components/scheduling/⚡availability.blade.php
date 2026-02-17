<?php

use App\Models\EmployeeAvailability;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        $user = auth()->user();
        if (! $user?->can('scheduling.view') && ! $user?->employeeProfile) {
            abort(403);
        }
    }

    /** @var array<int, array{is_available: bool, from: string, to: string}> */
    public array $days = [];

    public function mount(): void
    {
        $this->loadAvailability();
    }

    #[Computed]
    public function employeeProfile()
    {
        return auth()->user()?->employeeProfile;
    }

    public function loadAvailability(): void
    {
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        $existing = [];
        if ($this->employeeProfile) {
            $existing = EmployeeAvailability::query()
                ->where('employee_profile_id', $this->employeeProfile->id)
                ->get()
                ->keyBy('day_of_week');
        }

        $this->days = [];
        for ($i = 0; $i < 7; $i++) {
            $record = $existing[$i] ?? null;
            $this->days[$i] = [
                'is_available' => $record ? (bool) $record->is_available : ($i >= 1 && $i <= 5),
                'from' => $record?->available_from ?? '07:00',
                'to' => $record?->available_to ?? '17:00',
            ];
        }
    }

    public function save(): void
    {
        if (! $this->employeeProfile) {
            return;
        }

        foreach ($this->days as $dayOfWeek => $day) {
            EmployeeAvailability::updateOrCreate(
                [
                    'employee_profile_id' => $this->employeeProfile->id,
                    'day_of_week' => $dayOfWeek,
                ],
                [
                    'is_available' => $day['is_available'],
                    'available_from' => $day['is_available'] ? $day['from'] : null,
                    'available_to' => $day['is_available'] ? $day['to'] : null,
                ]
            );
        }

        session()->flash('success', __('Availability updated.'));
    }
}; ?>

<div>
    @if (! $this->employeeProfile)
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
            <flux:icon name="clock" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-4">{{ __('No employee profile found') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">{{ __('Your employee profile has not been set up yet.') }}</flux:text>
        </div>
    @else
        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <flux:heading size="lg" class="mb-4">{{ __('My Weekly Availability') }}</flux:heading>
        <flux:text class="text-zinc-500 mb-6">{{ __('Set your general weekly availability. Managers will use this when scheduling shifts.') }}</flux:text>

        <div class="space-y-3">
            @php $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']; @endphp
            @foreach ($dayNames as $index => $dayName)
                <div wire:key="day-{{ $index }}" class="flex flex-col sm:flex-row sm:items-center gap-3 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="w-28 shrink-0">
                        <flux:text class="font-medium">{{ __($dayName) }}</flux:text>
                    </div>

                    <div class="flex items-center gap-3">
                        <flux:switch
                            wire:model.live="days.{{ $index }}.is_available"
                            label="{{ __('Available') }}"
                        />
                    </div>

                    @if ($days[$index]['is_available'])
                        <div class="flex items-center gap-2 sm:ml-4">
                            <flux:input
                                type="time"
                                wire:model="days.{{ $index }}.from"
                                class="w-32"
                            />
                            <flux:text class="text-zinc-500">{{ __('to') }}</flux:text>
                            <flux:input
                                type="time"
                                wire:model="days.{{ $index }}.to"
                                class="w-32"
                            />
                        </div>
                    @else
                        <flux:text class="text-zinc-400 sm:ml-4">{{ __('Not available') }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            <flux:button variant="primary" wire:click="save">
                {{ __('Save Availability') }}
            </flux:button>
        </div>
    @endif
</div>
