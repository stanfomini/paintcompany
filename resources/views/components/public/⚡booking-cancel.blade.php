<?php

use App\Exceptions\SlotUnavailableException;
use App\Models\AppointmentBooking;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.public')] #[Title('Manage Booking')] class extends Component {
    public string $token = '';
    public ?AppointmentBooking $booking = null;
    public string $reason = '';
    public bool $cancelled = false;

    // Reschedule state
    public string $mode = 'options'; // 'options', 'cancel', 'reschedule'
    public ?string $selectedDate = null;
    public ?int $selectedSlotIndex = null;
    public array $availableDates = [];
    public array $availableSlots = [];
    public bool $rescheduled = false;
    public ?string $newBookingToken = null;
    public ?string $errorMessage = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->booking = AppointmentBooking::where('booking_token', $token)
            ->with(['estimator'])
            ->firstOrFail();

        if ($this->booking->isCancelled()) {
            $this->cancelled = true;
        }

        if ($this->booking->status === AppointmentBooking::STATUS_RESCHEDULED) {
            $this->rescheduled = true;
        }
    }

    #[Computed]
    public function companyName(): string
    {
        return \App\Models\CompanySetting::getInstance()->company_name ?? config('app.name');
    }

    #[Computed]
    public function formattedDate(): string
    {
        return $this->booking->booked_date->format('l, F j, Y');
    }

    #[Computed]
    public function formattedTime(): string
    {
        $start = Carbon::parse($this->booking->booked_start_time)->format('g:i A');
        $end = Carbon::parse($this->booking->booked_end_time)->format('g:i A');

        return $start.' - '.$end;
    }

    public function showCancel(): void
    {
        $this->mode = 'cancel';
    }

    public function confirmCancel(): void
    {
        $service = app(AppointmentBookingService::class);
        $service->cancelBooking($this->token, $this->reason ?: null);

        $this->booking->refresh();
        $this->cancelled = true;
    }

    public function startReschedule(): void
    {
        $this->mode = 'reschedule';
        $this->errorMessage = null;
        $this->selectedDate = null;
        $this->selectedSlotIndex = null;
        $this->availableSlots = [];

        $service = app(AppointmentAvailabilityService::class);
        $this->availableDates = $service->getAvailableDates();
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
        $this->selectedSlotIndex = null;
        $this->errorMessage = null;

        $service = app(AppointmentAvailabilityService::class);
        $this->availableSlots = $service->getAvailableSlotsForDate(Carbon::parse($date));
    }

    public function selectSlot(int $index): void
    {
        if (! isset($this->availableSlots[$index])) {
            return;
        }

        $this->selectedSlotIndex = $index;

        // Create slot hold
        $service = app(AppointmentAvailabilityService::class);
        $sessionId = session()->getId();
        $slot = $this->availableSlots[$index];
        $service->holdSlot(
            $sessionId,
            $slot['estimator_id'],
            Carbon::parse($this->selectedDate),
            $slot['start'],
            $slot['end'],
        );
    }

    public function confirmReschedule(): void
    {
        if ($this->selectedSlotIndex === null || ! isset($this->availableSlots[$this->selectedSlotIndex])) {
            return;
        }

        $slot = $this->availableSlots[$this->selectedSlotIndex];
        $this->errorMessage = null;

        try {
            $service = app(AppointmentBookingService::class);
            $newBooking = $service->rescheduleBooking(
                $this->token,
                Carbon::parse($this->selectedDate),
                $slot['start'],
                $slot['end'],
                $slot['estimator_id'],
            );

            $this->rescheduled = true;
            $this->newBookingToken = $newBooking->booking_token;
        } catch (SlotUnavailableException $e) {
            $this->errorMessage = __('That time slot is no longer available. Please select a different time.');
            $this->selectedSlotIndex = null;
            $this->availableDates = app(AppointmentAvailabilityService::class)->getAvailableDates();
        }
    }

    public function backToOptions(): void
    {
        $this->mode = 'options';
        $this->selectedDate = null;
        $this->selectedSlotIndex = null;
        $this->availableSlots = [];
        $this->errorMessage = null;
    }
};
?>

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-900 py-8 px-4">
    <div class="mx-auto max-w-lg">
        {{-- Header --}}
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="inline-block">
                <flux:heading size="xl" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors">{{ $this->companyName }}</flux:heading>
            </a>
            <flux:text class="mt-2 text-lg">{{ __('Manage Appointment') }}</flux:text>
        </div>

        @if ($rescheduled)
            {{-- Reschedule success --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center space-y-4">
                <div class="mx-auto w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/50 flex items-center justify-center">
                    <flux:icon name="check-circle" class="w-10 h-10 text-emerald-600 dark:text-emerald-400" />
                </div>
                <flux:heading size="lg">{{ __('Appointment Rescheduled') }}</flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    {{ __('Your appointment has been successfully rescheduled. A confirmation email has been sent.') }}
                </flux:text>

                @if ($newBookingToken)
                    <div class="pt-4">
                        <a href="{{ route('booking.confirmed', $newBookingToken) }}">
                            <flux:button variant="primary" class="w-full">
                                {{ __('View New Appointment') }}
                            </flux:button>
                        </a>
                    </div>
                @endif
            </div>

            {{-- Color Visualizer promo --}}
            <div class="mt-6 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-center">
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

        @elseif ($cancelled)
            {{-- Cancellation confirmed --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center space-y-4">
                <div class="mx-auto w-16 h-16 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center">
                    <flux:icon name="x-circle" class="w-10 h-10 text-red-600 dark:text-red-400" />
                </div>
                <flux:heading size="lg">{{ __('Appointment Cancelled') }}</flux:heading>
                <flux:text class="text-zinc-600 dark:text-zinc-400">
                    {{ __('Your appointment has been cancelled. If you would like to schedule a new appointment, please contact us.') }}
                </flux:text>

                @if ($booking->cancellation_reason)
                    <div class="mt-4 text-left">
                        <flux:callout variant="info">
                            <flux:callout.heading>{{ __('Your reason') }}</flux:callout.heading>
                            <flux:callout.text>{{ $booking->cancellation_reason }}</flux:callout.text>
                        </flux:callout>
                    </div>
                @endif
            </div>

        @elseif ($mode === 'reschedule')
            {{-- Reschedule flow --}}
            <div class="space-y-6">
                {{-- Back button --}}
                <flux:button variant="ghost" icon="arrow-left" wire:click="backToOptions" size="sm">
                    {{ __('Back') }}
                </flux:button>

                {{-- Current appointment info --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text size="sm" class="text-zinc-500 mb-1">{{ __('Current Appointment') }}</flux:text>
                    <flux:text class="font-medium">{{ $this->formattedDate }} &middot; {{ $this->formattedTime }}</flux:text>
                </div>

                {{-- Error message --}}
                @if ($errorMessage)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        {{ $errorMessage }}
                    </flux:callout>
                @endif

                @if ($selectedSlotIndex !== null && isset($availableSlots[$selectedSlotIndex]))
                    {{-- Step 3: Confirm reschedule --}}
                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-6">
                        <flux:heading size="lg">{{ __('Confirm Reschedule') }}</flux:heading>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                                <flux:text size="sm" class="text-red-600 dark:text-red-400 font-medium mb-1">{{ __('Current') }}</flux:text>
                                <flux:text class="font-medium text-sm">{{ $booking->booked_date->format('M j, Y') }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $this->formattedTime }}</flux:text>
                            </div>
                            <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
                                <flux:text size="sm" class="text-emerald-600 dark:text-emerald-400 font-medium mb-1">{{ __('New') }}</flux:text>
                                <flux:text class="font-medium text-sm">{{ Carbon::parse($selectedDate)->format('M j, Y') }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">
                                    {{ Carbon::parse($availableSlots[$selectedSlotIndex]['start'])->format('g:i A') }}
                                    - {{ Carbon::parse($availableSlots[$selectedSlotIndex]['end'])->format('g:i A') }}
                                </flux:text>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            <flux:button wire:click="confirmReschedule" variant="primary" class="w-full">
                                {{ __('Confirm Reschedule') }}
                            </flux:button>
                            <flux:button wire:click="selectDate('{{ $selectedDate }}')" variant="ghost" class="w-full">
                                {{ __('Pick a Different Time') }}
                            </flux:button>
                        </div>
                    </div>

                @elseif ($selectedDate)
                    {{-- Step 2: Time slots --}}
                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                        <div class="flex items-center justify-between">
                            <flux:heading size="lg">
                                {{ Carbon::parse($selectedDate)->format('l, F j') }}
                            </flux:heading>
                            <flux:button variant="ghost" size="sm" wire:click="startReschedule">
                                {{ __('Change Date') }}
                            </flux:button>
                        </div>

                        @if (count($availableSlots) > 0)
                            <flux:text size="sm" class="text-zinc-500">
                                {{ count($availableSlots) }} {{ __('time slots available') }}
                            </flux:text>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($availableSlots as $index => $slot)
                                    <button
                                        wire:click="selectSlot({{ $index }})"
                                        wire:key="slot-{{ $index }}"
                                        type="button"
                                        class="py-3 px-4 rounded-lg border border-zinc-200 dark:border-zinc-600 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-all text-center"
                                    >
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                                            {{ Carbon::parse($slot['start'])->format('g:i A') }}
                                        </span>
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400 block">
                                            {{ Carbon::parse($slot['end'])->format('g:i A') }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-6">
                                <flux:icon name="clock" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                                <flux:text class="text-zinc-500">{{ __('No available times on this date.') }}</flux:text>
                                <flux:button variant="ghost" size="sm" wire:click="startReschedule" class="mt-2">
                                    {{ __('Pick Another Date') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>

                @else
                    {{-- Step 1: Date picker --}}
                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                        <flux:heading size="lg">{{ __('Choose a New Date') }}</flux:heading>

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
                                                @php $dt = Carbon::parse($dateInfo['date']); @endphp
                                                <button
                                                    wire:click="selectDate('{{ $dateInfo['date'] }}')"
                                                    wire:key="date-{{ $dateInfo['date'] }}"
                                                    type="button"
                                                    class="flex flex-col items-center p-3 rounded-lg border-2 transition-all text-center border-zinc-200 dark:border-zinc-600 hover:border-blue-400 dark:hover:border-blue-500 bg-white dark:bg-zinc-800"
                                                >
                                                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">{{ $dt->format('D') }}</span>
                                                    <span class="text-lg font-bold text-zinc-900 dark:text-white">{{ $dt->format('j') }}</span>
                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $dt->format('M') }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-6">
                                <flux:icon name="calendar" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                                <flux:text class="text-zinc-500">{{ __('No available dates at this time. Please check back later.') }}</flux:text>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

        @elseif ($mode === 'cancel')
            {{-- Cancel form --}}
            <div class="space-y-6">
                {{-- Back button --}}
                <flux:button variant="ghost" icon="arrow-left" wire:click="backToOptions" size="sm">
                    {{ __('Back') }}
                </flux:button>

                {{-- Booking details --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <flux:heading size="lg">{{ __('Appointment Details') }}</flux:heading>

                    <div class="space-y-3">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                                <flux:icon name="calendar-days" variant="mini" />
                            </div>
                            <div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->formattedDate }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $this->formattedTime }}</flux:text>
                            </div>
                        </div>

                        @if ($booking->estimator)
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                                    <flux:icon name="user" variant="mini" />
                                </div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $booking->estimator->name }}</flux:text>
                            </div>
                        @endif

                        @if ($booking->prospect_address)
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                                    <flux:icon name="map-pin" variant="mini" />
                                </div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $booking->prospect_address }}</flux:text>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Warning --}}
                <flux:callout variant="warning">
                    <flux:callout.heading>{{ __('Are you sure?') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('Cancelling this appointment cannot be undone. You will need to book a new appointment if you change your mind.') }}</flux:callout.text>
                </flux:callout>

                {{-- Reason --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <flux:textarea
                        wire:model="reason"
                        :label="__('Reason for cancellation (optional)')"
                        :placeholder="__('Let us know why you need to cancel...')"
                        rows="3"
                    />
                </div>

                {{-- Actions --}}
                <div class="flex flex-col gap-3">
                    <flux:button
                        wire:click="confirmCancel"
                        wire:confirm="{{ __('Are you sure you want to cancel this appointment?') }}"
                        variant="danger"
                        class="w-full"
                    >
                        {{ __('Cancel Appointment') }}
                    </flux:button>

                    <flux:button variant="ghost" class="w-full" wire:click="backToOptions">
                        {{ __('Go Back') }}
                    </flux:button>
                </div>
            </div>

        @else
            {{-- Default: options view --}}
            <div class="space-y-6">
                {{-- Booking details --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <flux:heading size="lg">{{ __('Appointment Details') }}</flux:heading>

                    <div class="space-y-3">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                                <flux:icon name="calendar-days" variant="mini" />
                            </div>
                            <div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->formattedDate }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $this->formattedTime }}</flux:text>
                            </div>
                        </div>

                        @if ($booking->estimator)
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                                    <flux:icon name="user" variant="mini" />
                                </div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $booking->estimator->name }}</flux:text>
                            </div>
                        @endif

                        @if ($booking->prospect_address)
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                                    <flux:icon name="map-pin" variant="mini" />
                                </div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $booking->prospect_address }}</flux:text>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Options --}}
                <div class="flex flex-col gap-3">
                    <flux:button wire:click="startReschedule" variant="primary" icon="calendar" class="w-full">
                        {{ __('Reschedule Appointment') }}
                    </flux:button>

                    <flux:button wire:click="showCancel" variant="ghost" class="w-full text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
                        {{ __('Cancel Appointment') }}
                    </flux:button>

                    <a href="{{ route('booking.confirmed', $booking->booking_token) }}" class="w-full">
                        <flux:button variant="ghost" class="w-full">
                            {{ __('Keep Appointment') }}
                        </flux:button>
                    </a>
                </div>
            </div>
        @endif
    </div>
</div>
