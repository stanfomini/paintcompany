<?php

use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.public')] #[Title('Booking Confirmed')] class extends Component {
    public string $token = '';
    public ?AppointmentBooking $booking = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->booking = AppointmentBooking::where('booking_token', $token)
            ->with(['estimator', 'vendor'])
            ->firstOrFail();
    }

    #[Computed]
    public function companySettings(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function statusColor(): string
    {
        return match ($this->booking->status) {
            AppointmentBooking::STATUS_CONFIRMED => 'green',
            AppointmentBooking::STATUS_COMPLETED => 'blue',
            AppointmentBooking::STATUS_CANCELLED => 'red',
            AppointmentBooking::STATUS_RESCHEDULED => 'amber',
            AppointmentBooking::STATUS_NO_SHOW => 'red',
            default => 'zinc',
        };
    }

    #[Computed]
    public function statusLabel(): string
    {
        return AppointmentBooking::STATUSES[$this->booking->status] ?? ucfirst($this->booking->status);
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

    #[Computed]
    public function googleCalendarUrl(): string
    {
        $b = $this->booking;
        $start = Carbon::parse($b->booked_date->format('Y-m-d').' '.$b->booked_start_time);
        $end = Carbon::parse($b->booked_date->format('Y-m-d').' '.$b->booked_end_time);

        $params = http_build_query([
            'action' => 'TEMPLATE',
            'text' => 'Estimate Appointment',
            'dates' => $start->format('Ymd\THis').'/'.$end->format('Ymd\THis'),
            'location' => $b->prospect_address,
            'details' => 'Estimate appointment with '.($this->companySettings->company_name ?? 'our team'),
        ]);

        return 'https://calendar.google.com/calendar/render?'.$params;
    }
};
?>

@section('robots', 'noindex, nofollow')

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-900 py-8 px-4">
    <div class="mx-auto max-w-lg">
        {{-- Header --}}
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="inline-block">
                <flux:heading size="xl" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors">{{ $this->companySettings->company_name ?? config('app.name') }}</flux:heading>
            </a>
            <flux:text class="mt-2 text-lg">{{ __('Appointment Details') }}</flux:text>
        </div>

        {{-- Booking Card --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-6">
            {{-- Status Badge --}}
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Your Booking') }}</flux:heading>
                <flux:badge :color="$this->statusColor">{{ $this->statusLabel }}</flux:badge>
            </div>

            {{-- Booking Details --}}
            <div class="space-y-4">
                {{-- Date --}}
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                        <flux:icon name="calendar-days" variant="mini" />
                    </div>
                    <div>
                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->formattedDate }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $this->formattedTime }}</flux:text>
                    </div>
                </div>

                {{-- Estimator --}}
                @if ($booking->estimator)
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                            <flux:icon name="user" variant="mini" />
                        </div>
                        <div>
                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $booking->estimator->name }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Your Estimator') }}</flux:text>
                        </div>
                    </div>
                @endif

                {{-- Address --}}
                @if ($booking->prospect_address)
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 text-zinc-400 dark:text-zinc-500">
                            <flux:icon name="map-pin" variant="mini" />
                        </div>
                        <div>
                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $booking->prospect_address }}</flux:text>
                            @if ($booking->prospect_city || $booking->prospect_state || $booking->prospect_zip)
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                    {{ collect([$booking->prospect_city, $booking->prospect_state])->filter()->join(', ') }}
                                    {{ $booking->prospect_zip }}
                                </flux:text>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Actions --}}
            @if ($booking->isConfirmed())
                <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4 space-y-3">
                    {{-- Add to Google Calendar --}}
                    <a href="{{ $this->googleCalendarUrl }}" target="_blank" rel="noopener noreferrer" class="w-full">
                        <flux:button variant="primary" class="w-full">
                            <flux:icon name="calendar" variant="mini" class="mr-2" />
                            {{ __('Add to Google Calendar') }}
                        </flux:button>
                    </a>

                    {{-- Cancel / Reschedule --}}
                    <div class="text-center">
                        <a href="{{ route('booking.cancel', $booking->booking_token) }}" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-red-600 dark:hover:text-red-400 underline">
                            {{ __('Need to cancel or reschedule?') }}
                        </a>
                    </div>
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

        {{-- Contact info --}}
        <div class="mt-6 text-center">
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Questions? Contact us at') }}
                @if ($this->companySettings->company_phone)
                    <a href="tel:{{ $this->companySettings->company_phone }}" class="underline">{{ $this->companySettings->company_phone }}</a>
                @else
                    {{ $this->companySettings->company_email ?? config('mail.from.address') }}
                @endif
            </flux:text>
        </div>
    </div>
</div>
