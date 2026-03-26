<?php

use App\Models\CompanySetting;
use App\Models\ServiceArea;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('components.layouts.guest')]
#[Title('Service Areas | Painting Made Easy Denver')]
class extends Component
{
    #[Computed]
    public function areas(): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceArea::active()->orderBy('city')->get();
    }

    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function structuredData(): string
    {
        $areas = $this->areas->map(fn ($area) => [
            '@type' => 'City',
            'name' => $area->city,
            'containedInPlace' => [
                '@type' => 'State',
                'name' => 'Colorado',
            ],
        ])->values()->all();

        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => 'Professional House Painting',
            'provider' => [
                '@type' => 'LocalBusiness',
                'name' => $this->company->company_name ?? 'Painting Made Easy Denver',
            ],
            'areaServed' => $areas,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_title', 'Painting Service Areas | Denver Metro & Front Range | Painting Made Easy Denver')
    @section('meta_description', 'Professional painting services across the Denver metro area. Find your local painting team in Denver, Aurora, Lakewood, Arvada, Boulder, Broomfield, and 15+ more Colorado cities.')
    @section('canonical', route('areas.index'))
    @section('og_title', 'Service Areas | Painting Made Easy Denver')
    @section('og_description', 'Professional painting services across the Denver metro area. Find your local team.')
    @section('og_url', route('areas.index'))
    @section('og_type', 'website')
    @section('robots', 'index, follow')

    {{-- Hero --}}
    <section class="bg-brand-900 text-white pt-20 pb-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 text-center">
            @include('partials.public-breadcrumb', ['items' => [
                ['label' => __('Service Areas')],
            ]])
            <h1 class="font-heading text-4xl sm:text-5xl tracking-tight mb-4">{{ __('Our Service Areas') }}</h1>
            <p class="text-lg text-zinc-300 max-w-2xl mx-auto">{{ __('Professional painting services across the Denver metro area. Find your local team and get a free estimate today.') }}</p>
        </div>
    </section>

    {{-- Trust Strip --}}
    @include('partials.public-trust-strip', ['variant' => 'light'])

    {{-- Google Map --}}
    @if($this->areas->where('latitude', '!=', null)->isNotEmpty())
    <section class="bg-warm-50 py-12 sm:py-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-8">
                <h2 class="font-heading text-2xl sm:text-3xl text-zinc-900 mb-2">{{ __('Denver to Fort Collins — We\'ve Got You Covered') }}</h2>
                <p class="text-zinc-600">{{ $this->areas->count() }}{{ __('+ cities along the Front Range and I-25 corridor') }}</p>
            </div>
            @include('partials.google-map', [
                'areas' => $this->areas,
                'center' => [39.95, -104.99],
                'zoom' => 8,
                'lazy' => false,
            ])
        </div>
    </section>
    @endif

    {{-- Area Grid --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="font-heading text-3xl sm:text-4xl text-zinc-900 mb-3">{{ __('Find Your Local Team') }}</h2>
                <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ __('Select your city for neighborhood-specific painting information, local tips, and a free estimate.') }}</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($this->areas as $area)
                    @php
                        $neighborhoods = $area->local_content['neighborhoods'] ?? [];
                        $housingTypes = $area->local_content['housing_types'] ?? [];
                    @endphp
                    <a href="{{ route('areas.show', $area->slug) }}" wire:key="area-{{ $area->id }}" class="group bg-white rounded-xl p-6 border border-zinc-200 hover:border-accent-gold/40 shadow-sm hover:shadow-md transition-all">
                        <div class="flex items-start gap-4">
                            <div class="inline-flex items-center justify-center w-11 h-11 rounded-lg bg-brand-50 text-brand-700 shrink-0 group-hover:bg-brand-700 group-hover:text-white transition-colors">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-bold text-zinc-900 group-hover:text-brand-700 transition-colors text-lg">{{ $area->city }}</h3>
                                <p class="text-sm text-zinc-500 mb-2">{{ $area->state }}</p>
                                @if(!empty($neighborhoods))
                                    <p class="text-xs text-zinc-400 leading-relaxed">{{ implode(' · ', array_slice($neighborhoods, 0, 3)) }}</p>
                                @endif
                            </div>
                        </div>
                        @if(!empty($housingTypes))
                            <div class="mt-3 pt-3 border-t border-zinc-100">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach(array_slice($housingTypes, 0, 2) as $type)
                                        <span class="text-xs bg-warm-100 text-zinc-600 px-2 py-0.5 rounded-full">{{ $type }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    @include('partials.public-cta-section', [
        'heading' => __('Don\'t See Your City?'),
        'description' => __('We serve the entire Denver metro area. Contact us for a free estimate no matter where you are.'),
        'ctaText' => __('Get Your Free Estimate'),
        'ctaUrl' => route('home') . '#get-estimate',
        'variant' => 'accent',
    ])
</div>
