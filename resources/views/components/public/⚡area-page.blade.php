<?php

use App\Models\CompanySetting;
use App\Models\Keyword;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Services\Seo\KeywordResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest', ['headPartial' => 'partials.head-landing'])] class extends Component
{
    public ServiceArea $area;

    public function mount(string $slug): void
    {
        $this->area = ServiceArea::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function teamMembers(): array
    {
        if (! $this->area->territory) {
            return ['manager' => null, 'estimators' => collect(), 'knockers' => collect()];
        }

        $territory = $this->area->territory;

        return [
            'manager' => $territory->manager,
            'estimators' => $territory->estimators()->get(),
            'knockers' => $territory->knockers()->get(),
        ];
    }

    #[Computed]
    public function services(): Collection
    {
        $pivotServices = $this->area->services()->active()->orderBy('service_area_service.sort_order')->get();

        if ($pivotServices->isNotEmpty()) {
            return $pivotServices;
        }

        return Service::active()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function neighborhoods(): array
    {
        return $this->area->local_content['neighborhoods'] ?? [];
    }

    #[Computed]
    public function localContent(): array
    {
        return $this->area->local_content ?? [];
    }

    /**
     * @return array{primary: ?Keyword, secondary: \Illuminate\Support\Collection, supporting: \Illuminate\Support\Collection}
     */
    #[Computed]
    public function keywordBundle(): array
    {
        return app(KeywordResolver::class)->forAreaOnly($this->area);
    }

    #[Computed]
    public function primaryKeyword(): ?Keyword
    {
        return $this->keywordBundle['primary'];
    }

    #[Computed]
    public function metaTitle(): string
    {
        $companyName = $this->company->company_name ?: 'Painting Made Easy Denver';
        $fallback = $this->area->meta_title;

        if (! $kw = $this->primaryKeyword) {
            return $fallback;
        }

        $titled = Str::title($kw->keyword);
        $withCompany = "{$titled} | {$companyName}";

        return Str::length($withCompany) <= 60 ? $withCompany : Str::limit($titled, 60, '');
    }

    #[Computed]
    public function metaDescription(): string
    {
        $base = $this->area->meta_description ?? '';

        if (! $kw = $this->primaryKeyword) {
            return $base;
        }

        if (Str::contains(Str::lower($base), Str::lower($kw->keyword))) {
            return $base;
        }

        return Str::limit("Looking for {$kw->keyword}? {$base}", 160, '...');
    }

    #[Computed]
    public function keywordSentence(): ?string
    {
        if (! $kw = $this->primaryKeyword) {
            return null;
        }

        return $kw->customSentenceFor(null, $this->area)
            ?? "When homeowners search for {$kw->keyword} in {$this->area->city}, they want a team that knows the neighborhood.";
    }

    #[Computed]
    public function structuredData(): string
    {
        $neighborhoods = $this->neighborhoods;

        $areaServed = [
            '@type' => 'City',
            'name' => $this->area->city,
            'containedInPlace' => [
                '@type' => 'State',
                'name' => 'Colorado',
            ],
        ];

        if (! empty($neighborhoods)) {
            $areaServed['containsPlace'] = array_map(fn ($n) => [
                '@type' => 'Neighborhood',
                'name' => $n,
            ], $neighborhoods);
        }

        $schema = json_encode(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'HousePainter',
            'name' => ($this->company->company_name ?? 'Painting Made Easy Denver').' - '.$this->area->city,
            'description' => $this->area->meta_description,
            '@id' => route('areas.show', $this->area->slug),
            'url' => route('areas.show', $this->area->slug),
            'telephone' => $this->company->company_phone,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $this->area->city,
                'addressRegion' => $this->area->state,
                'addressCountry' => 'US',
            ],
            'geo' => $this->area->latitude ? [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $this->area->latitude,
                'longitude' => (float) $this->area->longitude,
            ] : null,
            'areaServed' => $areaServed,
            'priceRange' => '$$',
            'openingHoursSpecification' => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'opens' => $this->company->business_hours_start ?? '08:00',
                'closes' => $this->company->business_hours_end ?? '17:00',
            ],
        ]), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $breadcrumb = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Service Areas', 'item' => route('areas.index')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $this->area->city],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return '['."\n".$schema.','."\n".$breadcrumb."\n".']';
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_title', $this->metaTitle)
    @section('meta_description', $this->metaDescription)
    @section('canonical', route('areas.show', $area->slug))
    @section('og_title', $area->meta_title)
    @section('og_description', $area->meta_description)
    @section('og_url', route('areas.show', $area->slug))
    @section('og_type', 'website')
    @section('robots', 'index, follow')

    {{-- Breadcrumb --}}
    <div class="bg-brand-900 pt-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-4">
            @include('partials.public-breadcrumb', ['items' => [
                ['label' => __('Service Areas'), 'url' => route('areas.index')],
                ['label' => $area->city],
            ]])
        </div>
    </div>

    {{-- Hero --}}
    <section class="bg-brand-900 text-white">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-12 sm:pt-20 pb-12 sm:pb-16 text-center">
            <h1 class="font-heading text-4xl sm:text-5xl lg:text-6xl text-white mb-6 leading-tight">
                {{ __('House Painting in :city, Colorado', ['city' => $area->city]) }}
            </h1>

            @if(! empty($this->neighborhoods))
                <div class="flex flex-wrap items-center justify-center gap-2 mb-8">
                    @foreach(array_slice($this->neighborhoods, 0, 8) as $neighborhood)
                        <span class="text-xs sm:text-sm px-3 py-1 rounded-full bg-white/10 text-zinc-300">{{ $neighborhood }}</span>
                    @endforeach
                    @if(count($this->neighborhoods) > 8)
                        <span class="text-xs sm:text-sm px-3 py-1 rounded-full bg-white/10 text-accent-gold">+{{ count($this->neighborhoods) - 8 }} {{ __('more') }}</span>
                    @endif
                </div>
            @endif

            <p class="text-lg sm:text-xl text-zinc-300 mb-8 max-w-3xl mx-auto leading-relaxed">
                {{ $area->hero_text ?? __(':city homeowners get a stress-free painting experience with real-time project tracking, daily photo updates, and a satisfaction guarantee.', ['city' => $area->city]) }}
            </p>
            <a href="{{ route('booking.general') }}" class="inline-flex items-center gap-3 bg-cta hover:bg-cta-hover text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                {{ __('Get Your Free Estimate') }}
            </a>
        </div>
    </section>

    {{-- Trust Strip --}}
    @include('partials.public-trust-strip', ['variant' => 'light'])

    {{-- Why City Homes Need Expert Painters --}}
    @if(! empty($this->localContent['challenges']) || ! empty($this->localContent['housing_types']) || ! empty($this->localContent['why_local']))
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-4">{{ __('Why :city Homes Need Expert Painters', ['city' => $area->city]) }}</h2>
                @if($this->keywordSentence)
                    <p class="text-lg text-zinc-600 max-w-2xl mx-auto mb-3">{{ $this->keywordSentence }}</p>
                @endif
                @if(! empty($this->localContent['why_local']))
                    <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ $this->localContent['why_local'] }}</p>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                @if(! empty($this->localContent['challenges']))
                <div class="bg-warm-50 rounded-xl p-6 sm:p-8">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-accent-gold/10 text-accent-gold">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                        </div>
                        <h3 class="font-heading text-xl text-zinc-900">{{ __('Local Painting Challenges') }}</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach($this->localContent['challenges'] as $challenge)
                            <li class="flex items-start gap-3 text-zinc-700">
                                <svg class="w-5 h-5 text-accent-gold shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                {{ $challenge }}
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                @if(! empty($this->localContent['housing_types']))
                <div class="bg-warm-50 rounded-xl p-6 sm:p-8">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-cta/10 text-cta">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        <h3 class="font-heading text-xl text-zinc-900">{{ __('Common Home Styles') }}</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach($this->localContent['housing_types'] as $type)
                            <li class="flex items-start gap-3 text-zinc-700">
                                <svg class="w-5 h-5 text-cta shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                                {{ $type }}
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>
        </div>
    </section>
    @endif

    {{-- Services Grid --}}
    <section class="bg-warm-50 py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-4">{{ __('Painting Services in :city', ['city' => $area->city]) }}</h2>
                @if($area->specialties_text)
                    <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ $area->specialties_text }}</p>
                @endif
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($this->services as $service)
                    <a href="{{ route('areas.service', [$area->slug, $service->slug]) }}"
                       wire:key="service-{{ $service->id }}"
                       class="group bg-white rounded-xl p-6 shadow-sm hover:shadow-md transition-shadow border border-zinc-100">
                        <div class="flex items-start gap-4">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-brand-50 text-brand-700 shrink-0">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-zinc-900 group-hover:text-cta transition-colors mb-1">{{ $service->title }}</h3>
                                <p class="text-sm text-zinc-600">{{ $service->tagline }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Why Choose Us --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-4">{{ __('Why :city Homeowners Choose Us', ['city' => $area->city]) }}</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-cta/10 text-cta mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-2">{{ __('Satisfaction Guaranteed') }}</h3>
                    <p class="text-sm text-zinc-600 leading-relaxed">{{ __('If you\'re not thrilled with the result, we\'ll make it right. Period.') }}</p>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-brand-50 text-brand-700 mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-2">{{ __('Real-Time Tracking') }}</h3>
                    <p class="text-sm text-zinc-600 leading-relaxed">{{ __('Track your project from estimate to completion with daily photo updates.') }}</p>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-accent-gold/10 text-accent-gold mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                    </div>
                    <h3 class="font-semibold text-zinc-900 mb-2">{{ __('Cleaner Than We Found It') }}</h3>
                    <p class="text-sm text-zinc-600 leading-relaxed">{{ __('Your home is protected and cleaned daily. We guarantee it.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Local Team (if territory linked) --}}
    @if($this->teamMembers['manager'] || $this->teamMembers['estimators']->isNotEmpty())
    <section class="bg-warm-50 py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-4">{{ __('Meet Your :city Team', ['city' => $area->city]) }}</h2>
                <p class="text-lg text-zinc-600">{{ __('Our local team knows :city inside and out.', ['city' => $area->city]) }}</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @if($this->teamMembers['manager'])
                    <div class="bg-white rounded-xl p-6 shadow-sm text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-brand-50 text-brand-700 mb-4">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        </div>
                        <h3 class="font-semibold text-zinc-900">{{ $this->teamMembers['manager']->name }}</h3>
                        <p class="text-sm text-accent-gold font-medium">{{ __('Area Director') }}</p>
                    </div>
                @endif
                @foreach($this->teamMembers['estimators'] as $estimator)
                    <div class="bg-white rounded-xl p-6 shadow-sm text-center" wire:key="estimator-{{ $estimator->id }}">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-cta/10 text-cta mb-4">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        </div>
                        <h3 class="font-semibold text-zinc-900">{{ $estimator->name }}</h3>
                        <p class="text-sm text-cta font-medium">{{ __('Estimator') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Service Area Map --}}
    @if($area->latitude)
    <section class="bg-white py-12 sm:py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-6">
                <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-2">{{ __('Our Service Area Near :city', ['city' => $area->city]) }}</h2>
                <p class="text-zinc-600">{{ __('We also serve nearby communities across the Denver metro.') }}</p>
            </div>
            @include('partials.google-map', [
                'areas' => \App\Models\ServiceArea::active()->whereNotNull('latitude')->get(),
                'center' => [$area->latitude, $area->longitude],
                'zoom' => 11,
                'highlight' => $area->slug,
                'lazy' => true,
            ])
        </div>
    </section>
    @endif

    {{-- CTA --}}
    @include('partials.public-cta-section', [
        'heading' => __('Ready to Transform Your :city Home?', ['city' => $area->city]),
        'description' => __('Get a free, no-obligation estimate from our :city team. Most homeowners hear back within hours.', ['city' => $area->city]),
        'ctaText' => __('Get Your Free Estimate'),
        'ctaUrl' => route('booking.general'),
        'variant' => 'dark',
    ])

    {{-- Other Service Areas --}}
    <section class="bg-warm-50 py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <h3 class="font-heading text-xl text-zinc-900 mb-6 text-center">{{ __('Other Service Areas') }}</h3>
            <div class="flex flex-wrap justify-center gap-3">
                @foreach(ServiceArea::active()->where('id', '!=', $area->id)->orderBy('city')->get() as $otherArea)
                    <a href="{{ route('areas.show', $otherArea->slug) }}" wire:key="other-{{ $otherArea->id }}" class="text-sm px-3 py-1.5 bg-white rounded-full border border-zinc-200 text-zinc-700 hover:border-accent-gold hover:text-brand-700 transition-colors">
                        {{ $otherArea->city }}
                    </a>
                @endforeach
            </div>
        </div>
    </section>
</div>
