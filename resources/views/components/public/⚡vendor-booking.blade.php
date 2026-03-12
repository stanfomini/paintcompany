<?php

use App\Exceptions\SlotUnavailableException;
use App\Models\CompanySetting;
use App\Models\Schedule;
use App\Models\Vendor;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.public')] #[Title('Book an Appointment')] class extends Component
{
    public int $step = 1;

    public ?string $slug = null;

    // Calendar state
    public string $view = 'date'; // 'date' or 'time'

    public ?string $selectedDate = null;

    public ?array $selectedSlot = null;

    public array $availableDates = [];

    public array $availableSlots = [];

    // Form state
    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $description = '';

    public string $estimatePreference = 'in_person';

    public string $honeypot = '';

    // Result
    public ?array $bookingResult = null;

    public ?string $errorMessage = null;

    // Schedule info for visual indicators
    public array $dateScheduleInfo = [];

    public function mount(?string $slug = null): void
    {
        $this->slug = $slug;

        $settings = CompanySetting::getInstance();
        if (! $settings->booking_enabled) {
            abort(404);
        }

        if ($slug) {
            // Validate vendor exists and is enabled (will 404 if not)
            Vendor::where('booking_slug', $slug)
                ->where('booking_enabled', true)
                ->firstOrFail();
        }

        $service = app(AppointmentAvailabilityService::class);
        $this->availableDates = $service->getAvailableDates();
        $this->loadDateScheduleInfo();
    }

    #[Computed]
    public function vendor(): ?Vendor
    {
        if ($this->slug) {
            return Vendor::where('booking_slug', $this->slug)->first();
        }

        return null;
    }

    #[Computed]
    public function companySettings(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    /**
     * Load schedule info for all available dates to show visual indicators.
     */
    protected function loadDateScheduleInfo(): void
    {
        $service = app(AppointmentAvailabilityService::class);
        $estimators = $service->getBookableEstimators();

        if ($estimators->isEmpty()) {
            return;
        }

        $info = [];
        foreach ($this->availableDates as $dateInfo) {
            $date = $dateInfo['date'];
            $scheduledEstimators = [];

            foreach ($estimators as $estimator) {
                $profile = $estimator->employeeProfile;
                if (! $profile) {
                    continue;
                }

                $hasSchedule = Schedule::query()
                    ->forEmployee($profile->id)
                    ->forDate($date)
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                $scheduledEstimators[] = [
                    'name' => $estimator->name,
                    'scheduled' => $hasSchedule,
                ];
            }

            $info[$date] = $scheduledEstimators;
        }

        $this->dateScheduleInfo = $info;
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
        $this->selectedSlot = null;

        $service = app(AppointmentAvailabilityService::class);
        $this->availableSlots = $service->getAvailableSlotsForDate(Carbon::parse($date));

        $this->view = 'time';
    }

    public function showDatePicker(): void
    {
        $this->view = 'date';
    }

    public function selectSlot(int $index): void
    {
        if (! isset($this->availableSlots[$index])) {
            return;
        }

        $this->selectedSlot = $this->availableSlots[$index];

        // Create slot hold
        $service = app(AppointmentAvailabilityService::class);
        $sessionId = session()->getId();
        $service->holdSlot(
            $sessionId,
            $this->selectedSlot['estimator_id'],
            Carbon::parse($this->selectedDate),
            $this->selectedSlot['start'],
            $this->selectedSlot['end'],
        );

        $this->step = 2;
    }

    public function goBack(): void
    {
        if ($this->step === 2) {
            $this->step = 1;
            $this->selectedSlot = null;
            $this->view = 'time';
        }
    }

    public function submitBooking(): void
    {
        // Honeypot check
        if (! empty($this->honeypot)) {
            return;
        }

        $this->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'required|string|max:500',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            'description' => 'nullable|string|max:2000',
            'estimatePreference' => 'required|in:in_person,virtual',
        ]);

        $this->errorMessage = null;

        try {
            // Re-fetch vendor from slug (protected properties don't survive Livewire requests)
            $vendor = $this->slug
                ? Vendor::where('booking_slug', $this->slug)->where('booking_enabled', true)->first()
                : null;

            $service = app(AppointmentBookingService::class);
            $booking = $service->bookAppointment(
                [
                    'first_name' => $this->firstName,
                    'last_name' => $this->lastName,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'address' => $this->address,
                    'city' => $this->city,
                    'state' => $this->state,
                    'zip' => $this->zip,
                    'description' => $this->description,
                    'estimate_preference' => $this->estimatePreference,
                ],
                $this->selectedSlot['estimator_id'],
                Carbon::parse($this->selectedDate),
                $this->selectedSlot['start'],
                $this->selectedSlot['end'],
                $vendor,
                $this->slug ? 'vendor' : 'prospect',
                session()->getId(),
            );

            $this->bookingResult = [
                'token' => $booking->booking_token,
                'date' => $booking->booked_date->format('l, F j, Y'),
                'start_time' => Carbon::parse($booking->booked_start_time)->format('g:i A'),
                'end_time' => Carbon::parse($booking->booked_end_time)->format('g:i A'),
                'estimator' => $booking->estimator->name ?? 'TBD',
                'address' => $this->address,
            ];

            $this->dispatch('booking-completed');
            $this->step = 3;
        } catch (SlotUnavailableException $e) {
            $this->errorMessage = __('That time slot is no longer available. Please select a different time.');
            $this->step = 1;
            $this->selectedSlot = null;
            $this->view = 'date';
            $this->availableDates = app(AppointmentAvailabilityService::class)->getAvailableDates();
            $this->loadDateScheduleInfo();
        }
    }

    /**
     * Build a Google Calendar "add event" URL from the booking result.
     */
    public function getGoogleCalendarUrl(): string
    {
        if (! $this->bookingResult) {
            return '#';
        }

        $date = Carbon::parse($this->bookingResult['date']);
        $start = Carbon::parse($this->bookingResult['start_time']);
        $end = Carbon::parse($this->bookingResult['end_time']);

        $startDt = $date->copy()->setTime($start->hour, $start->minute)->format('Ymd\THis');
        $endDt = $date->copy()->setTime($end->hour, $end->minute)->format('Ymd\THis');

        $title = urlencode('Painting Estimate - '.($this->companySettings->company_name ?? config('app.name')));
        $location = urlencode($this->bookingResult['address']);
        $details = urlencode('Estimate appointment with '.$this->bookingResult['estimator']);

        return "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$startDt}/{$endDt}&location={$location}&details={$details}";
    }
};
?>

