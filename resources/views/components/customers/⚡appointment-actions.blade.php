<?php

use App\Models\AppointmentBooking;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public int $customerId;

    public ?string $cancelReason = '';

    #[Computed]
    public function appointments(): \Illuminate\Database\Eloquent\Collection
    {
        return AppointmentBooking::query()
            ->where('customer_id', $this->customerId)
            ->whereIn('status', [
                AppointmentBooking::STATUS_CONFIRMED,
                AppointmentBooking::STATUS_COMPLETED,
            ])
            ->with('estimator')
            ->orderBy('booked_date')
            ->orderBy('booked_start_time')
            ->get();
    }

    public function cancelBooking(string $token): void
    {
        app(AppointmentBookingService::class)->cancelBooking($token, $this->cancelReason ?: null);
        $this->cancelReason = '';
        unset($this->appointments);
        session()->flash('success', 'Appointment cancelled.');
    }

    public function markCompleted(int $id): void
    {
        $booking = AppointmentBooking::findOrFail($id);
        app(AppointmentBookingService::class)->markCompleted($booking);
        unset($this->appointments);
    }

    public function markNoShow(int $id): void
    {
        $booking = AppointmentBooking::findOrFail($id);
        app(AppointmentBookingService::class)->markNoShow($booking);
        unset($this->appointments);
    }

    public function reschedule(string $token): void
    {
        $this->dispatch('open-reschedule-appointment-modal', token: $token);
    }

    #[On('appointment-booked')]
    public function refresh(): void
    {
        unset($this->appointments);
    }
}; ?>

<div>
    @if ($this->appointments->isEmpty())
        <div class="text-center py-6">
            <flux:icon name="calendar" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No appointments scheduled.') }}</flux:text>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($this->appointments as $booking)
                @php
                    $isPast = $booking->booked_date->isPast();
                    $isConfirmed = $booking->status === AppointmentBooking::STATUS_CONFIRMED;
                    $isCompleted = $booking->status === AppointmentBooking::STATUS_COMPLETED;
                @endphp
                <div
                    wire:key="appointment-{{ $booking->id }}"
                    class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        {{-- Appointment info --}}
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center
                                {{ $isCompleted
                                    ? 'bg-green-100 dark:bg-green-900/30'
                                    : ($isPast ? 'bg-amber-100 dark:bg-amber-900/30' : 'bg-blue-100 dark:bg-blue-900/30') }}">
                                <flux:icon
                                    name="{{ $isCompleted ? 'check-circle' : 'calendar-days' }}"
                                    class="w-5 h-5 {{ $isCompleted
                                        ? 'text-green-600 dark:text-green-400'
                                        : ($isPast ? 'text-amber-600 dark:text-amber-400' : 'text-blue-600 dark:text-blue-400') }}"
                                />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                                    {{ $booking->booked_date->format('l, M j, Y') }}
                                </p>
                                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ Carbon::parse($booking->booked_start_time)->format('g:i A') }}
                                    - {{ Carbon::parse($booking->booked_end_time)->format('g:i A') }}
                                </p>
                                @if ($booking->estimator)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        {{ __('with') }} {{ $booking->estimator->name }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Status badge --}}
                        <div class="shrink-0">
                            @if ($isCompleted)
                                <flux:badge color="green" size="sm">{{ __('Completed') }}</flux:badge>
                            @elseif ($isConfirmed)
                                <flux:badge color="blue" size="sm">{{ __('Confirmed') }}</flux:badge>
                            @endif
                        </div>
                    </div>

                    {{-- Action buttons --}}
                    @if ($isConfirmed)
                        <div class="mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-700 flex flex-wrap gap-2">
                            @if ($isPast)
                                {{-- Past confirmed: mark completed or no show --}}
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    icon="check"
                                    wire:click="markCompleted({{ $booking->id }})"
                                >
                                    {{ __('Mark Completed') }}
                                </flux:button>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="x-mark"
                                    wire:click="markNoShow({{ $booking->id }})"
                                    wire:confirm="{{ __('Mark this appointment as a no-show?') }}"
                                >
                                    {{ __('No Show') }}
                                </flux:button>
                            @else
                                {{-- Future confirmed: join call (if virtual), reschedule, or cancel --}}
                                @if ($booking->estimate_preference === 'virtual' && $booking->getVideoCallUrl())
                                    <flux:button
                                        size="sm"
                                        variant="primary"
                                        icon="video-camera"
                                        :href="$booking->getVideoCallUrl()"
                                        target="_blank"
                                    >
                                        {{ __('Join Call') }}
                                    </flux:button>
                                @endif
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-path"
                                    wire:click="reschedule('{{ $booking->booking_token }}')"
                                >
                                    {{ __('Reschedule') }}
                                </flux:button>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="x-mark"
                                    wire:click="cancelBooking('{{ $booking->booking_token }}')"
                                    wire:confirm="{{ __('Are you sure you want to cancel this appointment?') }}"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    {{ __('Cancel') }}
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
