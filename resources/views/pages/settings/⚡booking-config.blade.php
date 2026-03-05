<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $bookingEnabled = false;
    public string $slotDuration = '60';
    public int $bufferMinutes = 15;
    public int $lookaheadDays = 30;
    public int $minNoticeHours = 24;
    public array $bookableEstimatorIds = [];
    public string $businessHoursStart = '08:00';
    public string $businessHoursEnd = '17:00';
    public array $businessDays = [1, 2, 3, 4, 5];

    public function toggleDay(int $day): void
    {
        if (in_array($day, $this->businessDays)) {
            $this->businessDays = array_values(array_diff($this->businessDays, [$day]));
        } else {
            $this->businessDays[] = $day;
            sort($this->businessDays);
        }
    }

    public bool $saved = false;

    public function boot(): void
    {
        if (! auth()->user()?->can('settings.company')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $settings = CompanySetting::getInstance();

        $this->bookingEnabled = (bool) $settings->booking_enabled;
        $this->slotDuration = (string) ($settings->booking_slot_duration_minutes ?? 60);
        $this->bufferMinutes = $settings->getBookingBuffer();
        $this->lookaheadDays = $settings->getBookingLookaheadDays();
        $this->minNoticeHours = $settings->getBookingMinNoticeHours();
        $this->bookableEstimatorIds = $settings->getBookableEstimatorIds();
        $this->businessHoursStart = $settings->business_hours_start ?? '08:00';
        $this->businessHoursEnd = $settings->business_hours_end ?? '17:00';
        $this->businessDays = $settings->business_days ?? [1, 2, 3, 4, 5];
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->orderBy('name')
            ->get();
    }

    public function save(): void
    {
        $this->validate([
            'slotDuration' => 'required|in:30,60,90',
            'bufferMinutes' => 'required|integer|min:0|max:120',
            'lookaheadDays' => 'required|integer|min:7|max:90',
            'minNoticeHours' => 'required|integer|min:1|max:168',
            'bookableEstimatorIds' => 'array',
            'bookableEstimatorIds.*' => 'exists:users,id',
            'businessHoursStart' => 'required|date_format:H:i',
            'businessHoursEnd' => 'required|date_format:H:i|after:businessHoursStart',
            'businessDays' => 'required|array|min:1',
            'businessDays.*' => 'integer|between:1,7',
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'booking_enabled' => $this->bookingEnabled,
            'booking_slot_duration_minutes' => (int) $this->slotDuration,
            'booking_buffer_minutes' => $this->bufferMinutes,
            'booking_lookahead_days' => $this->lookaheadDays,
            'booking_min_notice_hours' => $this->minNoticeHours,
            'bookable_estimator_ids' => $this->bookableEstimatorIds,
            'business_hours_start' => $this->businessHoursStart,
            'business_hours_end' => $this->businessHoursEnd,
            'business_days' => array_map('intval', $this->businessDays),
        ]);

        CompanySetting::clearInstanceCache();

        $this->saved = true;
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Booking Settings')" :subheading="__('Configure the online appointment booking system for vendors and prospects.')" :fullWidth="true">
        <div class="space-y-8">
            {{-- Global Toggle --}}
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="rounded-lg bg-blue-100 p-2 dark:bg-blue-900/30">
                            <flux:icon name="calendar-days" class="size-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Online Booking') }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Allow vendors to offer a booking link to prospects.') }}</p>
                        </div>
                    </div>
                    <flux:switch wire:model.live="bookingEnabled" />
                </div>
            </div>

            {{-- Slot Configuration --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Appointment Slots') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Configure how appointment slots are generated.') }}</flux:text>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Slot Duration') }}</flux:label>
                        <flux:select wire:model="slotDuration">
                            <option value="30">{{ __('30 minutes') }}</option>
                            <option value="60">{{ __('60 minutes') }}</option>
                            <option value="90">{{ __('90 minutes') }}</option>
                        </flux:select>
                        <flux:description>{{ __('Length of each appointment slot.') }}</flux:description>
                        <flux:error name="slotDuration" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Buffer Between Appointments (minutes)') }}</flux:label>
                        <flux:input wire:model="bufferMinutes" type="number" min="0" max="120" />
                        <flux:description>{{ __('Travel/prep time between appointments.') }}</flux:description>
                        <flux:error name="bufferMinutes" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Lookahead (days)') }}</flux:label>
                        <flux:input wire:model="lookaheadDays" type="number" min="7" max="90" />
                        <flux:description>{{ __('How far out prospects can book.') }}</flux:description>
                        <flux:error name="lookaheadDays" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Minimum Notice (hours)') }}</flux:label>
                        <flux:input wire:model="minNoticeHours" type="number" min="1" max="168" />
                        <flux:description>{{ __('Minimum lead time before an appointment.') }}</flux:description>
                        <flux:error name="minNoticeHours" />
                    </flux:field>
                </div>
            </div>

            {{-- Business Hours --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Business Hours') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Set your operating hours and days for appointment availability.') }}</flux:text>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Start Time') }}</flux:label>
                        <flux:input wire:model="businessHoursStart" type="time" />
                        <flux:description>{{ __('When your business day begins.') }}</flux:description>
                        <flux:error name="businessHoursStart" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('End Time') }}</flux:label>
                        <flux:input wire:model="businessHoursEnd" type="time" />
                        <flux:description>{{ __('When your business day ends.') }}</flux:description>
                        <flux:error name="businessHoursEnd" />
                    </flux:field>
                </div>

                <div>
                    <flux:label class="mb-2">{{ __('Operating Days') }}</flux:label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $dayNum => $dayLabel)
                            <button
                                wire:key="day-{{ $dayNum }}"
                                wire:click="toggleDay({{ $dayNum }})"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium transition
                                    {{ in_array($dayNum, $businessDays)
                                        ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-900/30 dark:text-blue-300'
                                        : 'border-zinc-300 text-zinc-600 dark:border-zinc-600 dark:text-zinc-400 hover:border-zinc-400 dark:hover:border-zinc-500' }}"
                            >
                                {{ __($dayLabel) }}
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="businessDays" />
                </div>
            </div>

            <flux:separator />

            {{-- Bookable Estimators --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Bookable Estimators') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Select which team members can be assigned appointments.') }}</flux:text>

                <div class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    @forelse ($this->internalUsers as $user)
                        <label wire:key="estimator-{{ $user->id }}" class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                            <input
                                type="checkbox"
                                wire:model="bookableEstimatorIds"
                                value="{{ $user->id }}"
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800"
                            />
                            <div class="flex items-center gap-2">
                                <flux:avatar size="xs" :name="$user->name" :initials="$user->initials()" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $user->name }}</span>
                            </div>
                        </label>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No internal users found.') }}</p>
                    @endforelse
                </div>
                <flux:error name="bookableEstimatorIds" />
            </div>

            {{-- Save Button --}}
            <div class="flex items-center gap-4">
                <flux:button variant="primary" wire:click="save">{{ __('Save Settings') }}</flux:button>

                @if ($saved)
                    <span
                        x-data="{ show: true }"
                        x-init="setTimeout(() => show = false, 2000)"
                        x-show="show"
                        x-transition.out.opacity.duration.1500ms
                        class="text-sm text-zinc-600 dark:text-zinc-400"
                    >{{ __('Saved.') }}</span>
                @endif
            </div>
        </div>
    </x-pages::settings.layout>
</section>
