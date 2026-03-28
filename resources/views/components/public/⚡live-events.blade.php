<?php

use App\Models\CompanySetting;
use App\Models\LiveEvent;
use App\Models\ServiceArea;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('Live Events | Painting Made Easy Denver')] class extends Component
{
    #[Url(as: 'area')]
    public string $selectedArea = '';

    #[Computed]
    public function liveEvents()
    {
        return LiveEvent::live()
            ->with('serviceArea', 'service', 'host')
            ->when($this->selectedArea, fn ($q) => $q->where('service_area_id', $this->selectedArea))
            ->get();
    }

    #[Computed]
    public function upcomingEvents()
    {
        return LiveEvent::upcoming()
            ->with('serviceArea', 'service', 'host')
            ->when($this->selectedArea, fn ($q) => $q->where('service_area_id', $this->selectedArea))
            ->orderBy('scheduled_at')
            ->get();
    }

    #[Computed]
    public function pastEvents()
    {
        return LiveEvent::ended()
            ->with('serviceArea', 'service')
            ->when($this->selectedArea, fn ($q) => $q->where('service_area_id', $this->selectedArea))
            ->latest('ended_at')
            ->take(6)
            ->get();
    }

    #[Computed]
    public function serviceAreas()
    {
        return ServiceArea::active()->orderBy('city')->get(['id', 'city', 'slug']);
    }

    #[Computed]
    public function structuredData(): string
    {
        $company = CompanySetting::getInstance();
        $name = $company->company_name ?? config('app.name');

        $events = LiveEvent::where('status', '!=', 'cancelled')
            ->with('serviceArea', 'host')
            ->orderBy('scheduled_at')
            ->take(20)
            ->get();

        $eventSchemas = $events->map(fn (LiveEvent $event) => [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'description' => $event->description ?? '',
            'startDate' => $event->scheduled_at?->toIso8601String(),
            'endDate' => $event->ended_at?->toIso8601String() ?? $event->scheduled_at?->addHours(2)->toIso8601String(),
            'eventStatus' => match ($event->status) {
                'live' => 'https://schema.org/EventMovedOnline',
                'ended' => 'https://schema.org/EventScheduled',
                default => 'https://schema.org/EventScheduled',
            },
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'location' => [
                '@type' => 'VirtualLocation',
                'url' => route('live.show', $event->slug),
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => $name,
                'url' => url('/'),
            ],
            'performer' => $event->host ? [
                '@type' => 'Person',
                'name' => $event->host->name,
            ] : null,
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
                'availability' => 'https://schema.org/InStock',
                'url' => route('live.show', $event->slug),
            ],
            'isAccessibleForFree' => true,
        ])->toArray();

        return collect($eventSchemas)
            ->map(fn ($schema) => '<script type="application/ld+json">'.json_encode(array_filter($schema), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).'</script>')
            ->implode("\n");
    }
};
?>

