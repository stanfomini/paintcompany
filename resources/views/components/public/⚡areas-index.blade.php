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
    @section('meta_description', 'Professional painting services across the Denver metro area. Find your local painting team in Denver, Aurora, Lakewood, Arvada, Boulder, Broomfield, and 15+ more Colorado cities.')
    @section('canonical', route('areas.index'))
    @section('og_title', 'Service Areas | Painting Made Easy Denver')
    @section('og_description', 'Professional painting services across the Denver metro area. Find your local team.')
    @section('og_url', route('areas.index'))
    @section('og_type', 'website')
    @section('robots', 'index, follow')

    {{-- Hero --}}
    <section class="bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white pt-20 pb-16">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 text-center">
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight mb-4">{{ __('Our Service Areas') }}</h1>
            <p class="text-lg text-blue-100 max-w-2xl mx-auto">{{ __('Professional painting services across the Denver metro area. Find your local team and get a free estimate today.') }}</p>
        </div>
    </section>

    {{-- Area Grid --}}
    <section class="bg-zinc-50 py-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($this->areas as $area)
                    <a href="{{ route('areas.show', $area->slug) }}" wire:key="area-{{ $area->id }}" class="group bg-white rounded-xl p-5 shadow-sm border border-zinc-200 hover:shadow-md hover:border-blue-300 transition-all">
                        <div class="flex items-start gap-3">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-blue-100 text-blue-600 shrink-0 group-hover:bg-blue-600 group-hover:text-white transition-colors">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                            </div>
                            <div>
                                <h2 class="font-bold text-zinc-900 group-hover:text-blue-600 transition-colors">{{ $area->city }}</h2>
                                <p class="text-sm text-zinc-500">{{ $area->state }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-gradient-to-r from-emerald-500 to-emerald-600 py-12">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <h2 class="text-2xl sm:text-3xl font-bold text-white mb-4">{{ __('Don\'t see your city?') }}</h2>
            <p class="text-emerald-100 text-lg mb-6">{{ __('We serve the entire Denver metro area. Contact us for a free estimate no matter where you are.') }}</p>
            <a href="{{ route('home') }}#get-estimate" class="inline-flex items-center gap-2 bg-white text-emerald-700 font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-xl hover:bg-zinc-50">
                {{ __('Get Your Free Estimate') }}
            </a>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="bg-[#1B2D4F] text-white py-10">
        <div class="max-w-5xl mx-auto px-6">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-6">
                <p class="text-sm text-blue-200">&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <a href="{{ route('home') }}" class="text-blue-200 hover:text-white transition-colors">{{ __('Home') }}</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('legal.terms') }}" class="text-blue-200 hover:text-white transition-colors">{{ __('Terms') }}</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('legal.privacy') }}" class="text-blue-200 hover:text-white transition-colors">{{ __('Privacy') }}</a>
                </div>
            </div>
        </div>
    </footer>
</div>
