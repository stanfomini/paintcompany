<?php

use App\Models\CompanySetting;
use App\Models\LiveEvent;
use App\Models\Service;
use App\Models\ServiceArea;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component
{
    public ServiceArea $serviceArea;

    public Service $service;

    public function mount(string $areaSlug, string $serviceSlug): void
    {
        $this->serviceArea = ServiceArea::where('slug', $areaSlug)->where('is_active', true)->firstOrFail();
        $this->service = Service::where('slug', $serviceSlug)->where('is_active', true)->firstOrFail();
    }

    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function pivot(): ?object
    {
        $record = $this->serviceArea->services()->where('service_id', $this->service->id)->first();

        return $record?->pivot;
    }

    #[Computed]
    public function h1(): string
    {
        return $this->pivot?->custom_h1 ?? "{$this->service->title} in {$this->serviceArea->city}, Colorado";
    }

    #[Computed]
    public function metaTitle(): string
    {
        return "{$this->service->title} {$this->serviceArea->city} CO | Painting Made Easy Denver";
    }

    #[Computed]
    public function localContent(): array
    {
        return $this->serviceArea->local_content ?? [];
    }

    #[Computed]
    public function otherServicesInCity(): \Illuminate\Support\Collection
    {
        return $this->serviceArea->services()
            ->where('service_id', '!=', $this->service->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function thisServiceInOtherCities(): \Illuminate\Support\Collection
    {
        return $this->service->serviceAreas()
            ->where('service_area_id', '!=', $this->serviceArea->id)
            ->where('is_active', true)
            ->orderBy('city')
            ->limit(12)
            ->get();
    }

    #[Computed]
    public function allAreas(): \Illuminate\Support\Collection
    {
        return ServiceArea::active()->orderBy('city')->get();
    }

    #[Computed]
    public function upcomingEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return LiveEvent::upcoming()
            ->where(function ($q) {
                $q->where('service_area_id', $this->serviceArea->id)
                    ->orWhere('service_id', $this->service->id);
            })
            ->with('serviceArea', 'service')
            ->take(3)
            ->get();
    }

    #[Computed]
    public function structuredData(): string
    {
        $company = $this->company;
        $companyName = $company->company_name ?? 'Painting Made Easy Denver';
        $neighborhoods = $this->localContent['neighborhoods'] ?? [];

        $schemas = [];

        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $this->h1,
            'description' => $this->service->meta_description,
            'provider' => [
                '@type' => 'HousePainter',
                'name' => $companyName,
                'telephone' => $company->company_phone,
                'url' => url('/'),
            ],
            'areaServed' => array_filter([
                '@type' => 'City',
                'name' => $this->serviceArea->city,
                'containedInPlace' => [
                    '@type' => 'State',
                    'name' => 'Colorado',
                ],
            ]),
            'url' => route('areas.service', [$this->serviceArea->slug, $this->service->slug]),
        ];

        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Service Areas', 'item' => route('areas.index')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $this->serviceArea->city, 'item' => route('areas.show', $this->serviceArea->slug)],
                ['@type' => 'ListItem', 'position' => 4, 'name' => $this->service->title],
            ],
        ];

        if (! empty($this->service->faqs)) {
            $faqItems = [];
            foreach (array_slice($this->service->faqs, 0, 5) as $faq) {
                $faqItems[] = [
                    '@type' => 'Question',
                    'name' => $faq['q'] ?? '',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['a'] ?? '',
                    ],
                ];
            }
            if (! empty($faqItems)) {
                $schemas[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => $faqItems,
                ];
            }
        }

        return json_encode($schemas, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_description', $this->service->meta_description)
    @section('meta_title', $this->metaTitle)
    @section('canonical', route('areas.service', [$this->serviceArea->slug, $this->service->slug]))
    @section('robots', 'index, follow')

    {{-- Breadcrumb --}}
    <div class="bg-brand-900 pt-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-4">
            @include('partials.public-breadcrumb', ['items' => [
                ['label' => 'Service Areas', 'url' => route('areas.index')],
                ['label' => $this->serviceArea->city, 'url' => route('areas.show', $this->serviceArea->slug)],
                ['label' => $this->service->title],
            ]])
        </div>
    </div>

    {{-- Hero --}}
    <section class="bg-brand-900 text-white">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-8 sm:pt-16 pb-12 sm:pb-16 text-center">
            <h1 class="font-heading text-3xl sm:text-4xl lg:text-5xl tracking-tight mb-4">
                <span class="text-accent-gold">{{ $this->service->title }}</span> in {{ $this->serviceArea->city }}, Colorado
            </h1>
            <p class="text-lg sm:text-xl text-zinc-300 max-w-3xl mx-auto">{{ $this->service->tagline }}</p>

            @if(!empty($this->localContent['neighborhoods']))
                <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm text-zinc-400">
                    @foreach(array_slice($this->localContent['neighborhoods'], 0, 6) as $neighborhood)
                        <span>{{ $neighborhood }}</span>
                        @if(!$loop->last)<span class="text-zinc-600">|</span>@endif
                    @endforeach
                </div>
            @endif

            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="{{ route('booking.general') }}" class="inline-flex items-center gap-2 bg-cta hover:bg-cta-hover text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                    Get Your Free Estimate
                </a>
                @if($this->company->company_phone)
                    <a href="tel:{{ $this->company->company_phone }}" class="inline-flex items-center gap-2 text-accent-gold hover:text-white text-lg font-semibold transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                        {{ $this->company->company_phone }}
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- Trust Strip --}}
    @include('partials.public-trust-strip', ['variant' => 'light'])

    {{-- Local Context --}}
    @if(!empty($this->localContent['challenges']) || !empty($this->localContent['housing_types']))
    <section class="bg-warm-50 py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-6">Why {{ $this->serviceArea->city }} Homes Need Expert {{ $this->service->title }}</h2>

            @if($this->pivot?->custom_intro)
                <p class="text-lg text-zinc-600 leading-relaxed mb-6">{{ $this->pivot->custom_intro }}</p>
            @endif

            @if(!empty($this->localContent['housing_types']))
                <p class="text-lg text-zinc-600 leading-relaxed mb-4">
                    {{ $this->serviceArea->city }} homes include <strong>{{ implode(', ', array_slice($this->localContent['housing_types'], 0, 4)) }}</strong> — each requiring a tailored approach to {{ strtolower($this->service->title) }}.
                </p>
            @endif

            @if(!empty($this->localContent['challenges']))
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach($this->localContent['challenges'] as $challenge)
                        <div class="flex items-start gap-3 bg-white rounded-lg p-4 shadow-sm">
                            <svg class="w-5 h-5 text-accent-copper shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                            <span class="text-sm text-zinc-700">{{ $challenge }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if(!empty($this->localContent['seasonal_notes']))
                <p class="mt-6 text-sm text-zinc-500 italic">{{ $this->localContent['seasonal_notes'] }}</p>
            @endif
        </div>
    </section>
    @endif

    {{-- Service Overview --}}
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-6">{{ $this->service->title }} Overview</h2>
            <div class="prose prose-zinc max-w-none text-lg leading-relaxed">
                {!! nl2br(e($this->service->overview)) !!}
            </div>
            <p class="mt-4 text-sm text-zinc-500">
                Read our complete <a href="{{ route('services.show', $this->service->slug) }}" class="text-brand-700 hover:text-brand-900 font-medium underline underline-offset-2">{{ $this->service->title }} guide</a> for more details on materials, costs, and tips.
            </p>

            {{-- Steps --}}
            @if(!empty($this->service->steps))
                <h3 class="font-heading text-xl sm:text-2xl text-zinc-900 mt-10 mb-6">Our Process</h3>
                <div class="space-y-4">
                    @foreach($this->service->steps as $index => $step)
                        <div class="flex items-start gap-4">
                            <div class="shrink-0 w-10 h-10 rounded-full bg-brand-700 text-white flex items-center justify-center font-bold text-sm">{{ $index + 1 }}</div>
                            <div>
                                <h4 class="font-semibold text-zinc-900">{{ $step['title'] }}</h4>
                                <p class="text-zinc-600 mt-1">{{ $step['description'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- Tips --}}
    @if(!empty($this->service->tips))
    <section class="bg-warm-50 py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-6">{{ $this->service->title }} Tips for {{ $this->serviceArea->city }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach($this->service->tips as $tip)
                    <div class="bg-white rounded-xl p-5 shadow-sm">
                        <h3 class="font-semibold text-zinc-900 mb-2">{{ $tip['title'] }}</h3>
                        <p class="text-sm text-zinc-600">{{ $tip['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Google Map --}}
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-6">Our Service Area</h2>
            @include('partials.google-map', [
                'areas' => $this->allAreas,
                'center' => [$this->serviceArea->latitude ?? 39.75, $this->serviceArea->longitude ?? -104.99],
                'zoom' => 10,
                'highlight' => $this->serviceArea->slug,
                'lazy' => true,
            ])
        </div>
    </section>

    {{-- FAQ --}}
    @if(!empty($this->service->faqs))
    <section class="bg-warm-50 py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-6">{{ $this->service->title }} FAQ</h2>
            <div class="space-y-4">
                @foreach($this->service->faqs as $faq)
                    <details class="group bg-white rounded-xl">
                        <summary class="flex items-center justify-between cursor-pointer p-5 font-semibold text-zinc-900 hover:text-brand-700 transition-colors list-none">
                            <span>{{ $faq['q'] }}</span>
                            <svg class="w-5 h-5 shrink-0 ml-4 text-zinc-400 transition-transform duration-200 group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </summary>
                        <div class="px-5 pb-5 text-zinc-600 leading-relaxed">
                            {{ $faq['a'] }}
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Cross-links: Other services in this city --}}
    @if($this->otherServicesInCity->isNotEmpty())
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-6">More Services in {{ $this->serviceArea->city }}</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach($this->otherServicesInCity as $otherService)
                    <a href="{{ route('areas.service', [$this->serviceArea->slug, $otherService->slug]) }}"
                       class="block bg-warm-50 hover:bg-warm-100 rounded-xl p-4 text-center transition-colors group">
                        <p class="font-semibold text-zinc-900 group-hover:text-brand-700 text-sm">{{ $otherService->title }}</p>
                        <p class="text-xs text-zinc-500 mt-1">{{ $this->serviceArea->city }}, CO</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Cross-links: This service in other cities --}}
    @if($this->thisServiceInOtherCities->isNotEmpty())
    <section class="bg-warm-50 py-12 sm:py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-6">{{ $this->service->title }} in Other Cities</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                @foreach($this->thisServiceInOtherCities as $otherArea)
                    <a href="{{ route('areas.service', [$otherArea->slug, $this->service->slug]) }}"
                       class="block bg-white hover:bg-brand-50 rounded-lg p-3 transition-colors group">
                        <p class="font-medium text-zinc-900 group-hover:text-brand-700 text-sm">{{ $otherArea->city }}, CO</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Upcoming Live Events --}}
    @if($this->upcomingEvents->isNotEmpty())
    <section class="py-12 bg-zinc-50">
        <div class="max-w-7xl mx-auto px-4">
            <h2 class="text-2xl font-bold text-zinc-900 mb-6">Upcoming Live Events</h2>
            <div class="grid gap-6 md:grid-cols-3">
                @foreach($this->upcomingEvents as $event)
                <a href="{{ route('live.show', $event) }}" class="bg-white rounded-lg shadow-sm hover:shadow-md transition p-6">
                    <div class="text-sm text-blue-600 font-medium">{{ $event->scheduled_at->format('M j, Y \a\t g:i A') }}</div>
                    <h3 class="text-lg font-semibold text-zinc-900 mt-1">{{ $event->title }}</h3>
                    <p class="text-sm text-zinc-500 mt-2 line-clamp-2">{{ $event->description }}</p>
                </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- CTA --}}
    @include('partials.public-cta-section', [
        'heading' => "Get {$this->service->title} in {$this->serviceArea->city}",
        'description' => "Free estimate for your {$this->serviceArea->city} home. Same-day response, transparent pricing.",
    ])
</div>