<div>
    @section('meta_title', 'Live Events | Painting Made Easy Denver')
    @section('meta_description', 'Watch live painting demos, Q&A sessions, and home improvement tips from Painting Made Easy Denver. Free to join.')
    @section('canonical', route('live.index'))
    @section('robots', 'index, follow')

    @push('structured_data')
    {!! $this->structuredData !!}
    @endpush

    {{-- Hero Section --}}
    <section class="relative bg-brand-900 overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700 opacity-90"></div>
        <div class="absolute inset-0" style="background-image: radial-gradient(circle at 20% 50%, rgba(201, 168, 76, 0.08) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(15, 52, 96, 0.15) 0%, transparent 50%);"></div>

        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 pt-28 pb-16 sm:pt-32 sm:pb-20 text-center">
            <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm rounded-full px-4 py-1.5 mb-6">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                </span>
                <span class="text-sm font-medium text-white/90">Live & Upcoming Events</span>
            </div>

            <h1 class="font-heading text-4xl sm:text-5xl lg:text-6xl text-white mb-4">
                Live Painting Events
            </h1>
            <p class="text-lg sm:text-xl text-zinc-300 max-w-2xl mx-auto mb-8">
                Watch live painting demos, ask questions in real time, and learn pro tips for your next home project.
            </p>

            {{-- Area Filter --}}
            <div class="max-w-xs mx-auto">
                <select
                    wire:model.live="selectedArea"
                    class="w-full px-4 py-2.5 rounded-xl bg-white/10 backdrop-blur-sm border border-white/20 text-white text-sm focus:outline-none focus:ring-2 focus:ring-accent-gold/50 appearance-none"
                    style="background-image: url('data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 12 12%22%3E%3Cpath fill=%22%23fff%22 d=%22M6 8L1 3h10z%22/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 12px center;"
                >
                    <option value="" class="bg-zinc-800">All Service Areas</option>
                    @foreach ($this->serviceAreas as $area)
                        <option value="{{ $area->id }}" class="bg-zinc-800">{{ $area->city }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 sm:px-6">

        {{-- Currently Live Section --}}
        @if ($this->liveEvents->isNotEmpty())
            <section class="py-12 sm:py-16">
                <div class="flex items-center gap-3 mb-8">
                    <span class="relative flex h-3 w-3">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                    </span>
                    <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900">Live Now</h2>
                </div>

                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->liveEvents as $event)
                        <a href="{{ route('live.show', $event->slug) }}" class="group block">
                            <div class="relative rounded-2xl overflow-hidden shadow-lg ring-2 ring-red-500/40 hover:ring-red-500/60 transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5">
                                {{-- Cover image or gradient --}}
                                <div class="aspect-video bg-gradient-to-br from-red-600 via-brand-800 to-brand-900 relative">
                                    @if ($event->cover_image)
                                        <img src="{{ $event->cover_image }}" alt="{{ $event->title }}" class="absolute inset-0 w-full h-full object-cover" loading="lazy">
                                    @endif
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent"></div>

                                    {{-- Live badge --}}
                                    <div class="absolute top-3 left-3 flex items-center gap-1.5 bg-red-600 text-white text-xs font-bold px-2.5 py-1 rounded-full shadow-lg">
                                        <span class="relative flex h-2 w-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
                                        </span>
                                        LIVE
                                    </div>

                                    {{-- Title overlay --}}
                                    <div class="absolute bottom-0 left-0 right-0 p-4">
                                        <h3 class="font-heading text-xl text-white group-hover:text-accent-gold transition-colors">{{ $event->title }}</h3>
                                    </div>
                                </div>

                                <div class="bg-white p-4 space-y-3">
                                    {{-- Tags --}}
                                    <div class="flex flex-wrap gap-2">
                                        @if ($event->serviceArea)
                                            <span class="inline-flex items-center gap-1 text-xs font-medium bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                                {{ $event->serviceArea->city }}
                                            </span>
                                        @endif
                                        @if ($event->service)
                                            <span class="inline-flex items-center text-xs font-medium bg-green-50 text-green-700 px-2 py-0.5 rounded-full">{{ $event->service->name }}</span>
                                        @endif
                                    </div>

                                    {{-- Host --}}
                                    @if ($event->host)
                                        <div class="flex items-center gap-2 text-sm text-zinc-600">
                                            <div class="w-6 h-6 rounded-full bg-brand-700 flex items-center justify-center text-xs text-white font-medium">
                                                {{ strtoupper(substr($event->host->name, 0, 1)) }}
                                            </div>
                                            <span>{{ $event->host->name }}</span>
                                        </div>
                                    @endif

                                    {{-- CTA --}}
                                    <div class="inline-flex items-center gap-2 text-sm font-semibold text-red-600 group-hover:text-red-700 transition-colors">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        Watch Now
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Upcoming Events Section --}}
        <section class="py-12 sm:py-16 {{ $this->liveEvents->isNotEmpty() ? 'border-t border-zinc-200' : '' }}">
            <div class="flex items-center gap-3 mb-8">
                <svg class="w-6 h-6 text-accent-gold" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900">Upcoming Events</h2>
            </div>

            @if ($this->upcomingEvents->isEmpty())
                <div class="text-center py-16 rounded-2xl bg-warm-50 border border-warm-200">
                    <svg class="w-12 h-12 text-zinc-300 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    <p class="text-zinc-500 text-lg font-medium mb-1">No upcoming events scheduled</p>
                    <p class="text-zinc-400 text-sm">Check back soon for new live painting events and demos.</p>
                </div>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->upcomingEvents as $event)
                        <div
                            class="group rounded-2xl overflow-hidden shadow-sm hover:shadow-lg border border-zinc-200 hover:border-accent-gold/30 transition-all duration-300 hover:-translate-y-0.5 bg-white"
                            x-data="{ countdown: '', interval: null }"
                            x-init="
                                const target = new Date('{{ $event->scheduled_at->toIso8601String() }}').getTime();
                                function update() {
                                    const now = Date.now();
                                    const diff = target - now;
                                    if (diff <= 0) { countdown = 'Starting soon'; return; }
                                    const d = Math.floor(diff / 86400000);
                                    const h = Math.floor((diff % 86400000) / 3600000);
                                    const m = Math.floor((diff % 3600000) / 60000);
                                    const s = Math.floor((diff % 60000) / 1000);
                                    countdown = (d > 0 ? d + 'd ' : '') + h + 'h ' + m + 'm ' + s + 's';
                                }
                                update();
                                interval = setInterval(update, 1000);
                            "
                            x-on:destroy.window="clearInterval(interval)"
                        >
                            {{-- Cover --}}
                            <div class="aspect-video bg-gradient-to-br from-brand-700 via-brand-800 to-brand-900 relative">
                                @if ($event->cover_image)
                                    <img src="{{ $event->cover_image }}" alt="{{ $event->title }}" class="absolute inset-0 w-full h-full object-cover" loading="lazy">
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <svg class="w-16 h-16 text-white/10" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                                    </div>
                                @endif
                                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>

                                {{-- Countdown overlay --}}
                                <div class="absolute bottom-3 left-3 inline-flex items-center gap-1.5 bg-black/60 backdrop-blur-sm text-white text-xs font-mono px-2.5 py-1 rounded-full">
                                    <svg class="w-3.5 h-3.5 text-accent-gold" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    <span x-text="countdown"></span>
                                </div>
                            </div>

                            <div class="p-4 space-y-3">
                                <h3 class="font-heading text-lg text-zinc-900 group-hover:text-brand-700 transition-colors">{{ $event->title }}</h3>

                                {{-- Date --}}
                                <div class="flex items-center gap-2 text-sm text-zinc-500">
                                    <svg class="w-4 h-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                                    {{ $event->scheduled_at->format('M j, Y') }} at {{ $event->scheduled_at->format('g:i A') }}
                                </div>

                                {{-- Tags --}}
                                <div class="flex flex-wrap gap-2">
                                    @if ($event->serviceArea)
                                        <span class="inline-flex items-center gap-1 text-xs font-medium bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                            {{ $event->serviceArea->city }}
                                        </span>
                                    @endif
                                    @if ($event->service)
                                        <span class="inline-flex items-center text-xs font-medium bg-green-50 text-green-700 px-2 py-0.5 rounded-full">{{ $event->service->name }}</span>
                                    @endif
                                </div>

                                {{-- Host --}}
                                @if ($event->host)
                                    <div class="flex items-center gap-2 text-sm text-zinc-600">
                                        <div class="w-6 h-6 rounded-full bg-brand-700 flex items-center justify-center text-xs text-white font-medium">
                                            {{ strtoupper(substr($event->host->name, 0, 1)) }}
                                        </div>
                                        <span>Hosted by {{ $event->host->name }}</span>
                                    </div>
                                @endif

                                @if ($event->description)
                                    <p class="text-sm text-zinc-500 line-clamp-2">{{ $event->description }}</p>
                                @endif

                                {{-- CTA --}}
                                <a href="{{ route('live.show', $event->slug) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-cta hover:text-cta-hover transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                                    Set Reminder
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Past Events Section --}}
        @if ($this->pastEvents->isNotEmpty())
            <section class="py-12 sm:py-16 border-t border-zinc-200">
                <div class="flex items-center gap-3 mb-8">
                    <svg class="w-6 h-6 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900">Past Events</h2>
                </div>

                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->pastEvents as $event)
                        <div class="rounded-2xl overflow-hidden shadow-sm border border-zinc-200 bg-white opacity-80 hover:opacity-100 transition-opacity">
                            {{-- Cover --}}
                            <div class="aspect-video bg-gradient-to-br from-zinc-600 to-zinc-800 relative">
                                @if ($event->cover_image)
                                    <img src="{{ $event->cover_image }}" alt="{{ $event->title }}" class="absolute inset-0 w-full h-full object-cover grayscale-[30%]" loading="lazy">
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <svg class="w-16 h-16 text-white/10" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                                    </div>
                                @endif
                                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>

                                {{-- Ended badge --}}
                                <div class="absolute top-3 left-3 inline-flex items-center text-xs font-medium bg-zinc-700/80 text-zinc-200 px-2.5 py-1 rounded-full">
                                    Event Ended
                                </div>
                            </div>

                            <div class="p-4 space-y-3">
                                <h3 class="font-heading text-lg text-zinc-900">{{ $event->title }}</h3>

                                <div class="flex items-center gap-2 text-sm text-zinc-400">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                                    {{ $event->ended_at?->format('M j, Y') ?? $event->scheduled_at?->format('M j, Y') }}
                                </div>

                                {{-- Tags --}}
                                <div class="flex flex-wrap gap-2">
                                    @if ($event->serviceArea)
                                        <span class="inline-flex items-center gap-1 text-xs font-medium bg-zinc-100 text-zinc-600 px-2 py-0.5 rounded-full">{{ $event->serviceArea->city }}</span>
                                    @endif
                                    @if ($event->service)
                                        <span class="inline-flex items-center text-xs font-medium bg-zinc-100 text-zinc-600 px-2 py-0.5 rounded-full">{{ $event->service->name }}</span>
                                    @endif
                                </div>

                                @if ($event->recording_path)
                                    <span class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        Watch Replay
                                    </span>
                                @else
                                    <span class="text-sm text-zinc-400 italic">Replay available soon</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Empty state when nothing at all --}}
        @if ($this->liveEvents->isEmpty() && $this->upcomingEvents->isEmpty() && $this->pastEvents->isEmpty())
            <section class="py-20 sm:py-28 text-center">
                <div class="max-w-md mx-auto">
                    <div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-gradient-to-br from-brand-700 to-brand-900 flex items-center justify-center">
                        <svg class="w-10 h-10 text-white/60" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                    </div>
                    <h2 class="font-heading text-2xl text-zinc-900 mb-2">No Events Yet</h2>
                    <p class="text-zinc-500 mb-6">We are preparing exciting live painting events for the Denver metro area. Check back soon!</p>
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-cta text-white text-sm font-semibold hover:bg-cta-hover transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                        Back to Home
                    </a>
                </div>
            </section>
        @endif

    </div>
</div>
