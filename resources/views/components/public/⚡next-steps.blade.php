<?php

use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component {
    public ?array $meeting = null;

    public function mount(): void
    {
        $this->meeting = session('meeting_scheduled');
    }

    public function getGoogleCalendarUrlProperty(): ?string
    {
        if (! $this->meeting) {
            return null;
        }

        $start = Carbon::parse($this->meeting['date']);
        $end = $start->copy()->addHour();

        $params = http_build_query([
            'action' => 'TEMPLATE',
            'text' => $this->meeting['title'],
            'dates' => $start->format('Ymd\THis').'/'.$end->format('Ymd\THis'),
            'ctz' => 'America/Denver',
            'details' => 'Partnership meeting with '.$this->meeting['name'].' to discuss the Painting Made Easy Denver agent referral program.',
        ]);

        return 'https://calendar.google.com/calendar/render?'.$params;
    }

    public function downloadIcs(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! $this->meeting) {
            abort(404);
        }

        $start = Carbon::parse($this->meeting['date']);
        $end = $start->copy()->addHour();
        $now = Carbon::now();
        $uid = uniqid('apex-meeting-', true);

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Painting Made Easy Denver//Meeting//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$now->format('Ymd\THis\Z'),
            'DTSTART;TZID=America/Denver:'.$start->format('Ymd\THis'),
            'DTEND;TZID=America/Denver:'.$end->format('Ymd\THis'),
            'SUMMARY:'.$this->meeting['title'],
            'DESCRIPTION:Partnership meeting with '.$this->meeting['name'].' to discuss the Painting Made Easy Denver agent referral program.',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        return response()->streamDownload(function () use ($ics) {
            echo $ics;
        }, 'meeting.ics', [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ]);
    }
}; ?>

@section('robots', 'noindex, nofollow')

<div class="min-h-screen bg-zinc-50 flex items-center justify-center p-6">
    <div class="w-full max-w-2xl">
        {{-- Header --}}
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 text-[#10B981] mb-6">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-3">{{ __('Thanks! Here\'s what happens next...') }}</h1>
            <p class="text-zinc-600 text-lg">{{ __('We\'ve received your request and are on it.') }}</p>
        </div>

        {{-- Steps --}}
        <div class="bg-white rounded-2xl shadow-lg p-8 sm:p-10 mb-8">
            <div class="space-y-8">
                {{-- Step 1 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#2563EB] text-white flex items-center justify-center font-bold text-sm">1</div>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">{{ __('We\'re reviewing your request right now') }}</h3>
                        <p class="text-zinc-600">{{ __('Our team looks at every submission the same day. We\'ll assess your project and start preparing your estimate.') }}</p>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#2563EB] text-white flex items-center justify-center font-bold text-sm">2</div>
                    </div>
                    <div>
                        @if (session('invitation_accept_url'))
                            <h3 class="font-semibold text-zinc-900 text-lg mb-1">{{ __('Set up your customer portal') }}</h3>
                            <p class="text-zinc-600 mb-3">{{ __('Click below to create your account and access your project dashboard.') }}</p>
                            <a href="{{ session('invitation_accept_url') }}" class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-semibold px-6 py-2.5 rounded-lg transition-colors shadow-sm">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                {{ __('Set Up My Account') }}
                            </a>
                            <p class="text-zinc-500 text-sm mt-2">{{ __('We also sent this link to your email.') }}</p>
                        @else
                            <h3 class="font-semibold text-zinc-900 text-lg mb-1">{{ __('Check your email for a portal invitation') }}</h3>
                            <p class="text-zinc-600">{{ __('You\'ll receive an email with a link to your personal customer portal, where you can view your estimate, photos, and project details.') }}</p>
                        @endif
                    </div>
                </div>

                {{-- Step 3 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#2563EB] text-white flex items-center justify-center font-bold text-sm">3</div>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">{{ __('Review and sign your estimate online') }}</h3>
                        <p class="text-zinc-600">{{ __('Your detailed proposal will appear in your portal with transparent pricing. When you\'re ready, sign digitally right from your phone or computer.') }}</p>
                    </div>
                </div>

                {{-- Step 4 --}}
                <div class="flex gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-[#10B981] text-white flex items-center justify-center font-bold text-sm">4</div>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">{{ __('We schedule, prep, and paint') }}</h3>
                        <p class="text-zinc-600">{{ __('You track everything in real time through your portal. From crew dispatch to final walkthrough, you\'ll always know what\'s happening.') }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Meeting scheduled - Add to Calendar --}}
        @if ($meeting)
            @php
                $meetingDate = \Carbon\Carbon::parse($meeting['date']);
            @endphp
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-8">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-zinc-900 text-lg mb-1">{{ __('Meeting Scheduled') }}</h3>
                        <p class="text-zinc-600 mb-4">{{ $meetingDate->format('l, F j, Y') }} at {{ $meetingDate->format('g:i A') }} (Denver time)</p>
                        <div class="flex flex-wrap gap-3">
                            <a href="{{ $this->googleCalendarUrl }}" target="_blank" rel="noopener noreferrer"
                                class="inline-flex items-center gap-2 bg-white border border-zinc-200 hover:border-zinc-300 text-zinc-700 font-medium px-4 py-2 rounded-lg text-sm transition-colors shadow-sm">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 22h-15A2.5 2.5 0 012 19.5v-15A2.5 2.5 0 014.5 2H6v1.5a.5.5 0 001 0V2h10v1.5a.5.5 0 001 0V2h1.5A2.5 2.5 0 0122 4.5v15a2.5 2.5 0 01-2.5 2.5zM4.5 3.5A1 1 0 003.5 4.5v15a1 1 0 001 1h15a1 1 0 001-1v-15a1 1 0 00-1-1H19v.5a.5.5 0 01-1 0V3.5H7v.5a.5.5 0 01-1 0V3.5zM20 8H4v-.5h16zM8 11h3v3H8zm5 0h3v3h-3zm-5 5h3v3H8zm5 0h3v3h-3z"/></svg>
                                {{ __('Google Calendar') }}
                            </a>
                            <button wire:click="downloadIcs"
                                class="inline-flex items-center gap-2 bg-white border border-zinc-200 hover:border-zinc-300 text-zinc-700 font-medium px-4 py-2 rounded-lg text-sm transition-colors shadow-sm">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                {{ __('Apple / Outlook (.ics)') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Speed callout --}}
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center mb-8">
            <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-amber-100 text-[#F59E0B] mb-3">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
            </div>
            <p class="font-semibold text-amber-900 text-lg">{{ __('Most customers receive their estimate the same day') }}</p>
        </div>

        {{-- Back to home --}}
        <div class="text-center">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-[#2563EB] hover:text-blue-700 font-medium transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                {{ __('Back to Home') }}
            </a>
        </div>
    </div>
</div>
