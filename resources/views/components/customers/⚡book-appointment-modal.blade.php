<?php

use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentBookingService;
use App\Services\DealStageService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $customerId = null;

    public ?int $dealId = null;

    public ?string $rescheduleBookingToken = null;

    // Calendar state
    public array $availableDates = [];

    public array $availableSlots = [];

    public ?string $selectedDate = null;

    public ?array $selectedSlot = null;

    // Override mode
    public bool $overrideMode = false;

    public string $overrideDate = '';

    public string $overrideStartTime = '09:00';

    public string $overrideEndTime = '10:00';

    public ?int $overrideEstimatorId = null;

    public string $overrideNote = '';

    // Estimate preference
    public string $estimatePreference = 'in_person';

    #[On('open-appointment-modal')]
    public function openForCustomer(int $customerId, ?int $dealId = null): void
    {
        $this->reset();
        $this->customerId = $customerId;
        $this->dealId = $dealId;
        $this->rescheduleBookingToken = null;
        $this->loadAvailableDates();
        $this->showModal = true;
    }

    #[On('open-reschedule-appointment-modal')]
    public function openForReschedule(string $token): void
    {
        $booking = AppointmentBooking::where('booking_token', $token)->first();
        if (! $booking) {
            return;
        }

        $this->reset();
        $this->customerId = $booking->customer_id;
        $this->dealId = $booking->deal_id;
        $this->rescheduleBookingToken = $token;
        $this->loadAvailableDates();
        $this->showModal = true;
    }

    public function loadAvailableDates(): void
    {
        $service = app(AppointmentAvailabilityService::class);
        $this->availableDates = $service->getAvailableDates();
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
        $this->selectedSlot = null;
        $service = app(AppointmentAvailabilityService::class);
        $this->availableSlots = $service->getAvailableSlotsForDate(Carbon::parse($date));
    }

    public function selectSlot(int $index): void
    {
        if (! isset($this->availableSlots[$index])) {
            return;
        }
        $this->selectedSlot = $this->availableSlots[$index];
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customerId ? Customer::find($this->customerId) : null;
    }

    #[Computed]
    public function bookableEstimators()
    {
        return app(AppointmentAvailabilityService::class)->getBookableEstimators();
    }

    public function bookAppointment(): void
    {
        $customer = $this->customer;
        if (! $customer) {
            session()->flash('error', 'Customer not found.');

            return;
        }

        try {
            $service = app(AppointmentBookingService::class);

            if ($this->overrideMode) {
                $this->validate([
                    'overrideDate' => 'required|date|after_or_equal:today',
                    'overrideStartTime' => 'required',
                    'overrideEndTime' => 'required|after:overrideStartTime',
                    'overrideEstimatorId' => 'required|exists:users,id',
                ]);

                $date = Carbon::parse($this->overrideDate);
                $estimatorId = $this->overrideEstimatorId;
                $startTime = $this->overrideStartTime;
                $endTime = $this->overrideEndTime;
            } else {
                if (! $this->selectedSlot || ! $this->selectedDate) {
                    session()->flash('error', 'Please select a date and time slot.');

                    return;
                }
                $date = Carbon::parse($this->selectedDate);
                $estimatorId = $this->selectedSlot['estimator_id'];
                $startTime = $this->selectedSlot['start'];
                $endTime = $this->selectedSlot['end'];
            }

            $prospectData = [
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email ?? '',
                'phone' => $customer->phone ?? '',
                'address' => $customer->address ?? '',
                'city' => $customer->city ?? '',
                'state' => $customer->state ?? '',
                'zip' => $customer->zip ?? '',
                'description' => '',
                'estimate_preference' => $this->estimatePreference,
            ];

            if ($this->rescheduleBookingToken) {
                $booking = $service->rescheduleBooking(
                    $this->rescheduleBookingToken,
                    $date,
                    $startTime,
                    $endTime,
                    $estimatorId
                );
            } else {
                $booking = $service->bookAppointment(
                    prospectData: $prospectData,
                    estimatorId: $estimatorId,
                    date: $date,
                    startTime: $startTime,
                    endTime: $endTime,
                    vendor: null,
                    bookedBy: 'internal',
                    sessionId: session()->getId()
                );

                // Set override note if applicable
                if ($this->overrideMode && $this->overrideNote) {
                    $booking->update([
                        'override_note' => 'Override by '.auth()->user()->name.': '.$this->overrideNote,
                    ]);
                }

                // Transition deal to quote_scheduled
                if ($this->dealId) {
                    $deal = Deal::find($this->dealId);
                    if ($deal && ! $deal->isPastDeal()) {
                        app(DealStageService::class)->transitionTo($deal, 'quote_scheduled', $date->toDateString());
                    }
                }
            }

            $this->showModal = false;
            $this->dispatch('appointment-booked');
            session()->flash('success', $this->rescheduleBookingToken ? 'Appointment rescheduled.' : 'Appointment booked.');

        } catch (\App\Exceptions\SlotUnavailableException $e) {
            session()->flash('error', 'That time slot is no longer available. Please select another.');
            $this->loadAvailableDates();
            if ($this->selectedDate) {
                $this->selectDate($this->selectedDate);
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to book appointment: '.$e->getMessage());
        }
    }

    public function close(): void
    {
        $this->showModal = false;
    }
}; ?>

