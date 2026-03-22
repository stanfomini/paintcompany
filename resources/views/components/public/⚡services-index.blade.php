<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('Painting Services Guide | Painting Made Easy Denver')] class extends Component
{
    public function services(): array
    {
        return [
            'interior-painting' => [
                'title' => 'Interior House Painting',
                'description' => 'Complete guide to walls, ceilings, and full room painting with prep, technique, and Denver climate tips.',
                'category' => 'residential',
                'icon' => 'home',
            ],
            'exterior-painting' => [
                'title' => 'Exterior House Painting',
                'description' => 'Siding, trim, and full exterior painting guide for Colorado\'s high-altitude UV and temperature swings.',
                'category' => 'residential',
                'icon' => 'building',
            ],
            'cabinet-painting' => [
                'title' => 'Cabinet Painting & Refinishing',
                'description' => 'Kitchen and bathroom cabinet transformation with proper prep, priming, and finish techniques.',
                'category' => 'residential',
                'icon' => 'cabinet',
            ],
            'deck-fence-staining' => [
                'title' => 'Deck & Fence Staining',
                'description' => 'Wood preservation and staining guide for Colorado\'s harsh freeze-thaw cycles and intense sun.',
                'category' => 'residential',
                'icon' => 'sun',
            ],
            'garage-floor-coating' => [
                'title' => 'Garage Floor Coating',
                'description' => 'Epoxy and polyurea floor coating guide with moisture testing, grinding, and application steps.',
                'category' => 'residential',
                'icon' => 'truck',
            ],
            'popcorn-ceiling-removal' => [
                'title' => 'Popcorn Ceiling Removal & Repainting',
                'description' => 'Safe removal process including asbestos testing, scraping, skim coating, and repainting.',
                'category' => 'residential',
                'icon' => 'arrows',
            ],
            'drywall-repair' => [
                'title' => 'Drywall Repair & Patching',
                'description' => 'Fix holes, cracks, and damage from nail pops to large repairs with texture matching.',
                'category' => 'residential',
                'icon' => 'wrench',
            ],
            'trim-door-painting' => [
                'title' => 'Trim, Door & Molding Painting',
                'description' => 'Baseboards, crown molding, doors, and window trim painting with pro-level finish techniques.',
                'category' => 'residential',
                'icon' => 'paintbrush',
            ],
            'wallpaper-removal' => [
                'title' => 'Wallpaper Removal & Repainting',
                'description' => 'Wallpaper stripping, adhesive removal, wall repair, and repainting guide.',
                'category' => 'residential',
                'icon' => 'scissors',
            ],
            'commercial-interior' => [
                'title' => 'Commercial Interior Painting',
                'description' => 'Office, retail, and restaurant painting with minimal disruption, VOC compliance, and phased scheduling.',
                'category' => 'commercial',
                'icon' => 'office',
            ],
            'commercial-exterior' => [
                'title' => 'Commercial Exterior Painting',
                'description' => 'Commercial building exterior painting with safety compliance, lift operations, and weather planning.',
                'category' => 'commercial',
                'icon' => 'storefront',
            ],
            'warehouse-industrial' => [
                'title' => 'Warehouse & Industrial Painting',
                'description' => 'Warehouse floor marking, safety color coding, epoxy systems, and OSHA compliance.',
                'category' => 'commercial',
                'icon' => 'cube',
            ],
            'hoa-multiunit' => [
                'title' => 'HOA & Multi-Unit Painting',
                'description' => 'Apartment, condo, and townhome complex painting with HOA coordination and resident communication.',
                'category' => 'commercial',
                'icon' => 'library',
            ],
            'line-striping' => [
                'title' => 'Parking Lot & Line Striping',
                'description' => 'ADA-compliant parking lot striping, fire lanes, and traffic marking with thermoplastic and paint options.',
                'category' => 'specialty',
                'icon' => 'map',
            ],
            'pressure-washing' => [
                'title' => 'Pressure Washing & Surface Prep',
                'description' => 'PSI guide for every surface, nozzle selection, chemical washing, and Denver water regulations.',
                'category' => 'specialty',
                'icon' => 'beaker',
            ],
            'color-consultation' => [
                'title' => 'Color Consultation & Design',
                'description' => 'Color theory, undertone analysis, whole-home flow planning, and Colorado light considerations.',
                'category' => 'specialty',
                'icon' => 'swatch',
            ],
            'stucco-coating' => [
                'title' => 'Elastomeric & Stucco Coating',
                'description' => 'Stucco crack repair, elastomeric waterproofing, and freeze-thaw protection for Colorado homes.',
                'category' => 'specialty',
                'icon' => 'shield',
            ],
        ];
    }

    #[Computed]
    public function residentialServices(): array
    {
        return array_filter($this->services(), fn ($s) => $s['category'] === 'residential');
    }

    #[Computed]
    public function commercialServices(): array
    {
        return array_filter($this->services(), fn ($s) => $s['category'] === 'commercial');
    }

    #[Computed]
    public function specialtyServices(): array
    {
        return array_filter($this->services(), fn ($s) => $s['category'] === 'specialty');
    }

    #[Computed]
    public function structuredData(): string
    {
        $items = [];
        $position = 1;

        foreach ($this->services() as $slug => $service) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'item' => [
                    '@type' => 'Service',
                    'name' => $service['title'],
                    'description' => $service['description'],
                    'url' => route('services.show', $slug),
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
    @push('seo_meta')
    <meta name="description" content="Complete painting services guide for Denver homeowners and businesses. Step-by-step guides for interior, exterior, cabinet, commercial painting and more. Expert tips for Colorado's climate.">
    <link rel="canonical" href="{{ route('services.index') }}">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="Painting Services Guide | Painting Made Easy Denver">
    <meta property="og:description" content="Complete painting services guide for Denver homeowners and businesses. Expert tips for Colorado's climate.">
    <meta property="og:url" content="{{ route('services.index') }}">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Painting Services Guide | Painting Made Easy Denver">
    <meta name="twitter:description" content="Complete painting services guide for Denver homeowners and businesses. Expert tips for Colorado's climate.">
    @endpush

    {{-- Hero --}}
    <section class="bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white pt-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 pt-16 sm:pt-24 pb-12 sm:pb-16 text-center">
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight mb-6">Painting Services Guide</h1>
            <p class="text-lg sm:text-xl text-blue-100 max-w-3xl mx-auto leading-relaxed">Your complete resource for painting services in the Denver metro area. Detailed how-to guides, materials lists, cost factors, and expert tips for Colorado's unique climate.</p>
        </div>
    </section>

    {{-- Social Proof Strip --}}
    <section class="bg-white border-b border-zinc-100">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-center gap-3 sm:gap-6 text-sm">
            <div class="flex items-center gap-1.5">
                <div class="flex">
                    <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                </div>
                <span class="font-semibold text-zinc-900">4.9/5</span>
                <span class="text-zinc-500">from 47 reviews</span>
            </div>
            <span class="hidden sm:inline text-zinc-300">|</span>
            <span class="text-zinc-500">Trusted by Denver homeowners since 2024</span>
            <span class="hidden sm:inline text-zinc-300">|</span>
            <a href="tel:+17209999725" class="font-medium text-emerald-600 hover:text-emerald-700 transition-colors">(720) 999-9725</a>
        </div>
    </section>

    {{-- Residential Section --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="mb-10">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-3">Residential Painting</h2>
                <p class="text-lg text-zinc-600 max-w-3xl">From interior walls to exterior siding, deck staining to cabinet refinishing — everything Denver homeowners need.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($this->residentialServices as $slug => $service)
                    <a wire:key="service-{{ $slug }}" href="{{ route('services.show', $slug) }}" class="group block bg-zinc-50 hover:bg-blue-50 rounded-xl p-6 border border-zinc-100 hover:border-blue-200 transition-all">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-blue-100 text-blue-600 mb-4">
                            @switch($service['icon'])
                                @case('home')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                                    @break
                                @case('building')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" /></svg>
                                    @break
                                @case('cabinet')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3" /></svg>
                                    @break
                                @case('sun')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                                    @break
                                @case('truck')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.029-.394 1.029-1.014v-2.236m-16.5 0V6.375c0-.621.504-1.125 1.125-1.125h12.75c.621 0 1.125.504 1.125 1.125v11.25c0 .62-.408 1.014-1.029 1.014H18.75" /></svg>
                                    @break
                                @case('arrows')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25" /></svg>
                                    @break
                                @case('wrench')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.049.58.025 1.192-.14 1.743" /></svg>
                                    @break
                                @case('paintbrush')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" /></svg>
                                    @break
                                @case('scissors')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m7.848 8.25 1.536.887M7.848 8.25a3 3 0 1 1-5.196-3 3 3 0 0 1 5.196 3Zm1.536.887a2.165 2.165 0 0 1 1.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l2.077 1.199M7.848 15.75l1.536-.887m-1.536.887a3 3 0 1 1-5.196 3 3 3 0 0 1 5.196-3Zm1.536-.887a2.165 2.165 0 0 0 1.083-1.838c.005-.352.054-.695.14-1.025m-1.223 2.863 2.077-1.199m0-3.328a4.323 4.323 0 0 1 2.068-1.379l5.325-1.628a4.5 4.5 0 0 1 2.48-.044l.803.215-7.794 4.5m-2.882-1.664A4.33 4.33 0 0 0 10.607 12m3.736 0 7.794 4.5-.802.215a4.5 4.5 0 0 1-2.48-.043l-5.326-1.629a4.324 4.324 0 0 1-2.068-1.379M14.343 12l-2.882 1.664" /></svg>
                                    @break
                            @endswitch
                        </div>
                        <h3 class="font-bold text-zinc-900 text-lg mb-2 group-hover:text-blue-600 transition-colors">{{ $service['title'] }}</h3>
                        <p class="text-zinc-600 text-sm mb-4">{{ $service['description'] }}</p>
                        <span class="text-sm font-semibold text-blue-600 group-hover:text-blue-700">Read Guide &rarr;</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Commercial Section --}}
    <section class="bg-zinc-50 py-16 sm:py-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="mb-10">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-3">Commercial Painting</h2>
                <p class="text-lg text-zinc-600 max-w-3xl">Professional painting for offices, retail spaces, warehouses, and multi-unit properties with minimal disruption to your business.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach($this->commercialServices as $slug => $service)
                    <a wire:key="service-{{ $slug }}" href="{{ route('services.show', $slug) }}" class="group block bg-white hover:bg-blue-50 rounded-xl p-6 border border-zinc-100 hover:border-blue-200 transition-all">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-emerald-100 text-emerald-600 mb-4">
                            @switch($service['icon'])
                                @case('office')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                    @break
                                @case('storefront')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" /></svg>
                                    @break
                                @case('cube')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" /></svg>
                                    @break
                                @case('library')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" /></svg>
                                    @break
                            @endswitch
                        </div>
                        <h3 class="font-bold text-zinc-900 text-lg mb-2 group-hover:text-blue-600 transition-colors">{{ $service['title'] }}</h3>
                        <p class="text-zinc-600 text-sm mb-4">{{ $service['description'] }}</p>
                        <span class="text-sm font-semibold text-blue-600 group-hover:text-blue-700">Read Guide &rarr;</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Specialty Section --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="mb-10">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-3">Specialty Services</h2>
                <p class="text-lg text-zinc-600 max-w-3xl">Beyond traditional painting — pressure washing, line striping, color consultation, and specialty coatings for unique projects.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach($this->specialtyServices as $slug => $service)
                    <a wire:key="service-{{ $slug }}" href="{{ route('services.show', $slug) }}" class="group block bg-zinc-50 hover:bg-blue-50 rounded-xl p-6 border border-zinc-100 hover:border-blue-200 transition-all">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-violet-100 text-violet-600 mb-4">
                            @switch($service['icon'])
                                @case('map')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m0-8.25a5.985 5.985 0 0 0 2.372.42 5.99 5.99 0 0 0 2.378-.42M9 6.75l-1.11-.74A2.25 2.25 0 0 0 4.5 7.94v8.514a2.25 2.25 0 0 0 1.675 2.175l1.49.497a2.25 2.25 0 0 0 1.424-.014l3.16-1.129a2.25 2.25 0 0 1 1.502 0l3.16 1.129a2.25 2.25 0 0 0 1.424.014l1.49-.497A2.25 2.25 0 0 0 21.75 16.46V7.94a2.25 2.25 0 0 0-3.39-1.934L15 8.25M9 6.75 6.61 5.066a2.25 2.25 0 0 0-3.11.934v0M15 8.25v8.25" /></svg>
                                    @break
                                @case('beaker')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
                                    @break
                                @case('swatch')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 0 0 5.304 0l6.401-6.402M6.75 21A3.75 3.75 0 0 1 3 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 0 0 3.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008Z" /></svg>
                                    @break
                                @case('shield')
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                                    @break
                            @endswitch
                        </div>
                        <h3 class="font-bold text-zinc-900 text-lg mb-2 group-hover:text-blue-600 transition-colors">{{ $service['title'] }}</h3>
                        <p class="text-zinc-600 text-sm mb-4">{{ $service['description'] }}</p>
                        <span class="text-sm font-semibold text-blue-600 group-hover:text-blue-700">Read Guide &rarr;</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white py-16 sm:py-20">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-bold mb-4">Not Sure What You Need?</h2>
            <p class="text-lg text-blue-100 mb-8">Our team can assess your project and recommend the right services. Get a free estimate or try our AI color visualizer.</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('home') }}#get-estimate" class="inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Get Your Free Estimate
                </a>
                <a href="tel:+17209999725" class="inline-flex items-center justify-center gap-2 bg-white/10 hover:bg-white/20 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors border border-white/20">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    (720) 999-9725
                </a>
            </div>
        </div>
    </section>
</div>
