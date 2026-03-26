<?php

use App\Models\CompanySetting;
use App\Models\Service;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('Painting Services Guide | Painting Made Easy Denver')] class extends Component
{
    #[Computed]
    public function servicesByCategory(): \Illuminate\Support\Collection
    {
        return Service::active()
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');
    }

    #[Computed]
    public function allServices(): \Illuminate\Database\Eloquent\Collection
    {
        return Service::active()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function structuredData(): string
    {
        $items = [];
        $position = 1;

        foreach ($this->allServices as $service) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'item' => [
                    '@type' => 'Service',
                    'name' => $service->title,
                    'description' => $service->meta_description ?? $service->tagline,
                    'url' => $service->url,
                    'provider' => ['@id' => url('/') . '#business'],
                ],
            ];
        }

        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Painting Services Guide',
            'description' => 'Complete painting services for Denver, CO',
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_title', 'Painting Services Guide | Denver CO | Painting Made Easy Denver')
    @section('meta_description', 'Denver painting services guide. Interior, exterior, cabinet, commercial & specialty painting with Colorado climate tips.')
    @section('canonical', route('services.index'))
    @section('robots', 'index, follow')
    @section('og_title', 'Painting Services Guide | Painting Made Easy Denver')
    @section('og_description', 'Denver painting services guide. Interior, exterior, cabinet, commercial & specialty painting with Colorado tips.')
    @section('og_url', route('services.index'))
    @section('og_type', 'website')

    {{-- Hero --}}
    <section class="bg-brand-900 text-white pt-20 pb-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 text-center">
            @include('partials.public-breadcrumb', ['items' => [
                ['label' => __('Services')],
            ]])
            <h1 class="font-heading text-4xl sm:text-5xl tracking-tight mb-4">{{ __('Painting Services Guide') }}</h1>
            <p class="text-lg text-zinc-300 max-w-3xl mx-auto leading-relaxed">{{ __('Your complete resource for painting services in the Denver metro area. Detailed how-to guides, materials lists, cost factors, and expert tips for Colorado\'s unique climate.') }}</p>
        </div>
    </section>

    {{-- Trust Strip --}}
    @include('partials.public-trust-strip', ['variant' => 'light'])

    {{-- Service Categories --}}
    @php
        $categoryMeta = [
            'Residential' => [
                'heading' => __('Residential Painting'),
                'description' => __('From interior walls to exterior siding, deck staining to cabinet refinishing — everything Denver homeowners need.'),
                'bg' => 'bg-white',
                'iconBg' => 'bg-brand-50 text-brand-700',
                'iconHoverBg' => 'group-hover:bg-brand-700 group-hover:text-white',
            ],
            'Commercial' => [
                'heading' => __('Commercial Painting'),
                'description' => __('Professional painting for offices, retail spaces, warehouses, and multi-unit properties with minimal disruption to your business.'),
                'bg' => 'bg-warm-50',
                'iconBg' => 'bg-green-100 text-cta',
                'iconHoverBg' => 'group-hover:bg-cta group-hover:text-white',
            ],
            'Specialty' => [
                'heading' => __('Specialty Services'),
                'description' => __('Beyond traditional painting — pressure washing, line striping, color consultation, and specialty coatings for unique projects.'),
                'bg' => 'bg-white',
                'iconBg' => 'bg-amber-100 text-amber-700',
                'iconHoverBg' => 'group-hover:bg-amber-600 group-hover:text-white',
            ],
        ];

        $categoryIcons = [
            'interior-painting' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
            'exterior-painting' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21',
            'cabinet-painting' => 'M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3',
            'deck-fence-staining' => 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z',
            'garage-floor-coating' => 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.029-.394 1.029-1.014v-2.236m-16.5 0V6.375c0-.621.504-1.125 1.125-1.125h12.75c.621 0 1.125.504 1.125 1.125v11.25c0 .62-.408 1.014-1.029 1.014H18.75',
            'popcorn-ceiling-removal' => 'M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25',
            'drywall-repair' => 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.049.58.025 1.192-.14 1.743',
            'trim-door-painting' => 'M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42',
            'wallpaper-removal' => 'M7.848 8.25l1.536.887M7.848 8.25a3 3 0 11-5.196-3 3 3 0 015.196 3zm1.536.887a2.165 2.165 0 011.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l2.077 1.199M7.848 15.75l1.536-.887m-1.536.887a3 3 0 11-5.196 3 3 3 0 015.196 3zm1.536-.887a2.165 2.165 0 001.083-1.838c.005-.352.054-.695.14-1.025m-1.223 2.863l2.077-1.199m0-3.328a4.323 4.323 0 012.068-1.379l5.325-1.628a4.5 4.5 0 012.48-.044l.803.215-7.794 4.5m-2.882-1.664A4.33 4.33 0 0010.607 12m3.736 0l7.794 4.5-.802.215a4.5 4.5 0 01-2.48-.043l-5.326-1.629a4.324 4.324 0 01-2.068-1.379M14.343 12l-2.882 1.664',
            'commercial-interior' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
            'commercial-exterior' => 'M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72l1.189-1.19A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72M6.75 18h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .414.336.75.75.75z',
            'warehouse-industrial' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
            'hoa-multiunit' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z',
            'line-striping' => 'M9 6.75V15m0-8.25a5.985 5.985 0 002.372.42 5.99 5.99 0 002.378-.42M9 6.75l-1.11-.74A2.25 2.25 0 004.5 7.94v8.514a2.25 2.25 0 001.675 2.175l1.49.497a2.25 2.25 0 001.424-.014l3.16-1.129a2.25 2.25 0 011.502 0l3.16 1.129a2.25 2.25 0 001.424.014l1.49-.497A2.25 2.25 0 0021.75 16.46V7.94a2.25 2.25 0 00-3.39-1.934L15 8.25M9 6.75L6.61 5.066a2.25 2.25 0 00-3.11.934v0M15 8.25v8.25',
            'pressure-washing' => 'M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
            'color-consultation' => 'M4.098 19.902a3.75 3.75 0 005.304 0l6.401-6.402M6.75 21A3.75 3.75 0 013 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 003.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008z',
            'stucco-coating' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
        ];
    @endphp

    @foreach($this->servicesByCategory as $category => $services)
        @php $meta = $categoryMeta[$category] ?? $categoryMeta['Residential']; @endphp
        <section class="{{ $meta['bg'] }} py-16 sm:py-20">
            <div class="max-w-6xl mx-auto px-4 sm:px-6">
                <div class="mb-10">
                    <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-3">{{ $meta['heading'] }}</h2>
                    <p class="text-lg text-zinc-600 max-w-3xl">{{ $meta['description'] }}</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($services as $service)
                        <a wire:key="service-{{ $service->slug }}" href="{{ $service->url }}" class="group block bg-white hover:bg-warm-50 rounded-xl p-6 border border-zinc-100 hover:border-accent-gold/30 shadow-sm hover:shadow-md transition-all">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg {{ $meta['iconBg'] }} {{ $meta['iconHoverBg'] }} transition-colors mb-4">
                                @if(isset($categoryIcons[$service->slug]))
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $categoryIcons[$service->slug] }}" /></svg>
                                @else
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" /></svg>
                                @endif
                            </div>
                            <h3 class="font-bold text-zinc-900 text-lg mb-2 group-hover:text-brand-700 transition-colors">{{ $service->title }}</h3>
                            <p class="text-zinc-600 text-sm mb-4">{{ $service->tagline ?? $service->meta_description }}</p>
                            <span class="text-sm font-semibold text-cta group-hover:text-cta-hover transition-colors">{{ __('Read Guide') }} &rarr;</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    {{-- CTA --}}
    @include('partials.public-cta-section', [
        'heading' => __('Not Sure What You Need?'),
        'description' => __('Our team can assess your project and recommend the right services. Get a free estimate or try our AI color visualizer.'),
        'ctaText' => __('Get Your Free Estimate'),
        'ctaUrl' => route('home') . '#get-estimate',
        'variant' => 'dark',
    ])
</div>