<div>
    <flux:modal wire:model="showModal" class="max-w-2xl">
        <div class="space-y-6">
            {{-- Header --}}
            <div>
                <flux:heading size="lg">
                    {{ $rescheduleBookingToken ? __('Reschedule Appointment') : __('Book Appointment') }}
                </flux:heading>
                @if ($this->customer)
                    <flux:text class="mt-1">
                        {{ __('For') }} <span class="font-medium">{{ $this->customer->full_name }}</span>
                    </flux:text>
                @endif
            </div>

            {{-- Flash messages --}}
            @if (session()->has('error'))
                <flux:callout variant="danger" icon="exclamation-triangle">
                    {{ session('error') }}
                </flux:callout>
            @endif

            <flux:separator />

            {{-- Override toggle --}}
            <flux:checkbox
                wire:model.live="overrideMode"
                :label="__('Override mode')"
                :description="__('Manually set date, time, and estimator (bypasses availability calendar)')"
            />

            @if ($overrideMode)
                {{-- Override mode fields --}}
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <flux:field>
                            <flux:label>{{ __('Date') }}</flux:label>
                            <flux:input wire:model="overrideDate" type="date" min="{{ now()->format('Y-m-d') }}" />
                            <flux:error name="overrideDate" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Start Time') }}</flux:label>
                            <flux:input wire:model="overrideStartTime" type="time" />
                            <flux:error name="overrideStartTime" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('End Time') }}</flux:label>
                            <flux:input wire:model="overrideEndTime" type="time" />
                            <flux:error name="overrideEndTime" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Estimator') }}</flux:label>
                        <flux:select wire:model="overrideEstimatorId">
                            <option value="">{{ __('Select estimator...') }}</option>
                            @foreach ($this->bookableEstimators as $estimator)
                                <option value="{{ $estimator->id }}">{{ $estimator->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="overrideEstimatorId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Override Note') }}</flux:label>
                        <flux:textarea wire:model="overrideNote" rows="2" :placeholder="__('Reason for override...')" />
                    </flux:field>
                </div>
            @else
                {{-- Calendar mode --}}
                <div class="space-y-4">
                    {{-- Date grid --}}
                    @if (count($availableDates) > 0)
                        <div>
                            <flux:heading size="sm" class="mb-3">{{ __('Select a Date') }}</flux:heading>
                            <div class="space-y-3">
                                @php
                                    $grouped = collect($availableDates)->groupBy(fn ($d) => Carbon::parse($d['date'])->startOfWeek()->format('M j'));
                                @endphp

                                @foreach ($grouped as $weekLabel => $dates)
                                    <div>
                                        <p class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-2">
                                            {{ __('Week of') }} {{ $weekLabel }}
                                        </p>
                                        <div class="grid grid-cols-5 sm:grid-cols-7 gap-2">
                                            @foreach ($dates as $dateInfo)
                                                @php
                                                    $dt = Carbon::parse($dateInfo['date']);
                                                    $isSelected = $selectedDate === $dateInfo['date'];
                                                @endphp
                                                <button
                                                    wire:click="selectDate('{{ $dateInfo['date'] }}')"
                                                    wire:key="date-{{ $dateInfo['date'] }}"
                                                    type="button"
                                                    class="flex flex-col items-center p-2 rounded-lg border-2 transition-all text-center
                                                        {{ $isSelected
                                                            ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/30'
                                                            : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-400 dark:hover:border-blue-500 bg-white dark:bg-zinc-800' }}"
                                                >
                                                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">{{ $dt->format('D') }}</span>
                                                    <span class="text-base font-bold {{ $isSelected ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-900 dark:text-white' }}">{{ $dt->format('j') }}</span>
                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $dt->format('M') }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="text-center py-6">
                            <flux:icon name="calendar" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text>{{ __('No available dates at this time.') }}</flux:text>
                        </div>
                    @endif

                    {{-- Time slots --}}
                    @if ($selectedDate && count($availableSlots) > 0)
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <flux:heading size="sm">
                                    {{ Carbon::parse($selectedDate)->format('l, F j') }}
                                </flux:heading>
                                <flux:badge size="sm">{{ count($availableSlots) }} {{ __('slots') }}</flux:badge>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach ($availableSlots as $index => $slot)
                                    @php
                                        $isSlotSelected = $selectedSlot && $selectedSlot['start'] === $slot['start'] && $selectedSlot['estimator_id'] === $slot['estimator_id'];
                                    @endphp
                                    <button
                                        wire:click="selectSlot({{ $index }})"
                                        wire:key="slot-{{ $index }}"
                                        type="button"
                                        class="py-2.5 px-3 rounded-lg border-2 transition-all text-center
                                            {{ $isSlotSelected
                                                ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/30'
                                                : 'border-zinc-200 dark:border-zinc-600 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 bg-white dark:bg-zinc-800' }}"
                                    >
                                        <span class="text-sm font-semibold {{ $isSlotSelected ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-900 dark:text-white' }}">
                                            {{ Carbon::parse($slot['start'])->format('g:i A') }}
                                        </span>
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400 block">
                                            {{ Carbon::parse($slot['end'])->format('g:i A') }}
                                        </span>
                                        <span class="text-[10px] text-zinc-400 dark:text-zinc-500 block mt-0.5">
                                            {{ $slot['estimator_name'] }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @elseif ($selectedDate && count($availableSlots) === 0)
                        <div class="text-center py-4">
                            <flux:icon name="clock" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text>{{ __('No available time slots for this date.') }}</flux:text>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Estimate preference --}}
            <flux:separator />

            <div>
                <flux:heading size="sm" class="mb-2">{{ __('Estimate Preference') }}</flux:heading>
                <flux:radio.group wire:model="estimatePreference">
                    <flux:radio value="in_person" label="{{ __('In-Person Walkthrough') }}" />
                    <flux:radio value="virtual" label="{{ __('Virtual Estimate') }}" />
                </flux:radio.group>
            </div>

            {{-- Selected slot summary --}}
            @if (! $overrideMode && $selectedSlot && $selectedDate)
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3 flex items-center gap-3">
                    <div class="shrink-0 w-10 h-10 rounded-lg bg-blue-600 flex items-center justify-center">
                        <flux:icon name="calendar-days" class="w-5 h-5 text-white" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ Carbon::parse($selectedDate)->format('l, F j, Y') }}
                        </p>
                        <p class="text-xs text-zinc-600 dark:text-zinc-300">
                            {{ Carbon::parse($selectedSlot['start'])->format('g:i A') }} - {{ Carbon::parse($selectedSlot['end'])->format('g:i A') }}
                            <span class="text-zinc-400">{{ __('with') }} {{ $selectedSlot['estimator_name'] }}</span>
                        </p>
                    </div>
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="close" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="primary"
                    wire:click="bookAppointment"
                    wire:loading.attr="disabled"
                    :disabled="! $overrideMode && (! $selectedSlot || ! $selectedDate)"
                >
                    <span wire:loading.remove wire:target="bookAppointment">
                        {{ $rescheduleBookingToken ? __('Reschedule') : __('Book Appointment') }}
                    </span>
                    <span wire:loading wire:target="bookAppointment">
                        {{ __('Booking...') }}
                    </span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