<div class="py-8 px-4 sm:py-12">
    <div class="mx-auto max-w-5xl">
        {{-- Header --}}
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="inline-block">
                <flux:heading size="xl" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors">{{ $this->companySettings->company_name ?? config('app.name') }}</flux:heading>
            </a>
            @if ($this->vendor)
                <flux:text class="mt-1">{{ __('Referred by') }} {{ $this->vendor->booking_display_name ?? $this->vendor->name }}</flux:text>
            @endif
            <flux:text class="mt-2 text-lg">{{ __('Book Your Free Estimate') }}</flux:text>
        </div>

        {{-- Step indicator --}}
        <div class="flex items-center justify-center gap-2 mb-8">
            @foreach ([1 => 'Select Time', 2 => 'Your Info', 3 => 'Confirmed'] as $num => $label)
                <div class="flex items-center gap-2">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold
                        {{ $step >= $num ? 'bg-blue-600 text-white' : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400' }}">
                        @if ($step > $num)
                            <flux:icon name="check" variant="mini" class="w-4 h-4" />
                        @else
                            {{ $num }}
                        @endif
                    </div>
                    <span class="text-sm font-medium hidden sm:inline {{ $step >= $num ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                        {{ __($label) }}
                    </span>
                    @if ($num < 3)
                        <div class="w-8 sm:w-12 h-px {{ $step > $num ? 'bg-blue-600' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {{-- Main content column --}}
        <div class="lg:col-span-2">

        {{-- Error message --}}
        @if ($errorMessage)
            <div class="mb-6">
                <flux:callout variant="danger" icon="exclamation-triangle">
                    {{ $errorMessage }}
                </flux:callout>
            </div>
        @endif

        {{-- Step 1: Calendar + Time Slots --}}
        @if ($step === 1)
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Choose a Date & Time') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Select an available date, then pick a time slot.') }}</flux:text>
                </div>

                {{-- Pill toggle (shown when a date has been selected) --}}
                @if ($selectedDate)
                    <div class="flex items-center gap-1 p-1 rounded-full bg-zinc-100 dark:bg-zinc-900 w-fit">
                        <button
                            wire:click="showDatePicker"
                            type="button"
                            class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium transition-all
                                {{ $view === 'date'
                                    ? 'bg-blue-600 text-white shadow-sm'
                                    : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
                        >
                            <flux:icon name="calendar" variant="mini" class="w-4 h-4" />
                            {{ Carbon::parse($selectedDate)->format('M j') }}
                        </button>
                        <button
                            wire:click="$set('view', 'time')"
                            type="button"
                            class="flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium transition-all
                                {{ $view === 'time'
                                    ? 'bg-blue-600 text-white shadow-sm'
                                    : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
                        >
                            <flux:icon name="clock" variant="mini" class="w-4 h-4" />
                            {{ count($availableSlots) }} {{ __('slots') }}
                        </button>
                    </div>
                @endif

                {{-- Date view --}}
                @if ($view === 'date')
                    @if (count($availableDates) > 0)
                        <div class="space-y-4">
                            @php
                                $grouped = collect($availableDates)->groupBy(fn ($d) => Carbon::parse($d['date'])->startOfWeek()->format('M j'));
                            @endphp

                            @foreach ($grouped as $weekLabel => $dates)
                                <div>
                                    <p class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-2">
                                        {{ __('Week of') }} {{ $weekLabel }}
                                    </p>
                                    <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                                        @foreach ($dates as $dateInfo)
                                            @php
                                                $dt = Carbon::parse($dateInfo['date']);
                                                $isSelected = $selectedDate === $dateInfo['date'];
                                                $schedInfo = $dateScheduleInfo[$dateInfo['date']] ?? [];
                                                $hasScheduled = collect($schedInfo)->contains('scheduled', true);
                                                $scheduledCount = collect($schedInfo)->where('scheduled', true)->count();
                                                $totalEstimators = count($schedInfo);
                                            @endphp
                                            <button
                                                wire:click="selectDate('{{ $dateInfo['date'] }}')"
                                                wire:key="date-{{ $dateInfo['date'] }}"
                                                type="button"
                                                class="relative flex flex-col items-center p-3 rounded-lg border-2 transition-all text-center
                                                    {{ $isSelected
                                                        ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/30'
                                                        : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-400 dark:hover:border-blue-500 bg-white dark:bg-zinc-800' }}"
                                            >
                                                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">{{ $dt->format('D') }}</span>
                                                <span class="text-lg font-bold {{ $isSelected ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-900 dark:text-white' }}">{{ $dt->format('j') }}</span>
                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $dt->format('M') }}</span>
                                                {{-- Schedule indicator --}}
                                                @if ($hasScheduled)
                                                    <span class="mt-1 inline-flex items-center gap-0.5 text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                        {{ $scheduledCount }}/{{ $totalEstimators }}
                                                    </span>
                                                @else
                                                    <span class="mt-1 inline-flex items-center gap-0.5 text-[10px] text-zinc-400 dark:text-zinc-500 font-medium">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                                                        {{ __('Open') }}
                                                    </span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Legend --}}
                        <div class="flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                {{ __('Estimator scheduled') }}
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                                {{ __('Open - no one scheduled yet') }}
                            </div>
                        </div>
                    @else
                        <div class="text-center py-8">
                            <flux:icon name="calendar" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                            <flux:text>{{ __('No available dates at this time. Please check back later.') }}</flux:text>
                        </div>
                    @endif
                @endif

                {{-- Time view --}}
                @if ($view === 'time' && $selectedDate)
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <flux:heading size="md">
                                {{ Carbon::parse($selectedDate)->format('l, F j') }}
                            </flux:heading>
                            <button wire:click="showDatePicker" type="button" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                                {{ __('Change date') }}
                            </button>
                        </div>

                        @if (count($availableSlots) > 0)
                            {{-- Estimator schedule info for selected date --}}
                            @php $schedInfo = $dateScheduleInfo[$selectedDate] ?? []; @endphp
                            @if (count($schedInfo) > 0)
                                <div class="p-3 bg-zinc-50 dark:bg-zinc-900 rounded-lg">
                                    <p class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase mb-2">{{ __('Estimator Status') }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($schedInfo as $est)
                                            <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-medium
                                                {{ $est['scheduled']
                                                    ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'
                                                    : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-400' }}">
                                                <span class="w-1.5 h-1.5 rounded-full {{ $est['scheduled'] ? 'bg-emerald-500' : 'bg-zinc-400 dark:bg-zinc-500' }}"></span>
                                                {{ $est['name'] }}
                                                <span class="text-[10px]">{{ $est['scheduled'] ? __('Scheduled') : __('Not scheduled') }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach ($availableSlots as $index => $slot)
                                    <button
                                        wire:click="selectSlot({{ $index }})"
                                        wire:key="slot-{{ $index }}"
                                        type="button"
                                        class="group py-3 px-4 rounded-lg border border-zinc-200 dark:border-zinc-600 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-all text-center"
                                    >
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                                            {{ Carbon::parse($slot['start'])->format('g:i A') }}
                                        </span>
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400 block">
                                            {{ Carbon::parse($slot['end'])->format('g:i A') }}
                                        </span>
                                        <span class="text-[10px] text-zinc-400 dark:text-zinc-500 group-hover:text-blue-500 block mt-1">
                                            {{ $slot['estimator_name'] }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4">
                                <flux:icon name="clock" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                                <flux:text>{{ __('No available time slots for this date. Please choose another date.') }}</flux:text>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Timezone info --}}
                <div class="text-center">
                    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500">
                        {{ __('All times shown in') }} {{ now()->timezone->getName() }}
                    </flux:text>
                </div>
            </div>
        @endif

        {{-- Step 2: Prospect Form --}}
        @if ($step === 2)
            <div class="space-y-6">
                {{-- Selected time summary --}}
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 flex items-center gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-lg bg-blue-600 flex items-center justify-center">
                        <flux:icon name="calendar-days" class="w-6 h-6 text-white" />
                    </div>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">
                            {{ Carbon::parse($selectedDate)->format('l, F j, Y') }}
                        </p>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ Carbon::parse($selectedSlot['start'])->format('g:i A') }} - {{ Carbon::parse($selectedSlot['end'])->format('g:i A') }}
                            <span class="text-zinc-400">{{ __('with') }} {{ $selectedSlot['estimator_name'] }}</span>
                        </p>
                    </div>
                    <button wire:click="goBack" type="button" class="ml-auto text-sm text-blue-600 dark:text-blue-400 hover:underline">
                        {{ __('Change') }}
                    </button>
                </div>

                {{-- Contact form --}}
                <form wire:submit="submitBooking" class="space-y-6">
                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                        <flux:heading size="lg">{{ __('Your Information') }}</flux:heading>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:input wire:model="firstName" :label="__('First Name')" required />
                            <flux:input wire:model="lastName" :label="__('Last Name')" required />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:input wire:model="email" type="email" :label="__('Email')" required />
                            <flux:input wire:model="phone" :label="__('Phone')" />
                        </div>
                    </div>

                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                        <flux:heading size="lg">{{ __('Property Details') }}</flux:heading>

                        <flux:input wire:model="address" :label="__('Property Address')" required />

                        <div class="grid grid-cols-5 gap-3">
                            <div class="col-span-2">
                                <flux:input wire:model="city" :label="__('City')" />
                            </div>
                            <div class="col-span-1">
                                <flux:input wire:model="state" :label="__('State')" maxlength="2" />
                            </div>
                            <div class="col-span-2">
                                <flux:input wire:model="zip" :label="__('Zip')" />
                            </div>
                        </div>

                        <flux:textarea wire:model="description" :label="__('What do you need painted?')" :placeholder="__('Interior, exterior, specific rooms, etc.')" rows="3" />
                    </div>

                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                        <flux:heading size="lg">{{ __('Estimate Preference') }}</flux:heading>
                        <flux:text class="mb-2">{{ __('How would you like to receive your estimate?') }}</flux:text>

                        <flux:radio.group wire:model="estimatePreference">
                            <flux:radio value="in_person" label="{{ __('In-Person Walkthrough') }}" description="{{ __('An estimator will visit the property for accurate measurements.') }}" />
                            <flux:radio value="virtual" label="{{ __('Virtual Estimate') }}" description="{{ __('We will review photos or video of the project remotely.') }}" />
                        </flux:radio.group>
                    </div>

                    {{-- Honeypot --}}
                    <div class="hidden" aria-hidden="true">
                        <input type="text" wire:model="honeypot" tabindex="-1" autocomplete="off" />
                    </div>

                    <div class="flex items-center justify-between">
                        <button wire:click="goBack" type="button" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 transition-colors">
                            &larr; {{ __('Back') }}
                        </button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="submitBooking">{{ __('Confirm Booking') }}</span>
                            <span wire:loading wire:target="submitBooking">{{ __('Booking...') }}</span>
                        </flux:button>
                    </div>
                </form>
            </div>
        @endif

        {{-- Step 3: Confirmation --}}
        @if ($step === 3 && $bookingResult)
            <div class="space-y-6">
                {{-- Success message --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                    <div class="mx-auto w-16 h-16 rounded-full bg-green-100 dark:bg-green-900 flex items-center justify-center mb-4">
                        <flux:icon name="check-circle" class="w-10 h-10 text-green-600 dark:text-green-400" />
                    </div>
                    <flux:heading size="lg">{{ __('Appointment Confirmed!') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('We\'ve sent a confirmation email to') }} <strong>{{ $email }}</strong>.
                    </flux:text>
                </div>

                {{-- Booking summary --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <flux:heading size="md">{{ __('Booking Details') }}</flux:heading>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        <div class="flex justify-between py-3">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</flux:text>
                            <flux:text class="font-medium">{{ $bookingResult['date'] }}</flux:text>
                        </div>
                        <div class="flex justify-between py-3">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</flux:text>
                            <flux:text class="font-medium">{{ $bookingResult['start_time'] }} - {{ $bookingResult['end_time'] }}</flux:text>
                        </div>
                        <div class="flex justify-between py-3">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Estimator') }}</flux:text>
                            <flux:text class="font-medium">{{ $bookingResult['estimator'] }}</flux:text>
                        </div>
                        <div class="flex justify-between py-3">
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</flux:text>
                            <flux:text class="font-medium">{{ $bookingResult['address'] }}</flux:text>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="{{ $this->getGoogleCalendarUrl() }}" target="_blank" rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                        <flux:icon name="calendar" variant="mini" class="w-4 h-4" />
                        {{ __('Add to Google Calendar') }}
                    </a>

                    <a href="{{ route('booking.cancel', $bookingResult['token']) }}"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 transition-colors">
                        {{ __('Cancel or Reschedule') }}
                    </a>
                </div>

                {{-- Color Visualizer promo --}}
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-center">
                    <flux:icon name="paint-brush" class="w-8 h-8 mx-auto text-blue-500 mb-3" />
                    <flux:heading size="md">{{ __('While You Wait...') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-600 dark:text-zinc-300 text-sm">
                        {{ __('Try our free Color Visualizer! Upload a photo of your space, pick your colors, and see what your project could look like before we arrive.') }}
                    </flux:text>
                    <a href="{{ route('visualize') }}" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition-colors">
                        <flux:icon name="camera" variant="mini" class="w-4 h-4" />
                        {{ __('Try the Color Visualizer') }}
                    </a>
                </div>

                {{-- Book another --}}
                <div class="text-center pt-4">
                    <a href="{{ $slug ? route('booking.vendor', $slug) : route('booking.general') }}"
                        class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                        {{ __('Book Another Appointment') }}
                    </a>
                </div>
            </div>
        @endif

        </div>{{-- end main content column --}}

        {{-- Sidebar - shown on ALL steps --}}
        <div class="lg:col-span-1 space-y-6">
            @if ($this->vendor)
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                            <flux:icon name="building-storefront" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <flux:heading size="md">{{ $this->vendor->booking_display_name ?? $this->vendor->name }}</flux:heading>
                    </div>

                    @if ($this->vendor->booking_bio)
                        <flux:text class="text-zinc-600 dark:text-zinc-300 text-sm leading-relaxed">
                            {{ $this->vendor->booking_bio }}
                        </flux:text>
                    @endif

                    @if ($this->vendor->contact_name)
                        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="user" variant="mini" class="w-4 h-4" />
                            <span>{{ $this->vendor->contact_name }}</span>
                        </div>
                    @endif

                    @if ($this->vendor->phone)
                        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="phone" variant="mini" class="w-4 h-4" />
                            <span>{{ $this->vendor->phone }}</span>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Company info --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-3">
                <flux:heading size="sm">{{ __('About Us') }}</flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ $this->companySettings->company_name ?? config('app.name') }}
                </flux:text>
                @if ($this->companySettings->address)
                    <div class="flex items-start gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="map-pin" variant="mini" class="w-4 h-4 mt-0.5 shrink-0" />
                        <span>{{ $this->companySettings->address }}</span>
                    </div>
                @endif
                @if ($this->companySettings->phone)
                    <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="phone" variant="mini" class="w-4 h-4" />
                        <span>{{ $this->companySettings->phone }}</span>
                    </div>
                @endif
            </div>

            {{-- Booking summary on step 2 --}}
            @if ($step === 2 && $selectedSlot)
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800 p-4 space-y-2">
                    <p class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase">{{ __('Your Selection') }}</p>
                    <div class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <flux:icon name="calendar-days" variant="mini" class="w-4 h-4 text-blue-500" />
                        {{ Carbon::parse($selectedDate)->format('D, M j') }}
                    </div>
                    <div class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <flux:icon name="clock" variant="mini" class="w-4 h-4 text-blue-500" />
                        {{ Carbon::parse($selectedSlot['start'])->format('g:i A') }} - {{ Carbon::parse($selectedSlot['end'])->format('g:i A') }}
                    </div>
                    <div class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <flux:icon name="user" variant="mini" class="w-4 h-4 text-blue-500" />
                        {{ $selectedSlot['estimator_name'] }}
                    </div>
                </div>
            @endif

            {{-- Setter Support Chat --}}
            <livewire:public.setter-support-chat />
        </div>{{-- end sidebar --}}
        </div>{{-- end grid --}}
    </div>

    @script
    <script>
        Livewire.on('booking-completed', () => {
            if (typeof fbq === 'function') {
                fbq('track', 'Schedule');
            }
        });
    </script>
    @endscript
</div>
