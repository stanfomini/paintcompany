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
    public string $slug = '';
    public ?Service $service = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->service = Service::where('slug', $slug)->where('is_active', true)->firstOrFail();
    }

    public function getTitle(): string
    {
        return $this->service->title . ' Denver CO | Painting Made Easy Denver';
    }

    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function serviceAreas(): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceArea::active()->orderBy('city')->get();
    }

    #[Computed]
    public function relatedServices(): \Illuminate\Database\Eloquent\Collection
    {
        $related = Service::active()
            ->where('id', '!=', $this->service->id)
            ->where('category', $this->service->category)
            ->orderBy('sort_order')
            ->limit(4)
            ->get();

        if ($related->count() < 4) {
            $exclude = $related->pluck('id')->push($this->service->id);
            $more = Service::active()
                ->whereNotIn('id', $exclude)
                ->orderBy('sort_order')
                ->limit(4 - $related->count())
                ->get();
            $related = $related->concat($more);
        }

        return $related;
    }

    #[Computed]
    public function upcomingEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return LiveEvent::upcoming()
            ->where('service_id', $this->service->id)
            ->with('serviceArea')
            ->take(3)
            ->get();
    }

    #[Computed]
    public function structuredData(): string
    {
        $company = $this->company;
        $companyName = $company->company_name ?? 'Painting Made Easy Denver';

        $serviceSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $this->service->title,
            'description' => $this->service->meta_description,
            'provider' => [
                '@type' => 'HousePainter',
                'name' => $companyName,
                'telephone' => $company->company_phone ?? '(720) 999-9725',
                'areaServed' => $this->serviceAreas->map(fn ($a) => [
                    '@type' => 'City',
                    'name' => $a->city,
                    'containedInPlace' => ['@type' => 'State', 'name' => 'Colorado'],
                ])->values()->all(),
            ],
            'serviceType' => $this->service->title,
            'url' => route('services.show', $this->slug),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $howToSteps = [];
        foreach ($this->service->steps ?? [] as $i => $step) {
            $howToSteps[] = [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'name' => $step['title'],
                'text' => $step['description'],
            ];
        }
        $howToSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => 'How to ' . $this->service->title . ' in Denver',
            'description' => $this->service->tagline,
            'step' => $howToSteps,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $faqEntities = [];
        foreach ($this->service->faqs ?? [] as $faq) {
            $faqEntities[] = [
                '@type' => 'Question',
                'name' => $faq['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['a'],
                ],
            ];
        }
        $faqSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faqEntities,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $breadcrumb = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => url('/services')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $this->service->title],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return '['."\n".$serviceSchema.','."\n".$howToSchema.','."\n".$faqSchema.','."\n".$breadcrumb."\n".']';
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_title', $service->title . ' Denver CO | Painting Made Easy Denver')
    @section('meta_description', $service->meta_description)
    @section('canonical', route('services.show', $slug))
    @section('robots', 'index, follow')

    {{-- Hero --}}
    <section class="bg-brand-900 text-white pt-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-16 sm:pt-24 pb-12 sm:pb-16">
            @include('partials.public-breadcrumb', ['items' => [
                ['label' => __('Services'), 'url' => route('services.index')],
                ['label' => $service->title],
            ]])
            <span class="inline-block bg-white/10 text-zinc-300 text-xs font-semibold px-3 py-1 rounded-full mb-4 backdrop-blur-sm uppercase tracking-wider">{{ $service->category }}</span>
            <h1 class="font-heading text-4xl sm:text-5xl tracking-tight mb-4">{{ $service->title }} in Denver &amp; the Front Range</h1>
            <p class="text-xl text-zinc-300 max-w-3xl">{{ $service->tagline }}</p>
        </div>
    </section>

    {{-- Trust Strip --}}
    @include('partials.public-trust-strip')

    {{-- Overview --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-8">Overview</h2>
            @foreach(explode("\n\n", $service->overview ?? '') as $paragraph)
                @if(trim($paragraph))
                    <p class="text-lg text-zinc-600 leading-relaxed mb-6 last:mb-0">{{ $paragraph }}</p>
                @endif
            @endforeach
        </div>
    </section>

    {{-- Materials & Tools --}}
    @if(!empty($service->materials))
    <section class="bg-warm-50 py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-8">Materials &amp; Tools Needed</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                @foreach($service->materials as $group => $items)
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <h3 class="font-bold text-zinc-900 mb-4 text-lg">{{ $group }}</h3>
                        <ul class="space-y-2.5">
                            @foreach($items as $item)
                                <li class="flex items-start gap-2.5 text-sm text-zinc-600">
                                    <svg class="w-5 h-5 text-cta shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Step-by-Step Guide --}}
    @if(!empty($service->steps))
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-10">Step-by-Step Guide</h2>
            <div class="space-y-6">
                @foreach($service->steps as $index => $step)
                    <div class="flex items-start gap-5" wire:key="step-{{ $index }}">
                        <div class="shrink-0 w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center">
                            <span class="text-lg font-extrabold text-brand-700">{{ $index + 1 }}</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-zinc-900 text-lg mb-2">{{ $step['title'] }}</h3>
                            <p class="text-zinc-600 leading-relaxed">{{ $step['description'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Pro Tips --}}
    @if(!empty($service->tips))
    <section class="bg-warm-50 py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-8">Denver Pro Tips</h2>
            <div class="space-y-6">
                @foreach($service->tips as $index => $tip)
                    <div class="bg-white rounded-xl p-6 shadow-sm" wire:key="tip-{{ $index }}">
                        <div class="flex items-start gap-4">
                            <div class="shrink-0 w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                <svg class="w-5 h-5 text-accent-gold" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-zinc-900 mb-2">{{ $tip['title'] }}</h3>
                                <p class="text-zinc-600 leading-relaxed">{{ $tip['description'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Cost Factors --}}
    @if(!empty($service->cost_factors))
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-8">What Affects Pricing</h2>
            <div class="bg-warm-50 rounded-xl p-6 sm:p-8">
                <ul class="space-y-4">
                    @foreach($service->cost_factors as $index => $factor)
                        <li class="flex items-start gap-3" wire:key="cost-{{ $index }}">
                            <svg class="w-5 h-5 text-accent-copper shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span class="text-zinc-700">{{ $factor }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>
    @endif

    {{-- FAQ --}}
    @if(!empty($service->faqs))
    <section class="bg-warm-50 py-16 sm:py-20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-8">Frequently Asked Questions</h2>
            <div class="space-y-4">
                @foreach($service->faqs as $index => $faq)
                    <details class="group bg-white rounded-xl shadow-sm" wire:key="faq-{{ $index }}">
                        <summary class="flex items-center justify-between cursor-pointer p-6 font-semibold text-zinc-900 text-lg hover:text-brand-700 transition-colors list-none">
                            <span>{{ $faq['q'] }}</span>
                            <svg class="w-5 h-5 shrink-0 ml-4 text-zinc-400 transition-transform duration-200 group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                        </summary>
                        <div class="px-6 pb-6 text-zinc-600 leading-relaxed">{{ $faq['a'] }}</div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Related Services --}}
    @if($this->relatedServices->isNotEmpty())
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-8 text-center">Related Services</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($this->relatedServices as $related)
                    <a href="{{ route('services.show', $related->slug) }}" wire:key="related-{{ $related->slug }}" class="bg-warm-50 rounded-xl p-5 hover:bg-warm-100 transition-colors group">
                        <span class="text-xs font-semibold text-brand-700 uppercase tracking-wider">{{ $related->category }}</span>
                        <h3 class="font-bold text-zinc-900 mt-1.5 mb-2 group-hover:text-brand-700 transition-colors">{{ $related->title }}</h3>
                        <p class="text-sm text-zinc-500 line-clamp-2">{{ $related->tagline }}</p>
                    </a>
                @endforeach
            </div>
            <div class="text-center mt-8">
                <a href="{{ route('services.index') }}" class="text-brand-700 hover:text-brand-800 font-medium text-sm transition-colors">
                    {{ __('View All Services') }} &rarr;
                </a>
            </div>
        </div>
    </section>
    @endif

    {{-- Area Cross-Links --}}
    @if($this->serviceAreas->isNotEmpty())
    <section class="bg-warm-50 py-16 sm:py-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-10">
                <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-3">Get {{ $service->title }} in Your City</h2>
                <p class="text-zinc-600 max-w-2xl mx-auto">We provide professional {{ strtolower($service->title) }} services across the Denver metro area and the Front Range.</p>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                @foreach($this->serviceAreas as $area)
                    <a href="{{ route('areas.service', [$area->slug, $slug]) }}" wire:key="area-{{ $area->slug }}" class="bg-white rounded-lg px-4 py-3 text-sm text-zinc-700 hover:text-brand-700 hover:bg-brand-50 transition-colors text-center shadow-sm">
                        {{ $service->title }} in {{ $area->city }}
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
        'heading' => __('Ready for Professional :service?', ['service' => $service->title]),
        'description' => __('Get a free estimate and see why Denver homeowners trust us with their painting projects.'),
    ])
</div>
