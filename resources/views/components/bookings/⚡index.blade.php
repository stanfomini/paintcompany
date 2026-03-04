<?php

use App\Models\AppointmentBooking;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $vendorFilter = '';

    #[Url]
    public string $estimatorFilter = '';

    public string $dateFrom = '';
    public string $dateTo = '';

    // Cancel modal
    public bool $showCancelModal = false;
    public ?int $cancellingId = null;
    public string $cancellationReason = '';

    public function boot(): void
    {
        if (! auth()->user()?->can('settings.company')) {
            abort(403);
        }
    }

    #[Computed]
    public function bookings()
    {
        $query = AppointmentBooking::query()
            ->with(['vendor', 'estimator', 'deal.customer'])
            ->orderByDesc('booked_date')
            ->orderByDesc('booked_start_time');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('prospect_first_name', 'like', "%{$this->search}%")
                    ->orWhere('prospect_last_name', 'like', "%{$this->search}%")
                    ->orWhere('prospect_email', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->vendorFilter) {
            $query->where('vendor_id', $this->vendorFilter);
        }

        if ($this->estimatorFilter) {
            $query->where('estimator_id', $this->estimatorFilter);
        }

        if ($this->dateFrom) {
            $query->whereDate('booked_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('booked_date', '<=', $this->dateTo);
        }

        return $query->paginate(15);
    }

    #[Computed]
    public function vendors(): \Illuminate\Database\Eloquent\Collection
    {
        return Vendor::query()
            ->where('booking_enabled', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function estimators(): \Illuminate\Database\Eloquent\Collection
    {
        $estimatorIds = AppointmentBooking::query()
            ->whereNotNull('estimator_id')
            ->distinct()
            ->pluck('estimator_id');

        return User::query()
            ->whereIn('id', $estimatorIds)
            ->orderBy('name')
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVendorFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEstimatorFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function markCompleted(int $bookingId): void
    {
        $booking = AppointmentBooking::findOrFail($bookingId);
        $booking->markCompleted();
        unset($this->bookings);
    }

    public function markNoShow(int $bookingId): void
    {
        $booking = AppointmentBooking::findOrFail($bookingId);
        $booking->markNoShow();
        unset($this->bookings);
    }

    public function openCancelModal(int $bookingId): void
    {
        $this->cancellingId = $bookingId;
        $this->cancellationReason = '';
        $this->showCancelModal = true;
    }

    public function confirmCancel(): void
    {
        $booking = AppointmentBooking::findOrFail($this->cancellingId);
        $booking->cancel($this->cancellationReason ?: null);

        $this->showCancelModal = false;
        $this->cancellingId = null;
        $this->cancellationReason = '';
        unset($this->bookings);
    }

    public function getStatusColor(string $status): string
    {
        return match ($status) {
            AppointmentBooking::STATUS_CONFIRMED => 'blue',
            AppointmentBooking::STATUS_COMPLETED => 'green',
            AppointmentBooking::STATUS_CANCELLED => 'red',
            AppointmentBooking::STATUS_NO_SHOW => 'yellow',
            AppointmentBooking::STATUS_RESCHEDULED => 'purple',
            default => 'zinc',
        };
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">{{ __('Bookings') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Manage appointment bookings from vendors and prospects.') }}
            </flux:text>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or email...')" icon="magnifying-glass" class="w-64" />

        <flux:select wire:model.live="statusFilter" class="w-40">
            <option value="">{{ __('All Statuses') }}</option>
            @foreach (AppointmentBooking::STATUSES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="vendorFilter" class="w-44">
            <option value="">{{ __('All Vendors') }}</option>
            @foreach ($this->vendors as $vendor)
                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="estimatorFilter" class="w-44">
            <option value="">{{ __('All Estimators') }}</option>
            @foreach ($this->estimators as $estimator)
                <option value="{{ $estimator->id }}">{{ $estimator->name }}</option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live="dateFrom" type="date" class="w-40" />
        <flux:input wire:model.live="dateTo" type="date" class="w-40" />
    </div>

    {{-- Bookings Table --}}
    @if ($this->bookings->isEmpty())
        <flux:callout variant="outline" icon="calendar-days">
            <flux:callout.heading>{{ __('No bookings found') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Bookings will appear here when prospects schedule appointments through vendor booking links.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-3">
            @foreach ($this->bookings as $booking)
                <div wire:key="booking-{{ $booking->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                    <div class="flex items-start justify-between gap-4">
                        {{-- Left: main info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <flux:badge size="sm" :color="$this->getStatusColor($booking->status)">
                                    {{ AppointmentBooking::STATUSES[$booking->status] ?? ucfirst($booking->status) }}
                                </flux:badge>
                                @if ($booking->estimate_preference)
                                    <flux:badge size="sm" color="zinc">
                                        {{ $booking->estimate_preference === 'in_person' ? __('In Person') : __('Virtual') }}
                                    </flux:badge>
                                @endif
                                @if ($booking->vendor)
                                    <flux:badge size="sm" color="indigo">
                                        <flux:icon name="building-storefront" class="w-3 h-3 mr-0.5" />{{ $booking->vendor->name }}
                                    </flux:badge>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 mb-1">
                                <flux:icon name="user" class="w-4 h-4 text-zinc-400 shrink-0" />
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $booking->getProspectFullName() }}</span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-1 text-sm mt-2">
                                <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                                    <flux:icon name="calendar-days" class="w-3.5 h-3.5 shrink-0 text-zinc-400" />
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $booking->booked_date->format('M j, Y') }}</span>
                                    <span>{{ \Carbon\Carbon::parse($booking->booked_start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($booking->booked_end_time)->format('g:i A') }}</span>
                                </div>
                                @if ($booking->prospect_address)
                                    <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                                        <flux:icon name="map-pin" class="w-3.5 h-3.5 shrink-0 text-zinc-400" />
                                        <span class="truncate">{{ $booking->prospect_address }}</span>
                                    </div>
                                @endif
                                @if ($booking->prospect_phone)
                                    <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                                        <flux:icon name="phone" class="w-3.5 h-3.5 shrink-0 text-zinc-400" />
                                        {{ $booking->prospect_phone }}
                                    </div>
                                @endif
                                @if ($booking->prospect_email)
                                    <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                                        <flux:icon name="envelope" class="w-3.5 h-3.5 shrink-0 text-zinc-400" />
                                        <span class="truncate">{{ $booking->prospect_email }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center gap-4 mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($booking->estimator)
                                    <span>{{ __('Estimator:') }} {{ $booking->estimator->name }}</span>
                                @endif
                                @if ($booking->deal?->customer_id)
                                    <a href="{{ route('customers.show', ['customer' => $booking->deal->customer_id, 'deal' => $booking->deal_id]) }}" wire:navigate class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{ __('Customer Hub') }}
                                    </a>
                                @endif
                            </div>
                        </div>

                        {{-- Right: actions --}}
                        <div class="flex items-center gap-1 shrink-0">
                            @if ($booking->isConfirmed())
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="check-circle"
                                    wire:click="markCompleted({{ $booking->id }})"
                                    wire:confirm="{{ __('Mark this booking as completed?') }}"
                                    title="{{ __('Mark Completed') }}"
                                />
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="exclamation-triangle"
                                    wire:click="markNoShow({{ $booking->id }})"
                                    wire:confirm="{{ __('Mark this booking as no-show?') }}"
                                    title="{{ __('Mark No-Show') }}"
                                    class="text-yellow-600 dark:text-yellow-400"
                                />
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="x-circle"
                                    wire:click="openCancelModal({{ $booking->id }})"
                                    title="{{ __('Cancel') }}"
                                    class="text-red-500 hover:text-red-700"
                                />
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $this->bookings->links() }}
        </div>
    @endif

    {{-- Cancel Modal --}}
    <flux:modal wire:model="showCancelModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Cancel Booking') }}</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('Are you sure you want to cancel this booking? The prospect will be notified.') }}
            </p>

            <flux:field>
                <flux:label>{{ __('Reason (optional)') }}</flux:label>
                <flux:textarea wire:model="cancellationReason" rows="2" :placeholder="__('Why is this being cancelled?')" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showCancelModal', false)" variant="ghost">{{ __('Keep Booking') }}</flux:button>
                <flux:button wire:click="confirmCancel" variant="danger">{{ __('Cancel Booking') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
