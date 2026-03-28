<?php

use App\Models\ChatMessage;
use App\Models\LiveEvent;
use App\Models\LiveEventRegistration;
use App\Services\LiveEventService;
use App\Services\LiveKitService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component
{
    public LiveEvent $event;

    public string $viewerName = '';

    public string $viewerEmail = '';

    public string $viewerPhone = '';

    public string $questionText = '';

    public bool $hasJoined = false;

    public string $livekitToken = '';

    public string $livekitUrl = '';

    public string $activeTab = 'chat';

    public string $chatMessage = '';

    public function mount(LiveEvent $liveEvent): void
    {
        $this->event = $liveEvent->load('serviceArea', 'service', 'host', 'chatThread');
        $this->livekitUrl = (string) config('services.livekit.url', '');
    }

    public function joinStream(): void
    {
        if ($this->event->access_type === 'registration') {
            $this->validate([
                'viewerName' => ['required', 'string', 'max:255'],
                'viewerEmail' => ['required', 'email', 'max:255'],
            ]);

            $liveEventService = app(LiveEventService::class);
            $liveEventService->registerViewer($this->event, [
                'name' => $this->viewerName,
                'email' => $this->viewerEmail,
                'phone' => $this->viewerPhone ?: null,
            ]);
        } else {
            $this->validate([
                'viewerName' => ['required', 'string', 'max:255'],
            ]);
        }

        try {
            $liveKitService = app(LiveKitService::class);
            $this->livekitToken = $liveKitService->generateViewerToken(
                $this->event,
                $this->viewerName
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LiveKit viewer token error', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->hasJoined = true;

        $this->dispatch('livekit-viewer-connect', token: $this->livekitToken, url: $this->livekitUrl);
    }

    public function registerForEvent(): void
    {
        $this->validate([
            'viewerName' => ['required', 'string', 'max:255'],
            'viewerEmail' => ['required', 'email', 'max:255'],
            'viewerPhone' => ['nullable', 'string', 'max:50'],
        ]);

        $liveEventService = app(LiveEventService::class);
        $liveEventService->registerViewer($this->event, [
            'name' => $this->viewerName,
            'email' => $this->viewerEmail,
            'phone' => $this->viewerPhone ?: null,
        ]);

        session()->flash('registered', true);
        $this->dispatch('registration-complete');
    }

    public function submitQuestion(): void
    {
        $this->validate([
            'questionText' => ['required', 'string', 'max:1000'],
        ]);

        $liveEventService = app(LiveEventService::class);
        $liveEventService->submitQuestion(
            $this->event,
            $this->questionText,
            $this->viewerName
        );

        $this->questionText = '';
        $this->dispatch('question-submitted');
    }

    public function sendChatMessage(): void
    {
        $this->validate([
            'chatMessage' => ['required', 'string', 'max:500'],
        ]);

        if (! $this->event->chat_thread_id) {
            return;
        }

        ChatMessage::create([
            'thread_id' => $this->event->chat_thread_id,
            'content' => $this->viewerName.': '.$this->chatMessage,
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => false,
        ]);

        $this->chatMessage = '';
    }

    #[Computed]
    public function pinnedResources()
    {
        return $this->event->resources()
            ->where('is_pinned', true)
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function allResources()
    {
        return $this->event->resources()
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function answeredQuestions()
    {
        return $this->event->questions()
            ->answered()
            ->latest('answered_at')
            ->get();
    }

    #[Computed]
    public function chatMessages()
    {
        if (! $this->event->chat_thread_id) {
            return collect();
        }

        return ChatMessage::where('thread_id', $this->event->chat_thread_id)
            ->where('is_internal', false)
            ->latest()
            ->take(50)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function relatedEvents()
    {
        return LiveEvent::upcoming()
            ->where('id', '!=', $this->event->id)
            ->when($this->event->service_area_id, fn ($q) => $q->where('service_area_id', $this->event->service_area_id))
            ->with('serviceArea', 'service')
            ->take(3)
            ->get();
    }

    #[Computed]
    public function structuredData(): string
    {
        $company = \App\Models\CompanySetting::getInstance();
        $name = $company->company_name ?? config('app.name');

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $this->event->title,
            'description' => $this->event->description ?? '',
            'startDate' => $this->event->scheduled_at?->toIso8601String(),
            'endDate' => $this->event->ended_at?->toIso8601String() ?? $this->event->scheduled_at?->addHours(2)->toIso8601String(),
            'eventStatus' => match ($this->event->status) {
                'live' => 'https://schema.org/EventMovedOnline',
                'ended' => 'https://schema.org/EventScheduled',
                default => 'https://schema.org/EventScheduled',
            },
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'location' => [
                '@type' => 'VirtualLocation',
                'url' => route('live.show', $this->event->slug),
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => $name,
                'url' => url('/'),
            ],
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
                'availability' => 'https://schema.org/InStock',
                'url' => route('live.show', $this->event->slug),
            ],
            'isAccessibleForFree' => true,
        ];

        if ($this->event->host) {
            $schema['performer'] = [
                '@type' => 'Person',
                'name' => $this->event->host->name,
            ];
        }

        if ($this->event->cover_image) {
            $schema['image'] = $this->event->cover_image;
        }

        return '<script type="application/ld+json">'.json_encode(array_filter($schema), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).'</script>';
    }
};
?>

<div>
    @section('meta_title', $event->title . ' | Live Event | Painting Made Easy Denver')
    @section('meta_description', Str::limit($event->description ?? 'Watch this live painting event from Painting Made Easy Denver.', 160))
    @section('canonical', route('live.show', $event->slug))
    @section('robots', 'index, follow')

    @push('structured_data')
    {!! $this->structuredData !!}
    @endpush

    {{-- ==================== PRE-EVENT STATE ==================== --}}
    @if ($event->status === 'scheduled')
        <section class="relative bg-brand-900 overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700"></div>
            <div class="absolute inset-0" style="background-image: radial-gradient(circle at 30% 40%, rgba(201, 168, 76, 0.1) 0%, transparent 50%), radial-gradient(circle at 70% 80%, rgba(15, 52, 96, 0.2) 0%, transparent 50%);"></div>

            <div class="relative max-w-4xl mx-auto px-4 sm:px-6 pt-28 pb-16 sm:pt-32 sm:pb-20">
                {{-- Back link --}}
                <a href="{{ route('live.index') }}" class="inline-flex items-center gap-1.5 text-sm text-white/60 hover:text-white/90 transition-colors mb-8">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                    All Events
                </a>

                <div class="text-center">
                    {{-- Badge --}}
                    <div class="inline-flex items-center gap-2 bg-accent-gold/20 text-accent-gold text-sm font-medium px-3 py-1 rounded-full mb-6">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                        Upcoming Event
                    </div>

                    <h1 class="font-heading text-3xl sm:text-5xl text-white mb-4">{{ $event->title }}</h1>

                    @if ($event->description)
                        <p class="text-lg text-zinc-300 max-w-2xl mx-auto mb-8">{{ $event->description }}</p>
                    @endif

                    {{-- Countdown --}}
                    <div
                        x-data="{
                            days: 0, hours: 0, minutes: 0, seconds: 0,
                            init() {
                                const target = new Date('{{ $event->scheduled_at->toIso8601String() }}').getTime();
                                const update = () => {
                                    const diff = Math.max(0, target - Date.now());
                                    this.days = Math.floor(diff / 86400000);
                                    this.hours = Math.floor((diff % 86400000) / 3600000);
                                    this.minutes = Math.floor((diff % 3600000) / 60000);
                                    this.seconds = Math.floor((diff % 60000) / 1000);
                                };
                                update();
                                setInterval(update, 1000);
                            }
                        }"
                        class="flex items-center justify-center gap-3 sm:gap-5 mb-10"
                    >
                        <div class="text-center">
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/10">
                                <span class="text-2xl sm:text-3xl font-bold text-white font-mono" x-text="String(days).padStart(2, '0')"></span>
                            </div>
                            <span class="text-xs text-zinc-400 mt-1.5 block">Days</span>
                        </div>
                        <span class="text-2xl text-white/30 font-light mt-[-1rem]">:</span>
                        <div class="text-center">
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/10">
                                <span class="text-2xl sm:text-3xl font-bold text-white font-mono" x-text="String(hours).padStart(2, '0')"></span>
                            </div>
                            <span class="text-xs text-zinc-400 mt-1.5 block">Hours</span>
                        </div>
                        <span class="text-2xl text-white/30 font-light mt-[-1rem]">:</span>
                        <div class="text-center">
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/10">
                                <span class="text-2xl sm:text-3xl font-bold text-white font-mono" x-text="String(minutes).padStart(2, '0')"></span>
                            </div>
                            <span class="text-xs text-zinc-400 mt-1.5 block">Min</span>
                        </div>
                        <span class="text-2xl text-white/30 font-light mt-[-1rem]">:</span>
                        <div class="text-center">
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/10">
                                <span class="text-2xl sm:text-3xl font-bold text-accent-gold font-mono" x-text="String(seconds).padStart(2, '0')"></span>
                            </div>
                            <span class="text-xs text-zinc-400 mt-1.5 block">Sec</span>
                        </div>
                    </div>

                    {{-- Event details row --}}
                    <div class="flex flex-wrap items-center justify-center gap-4 sm:gap-6 mb-10">
                        <div class="flex items-center gap-2 text-sm text-zinc-300">
                            <svg class="w-4 h-4 text-accent-gold" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                            {{ $event->scheduled_at->format('l, M j, Y') }}
                        </div>
                        <div class="flex items-center gap-2 text-sm text-zinc-300">
                            <svg class="w-4 h-4 text-accent-gold" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            {{ $event->scheduled_at->format('g:i A T') }}
                        </div>
                        @if ($event->host)
                            <div class="flex items-center gap-2 text-sm text-zinc-300">
                                <div class="w-5 h-5 rounded-full bg-accent-gold/30 flex items-center justify-center text-[10px] text-accent-gold font-medium">
                                    {{ strtoupper(substr($event->host->name, 0, 1)) }}
                                </div>
                                {{ $event->host->name }}
                            </div>
                        @endif
                    </div>

                    {{-- Tags --}}
                    <div class="flex flex-wrap items-center justify-center gap-2 mb-10">
                        @if ($event->serviceArea)
                            <span class="inline-flex items-center gap-1 text-xs font-medium bg-white/10 text-white/80 px-3 py-1 rounded-full">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                {{ $event->serviceArea->city }}
                            </span>
                        @endif
                        @if ($event->service)
                            <span class="inline-flex items-center text-xs font-medium bg-white/10 text-white/80 px-3 py-1 rounded-full">{{ $event->service->name }}</span>
                        @endif
                        <span class="inline-flex items-center text-xs font-medium bg-green-500/20 text-green-300 px-3 py-1 rounded-full">Free to Join</span>
                    </div>
                </div>

                {{-- Registration / Join Form --}}
                <div class="max-w-md mx-auto">
                    @if (session('registered'))
                        <div class="bg-green-500/20 border border-green-500/30 rounded-2xl p-6 text-center">
                            <svg class="w-10 h-10 text-green-400 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <h3 class="text-lg font-semibold text-white mb-1">You are registered!</h3>
                            <p class="text-sm text-zinc-300">We will notify you when the event goes live.</p>
                        </div>
                    @else
                        <div class="bg-white/5 backdrop-blur-sm border border-white/10 rounded-2xl p-6">
                            <h3 class="text-lg font-semibold text-white mb-4 text-center">
                                {{ $event->access_type === 'registration' ? 'Register for This Event' : 'Get Notified When We Go Live' }}
                            </h3>

                            <form wire:submit="{{ $event->access_type === 'registration' ? 'registerForEvent' : 'registerForEvent' }}" class="space-y-4">
                                <div>
                                    <label for="viewerName" class="block text-sm font-medium text-zinc-300 mb-1">Name</label>
                                    <input
                                        wire:model="viewerName"
                                        type="text"
                                        id="viewerName"
                                        class="w-full px-4 py-2.5 rounded-xl bg-white/10 border border-white/20 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-accent-gold/50 focus:border-accent-gold/50"
                                        placeholder="Your name"
                                    >
                                    @error('viewerName') <span class="text-red-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label for="viewerEmail" class="block text-sm font-medium text-zinc-300 mb-1">Email</label>
                                    <input
                                        wire:model="viewerEmail"
                                        type="email"
                                        id="viewerEmail"
                                        class="w-full px-4 py-2.5 rounded-xl bg-white/10 border border-white/20 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-accent-gold/50 focus:border-accent-gold/50"
                                        placeholder="you@example.com"
                                    >
                                    @error('viewerEmail') <span class="text-red-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label for="viewerPhone" class="block text-sm font-medium text-zinc-300 mb-1">Phone <span class="text-zinc-500">(optional)</span></label>
                                    <input
                                        wire:model="viewerPhone"
                                        type="tel"
                                        id="viewerPhone"
                                        class="w-full px-4 py-2.5 rounded-xl bg-white/10 border border-white/20 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-accent-gold/50 focus:border-accent-gold/50"
                                        placeholder="(720) 555-0100"
                                    >
                                    @error('viewerPhone') <span class="text-red-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                                </div>

                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    class="w-full py-3 rounded-xl bg-accent-gold text-brand-900 font-semibold hover:bg-accent-gold/90 transition-colors disabled:opacity-50"
                                >
                                    <span wire:loading.remove>Register</span>
                                    <span wire:loading>Registering...</span>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </section>

    {{-- ==================== LIVE STATE ==================== --}}
    @elseif ($event->status === 'live')
        <div
            x-data="livekitViewer()"
            class="bg-zinc-950 min-h-screen pt-14 pwa-safe-top"
        >
            {{-- Top bar --}}
            <div class="fixed top-0 inset-x-0 z-50 bg-zinc-900/95 backdrop-blur border-b border-zinc-800 h-14">
                <div class="max-w-full mx-auto px-4 flex items-center justify-between h-full">
                    <div class="flex items-center gap-3 min-w-0">
                        <a href="{{ route('live.index') }}" class="text-zinc-400 hover:text-white transition-colors shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                        </a>
                        <div class="min-w-0">
                            <h1 class="text-sm font-semibold text-white truncate">{{ $event->title }}</h1>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                            </span>
                            LIVE
                        </div>
                    </div>
                </div>
            </div>

            {{-- Access Gate: must enter name before joining --}}
            @if (! $hasJoined)
                <div class="flex items-center justify-center min-h-[calc(100vh-3.5rem)]">
                    <div class="max-w-md w-full mx-4">
                        <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-6 sm:p-8">
                            <div class="text-center mb-6">
                                <div class="inline-flex items-center gap-2 bg-red-500/20 text-red-400 text-sm font-medium px-3 py-1 rounded-full mb-4">
                                    <span class="relative flex h-2 w-2">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-400"></span>
                                    </span>
                                    Live Now
                                </div>
                                <h2 class="text-xl font-heading text-white mb-2">{{ $event->title }}</h2>
                                @if ($event->host)
                                    <p class="text-sm text-zinc-400">Hosted by {{ $event->host->name }}</p>
                                @endif
                            </div>

                            <form wire:submit="joinStream" class="space-y-4">
                                <div>
                                    <label for="joinName" class="block text-sm font-medium text-zinc-300 mb-1">Your Name</label>
                                    <input
                                        wire:model="viewerName"
                                        type="text"
                                        id="joinName"
                                        class="w-full px-4 py-2.5 rounded-xl bg-zinc-800 border border-zinc-700 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-red-500/50 focus:border-red-500/50"
                                        placeholder="Enter your name"
                                        autofocus
                                    >
                                    @error('viewerName') <span class="text-red-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                                </div>

                                @if ($event->access_type === 'registration')
                                    <div>
                                        <label for="joinEmail" class="block text-sm font-medium text-zinc-300 mb-1">Email</label>
                                        <input
                                            wire:model="viewerEmail"
                                            type="email"
                                            id="joinEmail"
                                            class="w-full px-4 py-2.5 rounded-xl bg-zinc-800 border border-zinc-700 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-red-500/50 focus:border-red-500/50"
                                            placeholder="you@example.com"
                                        >
                                        @error('viewerEmail') <span class="text-red-400 text-xs mt-1 block">{{ $message }}</span> @enderror
                                    </div>
                                @endif

                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    class="w-full py-3 rounded-xl bg-red-600 text-white font-semibold hover:bg-red-700 transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
                                >
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    <span wire:loading.remove>Join Stream</span>
                                    <span wire:loading>Connecting...</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @else
                {{-- ===== Full live experience ===== --}}
                <div class="flex flex-col lg:flex-row min-h-[calc(100vh-3.5rem)]">

                    {{-- Left: Video + Chat (desktop), Video + tabs (mobile) --}}
                    <div class="flex-1 flex flex-col min-w-0">

                        {{-- Video Player --}}
                        <div class="relative bg-black aspect-video lg:aspect-auto lg:flex-1" x-ref="videoContainer">
                            <div x-ref="videoPlayer" class="absolute inset-0 flex items-center justify-center">
                                <template x-if="!connected">
                                    <div class="text-center">
                                        <div class="w-12 h-12 border-4 border-red-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
                                        <p class="text-zinc-400 text-sm">Connecting to stream...</p>
                                    </div>
                                </template>
                            </div>

                            {{-- Pinned resources overlay --}}
                            @if ($this->pinnedResources->isNotEmpty())
                                <div class="absolute top-3 right-3 hidden lg:block">
                                    <div class="bg-black/70 backdrop-blur-sm rounded-lg p-2 max-w-[220px]">
                                        <p class="text-[10px] text-zinc-400 uppercase tracking-wider font-medium mb-1.5 px-1">Pinned</p>
                                        @foreach ($this->pinnedResources as $resource)
                                            <a
                                                href="{{ $resource->url }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="flex items-center gap-1.5 text-xs text-blue-300 hover:text-blue-200 px-1 py-0.5 rounded hover:bg-white/5 transition-colors"
                                            >
                                                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-2.44a4.5 4.5 0 00-6.364-6.364L6.5 5.5a4.5 4.5 0 006.364 6.364L14.5 10.5" /></svg>
                                                <span class="truncate">{{ $resource->title }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- Desktop: Chat below video --}}
                        <div class="hidden lg:flex flex-col h-64 border-t border-zinc-800 bg-zinc-900">
                            <div class="px-4 py-2 border-b border-zinc-800">
                                <h3 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Live Chat</h3>
                            </div>
                            <div class="flex-1 overflow-y-auto px-4 py-2 space-y-1.5" wire:poll.3s="$refresh" id="desktop-chat-scroll">
                                @foreach ($this->chatMessages as $msg)
                                    <div class="text-sm">
                                        <span class="text-zinc-300">{{ $msg->content }}</span>
                                    </div>
                                @endforeach
                                @if ($this->chatMessages->isEmpty())
                                    <p class="text-xs text-zinc-600 text-center py-4">No messages yet. Say hello!</p>
                                @endif
                            </div>
                            <form wire:submit="sendChatMessage" class="px-4 py-2 border-t border-zinc-800 flex gap-2">
                                <input
                                    wire:model="chatMessage"
                                    type="text"
                                    class="flex-1 px-3 py-2 rounded-lg bg-zinc-800 border border-zinc-700 text-white text-sm placeholder-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-600"
                                    placeholder="Type a message..."
                                    maxlength="500"
                                >
                                <button type="submit" class="px-3 py-2 rounded-lg bg-zinc-700 text-white text-sm font-medium hover:bg-zinc-600 transition-colors shrink-0">
                                    Send
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Right sidebar (desktop) --}}
                    <div class="hidden lg:flex lg:flex-col lg:w-80 xl:w-96 border-l border-zinc-800 bg-zinc-900">
                        {{-- Resources --}}
                        <div class="p-4 border-b border-zinc-800">
                            <h3 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m9.86-2.44a4.5 4.5 0 00-6.364-6.364L6.5 5.5a4.5 4.5 0 006.364 6.364L14.5 10.5" /></svg>
                                Resources
                            </h3>
                            @if ($this->allResources->isNotEmpty())
                                <div class="space-y-2">
                                    @foreach ($this->allResources as $resource)
                                        <a
                                            href="{{ $resource->url }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="flex items-center gap-2 text-sm text-blue-400 hover:text-blue-300 p-2 rounded-lg hover:bg-white/5 transition-colors"
                                        >
                                            @if ($resource->is_pinned)
                                                <svg class="w-3.5 h-3.5 text-accent-gold shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                                            @else
                                                <svg class="w-3.5 h-3.5 text-zinc-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                            @endif
                                            <div class="min-w-0">
                                                <p class="truncate font-medium">{{ $resource->title }}</p>
                                                @if ($resource->description)
                                                    <p class="text-xs text-zinc-500 truncate">{{ $resource->description }}</p>
                                                @endif
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs text-zinc-600">No resources shared yet.</p>
                            @endif
                        </div>

                        {{-- Ask a Question --}}
                        <div class="p-4 border-b border-zinc-800">
                            <h3 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>
                                Ask a Question
                            </h3>
                            <form wire:submit="submitQuestion" class="space-y-2">
                                <textarea
                                    wire:model="questionText"
                                    rows="2"
                                    class="w-full px-3 py-2 rounded-lg bg-zinc-800 border border-zinc-700 text-white text-sm placeholder-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-600 resize-none"
                                    placeholder="Type your question..."
                                    maxlength="1000"
                                ></textarea>
                                @error('questionText') <span class="text-red-400 text-xs block">{{ $message }}</span> @enderror
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    class="w-full py-2 rounded-lg bg-accent-gold text-brand-900 text-sm font-semibold hover:bg-accent-gold/90 transition-colors disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="submitQuestion">Submit Question</span>
                                    <span wire:loading wire:target="submitQuestion">Submitting...</span>
                                </button>
                            </form>

                            {{-- Flash for successful submission --}}
                            <div
                                x-data="{ show: false }"
                                x-on:question-submitted.window="show = true; setTimeout(() => show = false, 3000)"
                                x-show="show"
                                x-transition
                                class="mt-2 text-xs text-green-400 text-center"
                            >
                                Question submitted! The host will review it.
                            </div>
                        </div>

                        {{-- Answered Questions --}}
                        <div class="flex-1 overflow-y-auto p-4" wire:poll.5s="$refresh">
                            <h3 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-3">Answered Questions</h3>
                            @if ($this->answeredQuestions->isNotEmpty())
                                <div class="space-y-3">
                                    @foreach ($this->answeredQuestions as $q)
                                        <div class="bg-zinc-800/50 rounded-lg p-3">
                                            <div class="flex items-start gap-2">
                                                <svg class="w-4 h-4 text-green-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                <div>
                                                    <p class="text-sm text-zinc-200">{{ $q->question }}</p>
                                                    <p class="text-xs text-zinc-500 mt-1">Asked by {{ $q->viewer_name }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs text-zinc-600 text-center py-4">No questions answered yet.</p>
                            @endif
                        </div>
                    </div>

                    {{-- Mobile: Tabbed panel below video --}}
                    <div class="lg:hidden flex flex-col flex-1 bg-zinc-900">
                        {{-- Tab bar --}}
                        <div class="flex border-b border-zinc-800">
                            <button
                                wire:click="$set('activeTab', 'chat')"
                                class="flex-1 py-3 text-xs font-semibold text-center uppercase tracking-wider transition-colors {{ $activeTab === 'chat' ? 'text-white border-b-2 border-red-500' : 'text-zinc-500' }}"
                            >
                                Chat
                            </button>
                            <button
                                wire:click="$set('activeTab', 'resources')"
                                class="flex-1 py-3 text-xs font-semibold text-center uppercase tracking-wider transition-colors {{ $activeTab === 'resources' ? 'text-white border-b-2 border-red-500' : 'text-zinc-500' }}"
                            >
                                Resources
                            </button>
                            <button
                                wire:click="$set('activeTab', 'qa')"
                                class="flex-1 py-3 text-xs font-semibold text-center uppercase tracking-wider transition-colors {{ $activeTab === 'qa' ? 'text-white border-b-2 border-red-500' : 'text-zinc-500' }}"
                            >
                                Q&A
                            </button>
                        </div>

                        {{-- Tab content --}}
                        <div class="flex-1 overflow-y-auto">
                            {{-- Chat tab --}}
                            @if ($activeTab === 'chat')
                                <div class="flex flex-col h-full" wire:poll.3s="$refresh">
                                    <div class="flex-1 overflow-y-auto px-4 py-2 space-y-1.5" id="mobile-chat-scroll">
                                        @foreach ($this->chatMessages as $msg)
                                            <div class="text-sm">
                                                <span class="text-zinc-300">{{ $msg->content }}</span>
                                            </div>
                                        @endforeach
                                        @if ($this->chatMessages->isEmpty())
                                            <p class="text-xs text-zinc-600 text-center py-8">No messages yet. Say hello!</p>
                                        @endif
                                    </div>
                                    <form wire:submit="sendChatMessage" class="px-4 py-2 border-t border-zinc-800 flex gap-2">
                                        <input
                                            wire:model="chatMessage"
                                            type="text"
                                            class="flex-1 px-3 py-2 rounded-lg bg-zinc-800 border border-zinc-700 text-white text-sm placeholder-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-600"
                                            placeholder="Type a message..."
                                            maxlength="500"
                                        >
                                        <button type="submit" class="px-3 py-2 rounded-lg bg-zinc-700 text-white text-sm font-medium hover:bg-zinc-600 transition-colors shrink-0">
                                            Send
                                        </button>
                                    </form>
                                </div>
                            @endif

                            {{-- Resources tab --}}
                            @if ($activeTab === 'resources')
                                <div class="p-4 space-y-2">
                                    @if ($this->allResources->isNotEmpty())
                                        @foreach ($this->allResources as $resource)
                                            <a
                                                href="{{ $resource->url }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="flex items-center gap-2 text-sm text-blue-400 hover:text-blue-300 p-3 rounded-lg bg-zinc-800/50 hover:bg-zinc-800 transition-colors"
                                            >
                                                @if ($resource->is_pinned)
                                                    <svg class="w-4 h-4 text-accent-gold shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
                                                @else
                                                    <svg class="w-4 h-4 text-zinc-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                                @endif
                                                <div class="min-w-0">
                                                    <p class="truncate font-medium">{{ $resource->title }}</p>
                                                    @if ($resource->description)
                                                        <p class="text-xs text-zinc-500 truncate">{{ $resource->description }}</p>
                                                    @endif
                                                </div>
                                            </a>
                                        @endforeach
                                    @else
                                        <p class="text-xs text-zinc-600 text-center py-8">No resources shared yet.</p>
                                    @endif
                                </div>
                            @endif

                            {{-- Q&A tab --}}
                            @if ($activeTab === 'qa')
                                <div class="p-4 space-y-4" wire:poll.5s="$refresh">
                                    {{-- Submit question --}}
                                    <form wire:submit="submitQuestion" class="space-y-2">
                                        <textarea
                                            wire:model="questionText"
                                            rows="2"
                                            class="w-full px-3 py-2 rounded-lg bg-zinc-800 border border-zinc-700 text-white text-sm placeholder-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-600 resize-none"
                                            placeholder="Ask a question..."
                                            maxlength="1000"
                                        ></textarea>
                                        @error('questionText') <span class="text-red-400 text-xs block">{{ $message }}</span> @enderror
                                        <button
                                            type="submit"
                                            wire:loading.attr="disabled"
                                            class="w-full py-2 rounded-lg bg-accent-gold text-brand-900 text-sm font-semibold hover:bg-accent-gold/90 transition-colors disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="submitQuestion">Submit Question</span>
                                            <span wire:loading wire:target="submitQuestion">Submitting...</span>
                                        </button>
                                    </form>

                                    <div
                                        x-data="{ show: false }"
                                        x-on:question-submitted.window="show = true; setTimeout(() => show = false, 3000)"
                                        x-show="show"
                                        x-transition
                                        class="text-xs text-green-400 text-center"
                                    >
                                        Question submitted!
                                    </div>

                                    {{-- Answered list --}}
                                    @if ($this->answeredQuestions->isNotEmpty())
                                        <div class="border-t border-zinc-800 pt-3 space-y-3">
                                            <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Answered</h4>
                                            @foreach ($this->answeredQuestions as $q)
                                                <div class="bg-zinc-800/50 rounded-lg p-3">
                                                    <div class="flex items-start gap-2">
                                                        <svg class="w-4 h-4 text-green-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                        <div>
                                                            <p class="text-sm text-zinc-200">{{ $q->question }}</p>
                                                            <p class="text-xs text-zinc-500 mt-1">{{ $q->viewer_name }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Alpine.js LiveKit viewer --}}
        <script>
            function livekitViewer() {
                return {
                    room: null,
                    connected: false,

                    init() {
                        this.$wire.on('livekit-viewer-connect', ({ token, url }) => {
                            if (token && url) {
                                this.connect(token, url);
                            }
                        });
                    },

                    async connect(token, url) {
                        try {
                            const { Room, RoomEvent } = await import('livekit-client');
                            this.room = new Room();

                            this.room.on(RoomEvent.TrackSubscribed, (track, pub, participant) => {
                                const el = track.attach();
                                el.classList.add('w-full', 'h-full', 'object-cover');
                                if (this.$refs.videoPlayer) {
                                    // Clear the loading spinner
                                    const spinner = this.$refs.videoPlayer.querySelector('[x-if]');
                                    this.$refs.videoPlayer.appendChild(el);
                                }
                            });

                            this.room.on(RoomEvent.TrackUnsubscribed, (track) => {
                                track.detach().forEach(el => el.remove());
                            });

                            await this.room.connect(url, token);
                            this.connected = true;
                        } catch (error) {
                            console.error('LiveKit connection error:', error);
                        }
                    },

                    destroy() {
                        if (this.room) {
                            this.room.disconnect();
                        }
                    }
                };
            }
        </script>

    {{-- ==================== ENDED STATE ==================== --}}
    @elseif ($event->status === 'ended')
        <section class="relative bg-brand-900 overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700"></div>

            <div class="relative max-w-4xl mx-auto px-4 sm:px-6 pt-28 pb-16 sm:pt-32 sm:pb-20">
                <a href="{{ route('live.index') }}" class="inline-flex items-center gap-1.5 text-sm text-white/60 hover:text-white/90 transition-colors mb-8">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                    All Events
                </a>

                <div class="text-center">
                    <div class="inline-flex items-center gap-2 bg-zinc-500/20 text-zinc-300 text-sm font-medium px-3 py-1 rounded-full mb-6">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Event Ended
                    </div>

                    <h1 class="font-heading text-3xl sm:text-5xl text-white mb-4">{{ $event->title }}</h1>

                    @if ($event->description)
                        <p class="text-lg text-zinc-300 max-w-2xl mx-auto mb-6">{{ $event->description }}</p>
                    @endif

                    {{-- Event meta --}}
                    <div class="flex flex-wrap items-center justify-center gap-4 sm:gap-6 mb-8">
                        @if ($event->started_at && $event->ended_at)
                            <div class="flex items-center gap-2 text-sm text-zinc-400">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                                {{ $event->started_at->format('M j, Y') }}
                            </div>
                        @endif
                        @if ($event->host)
                            <div class="flex items-center gap-2 text-sm text-zinc-400">
                                <div class="w-5 h-5 rounded-full bg-zinc-600 flex items-center justify-center text-[10px] text-zinc-300 font-medium">
                                    {{ strtoupper(substr($event->host->name, 0, 1)) }}
                                </div>
                                Hosted by {{ $event->host->name }}
                            </div>
                        @endif
                    </div>

                    {{-- Tags --}}
                    <div class="flex flex-wrap items-center justify-center gap-2 mb-10">
                        @if ($event->serviceArea)
                            <span class="inline-flex items-center gap-1 text-xs font-medium bg-white/10 text-white/70 px-3 py-1 rounded-full">{{ $event->serviceArea->city }}</span>
                        @endif
                        @if ($event->service)
                            <span class="inline-flex items-center text-xs font-medium bg-white/10 text-white/70 px-3 py-1 rounded-full">{{ $event->service->name }}</span>
                        @endif
                    </div>

                    {{-- Recording --}}
                    <div class="max-w-md mx-auto">
                        @if ($event->recording_path)
                            <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 border border-white/10">
                                <svg class="w-10 h-10 text-accent-gold mx-auto mb-3" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                <h3 class="text-lg font-semibold text-white mb-2">Watch the Replay</h3>
                                <p class="text-sm text-zinc-400 mb-4">The full recording is available to watch.</p>
                                <a href="{{ $event->recording_path }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-accent-gold text-brand-900 font-semibold hover:bg-accent-gold/90 transition-colors">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    Watch Now
                                </a>
                            </div>
                        @else
                            <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-6 border border-white/10">
                                <svg class="w-10 h-10 text-zinc-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                                <h3 class="text-lg font-semibold text-white mb-2">Recording Coming Soon</h3>
                                <p class="text-sm text-zinc-400">The replay will be available shortly. Check back later!</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        {{-- Related upcoming events --}}
        @if ($this->relatedEvents->isNotEmpty())
            <section class="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16">
                <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-8">Upcoming Events</h2>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->relatedEvents as $related)
                        <a href="{{ route('live.show', $related->slug) }}" class="group block">
                            <div class="rounded-2xl overflow-hidden shadow-sm hover:shadow-lg border border-zinc-200 hover:border-accent-gold/30 transition-all duration-300 hover:-translate-y-0.5 bg-white">
                                <div class="aspect-video bg-gradient-to-br from-brand-700 via-brand-800 to-brand-900 relative">
                                    @if ($related->cover_image)
                                        <img src="{{ $related->cover_image }}" alt="{{ $related->title }}" class="absolute inset-0 w-full h-full object-cover" loading="lazy">
                                    @endif
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
                                </div>
                                <div class="p-4 space-y-2">
                                    <h3 class="font-heading text-lg text-zinc-900 group-hover:text-brand-700 transition-colors">{{ $related->title }}</h3>
                                    <div class="flex items-center gap-2 text-sm text-zinc-500">
                                        <svg class="w-4 h-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                                        {{ $related->scheduled_at->format('M j, Y') }} at {{ $related->scheduled_at->format('g:i A') }}
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @if ($related->serviceArea)
                                            <span class="inline-flex items-center text-xs font-medium bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full">{{ $related->serviceArea->city }}</span>
                                        @endif
                                        @if ($related->service)
                                            <span class="inline-flex items-center text-xs font-medium bg-green-50 text-green-700 px-2 py-0.5 rounded-full">{{ $related->service->name }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

    {{-- ==================== CANCELLED / OTHER ==================== --}}
    @else
        <section class="max-w-4xl mx-auto px-4 sm:px-6 pt-28 pb-16 text-center">
            <svg class="w-16 h-16 text-zinc-300 mx-auto mb-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
            <h1 class="font-heading text-3xl text-zinc-900 mb-2">Event Unavailable</h1>
            <p class="text-zinc-500 mb-6">This event has been cancelled or is no longer available.</p>
            <a href="{{ route('live.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-cta text-white text-sm font-semibold hover:bg-cta-hover transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                Browse All Events
            </a>
        </section>
    @endif
</div>
