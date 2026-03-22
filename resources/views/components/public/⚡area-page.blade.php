<?php

use App\Models\CompanySetting;
use App\Models\ServiceArea;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component
{
    public ServiceArea $area;

    public function mount(string $slug): void
    {
        $this->area = ServiceArea::where('slug', $slug)->where('is_active', true)->firstOrFail();
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
    public function structuredData(): string
    {
        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'HousePainter',
            'name' => ($this->company->company_name ?? 'Painting Made Easy Denver') . ' - ' . $this->area->city,
            'description' => 'Professional house painting in ' . $this->area->city . ' with AI color visualization, instant digital pricing, real-time project tracking, and digital proposals with e-signatures. ' . ($this->area->meta_description ?? ''),
            '@id' => route('areas.show', $this->area->slug),
            'url' => route('areas.show', $this->area->slug),
            'telephone' => $this->company->company_phone,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $this->area->city,
                'addressRegion' => $this->area->state,
                'addressCountry' => 'US',
            ],
            'areaServed' => [
                '@type' => 'City',
                'name' => $this->area->city,
                'containedInPlace' => [
                    '@type' => 'State',
                    'name' => 'Colorado',
                ],
            ],
            'priceRange' => '$$',
            'openingHoursSpecification' => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'opens' => $this->company->business_hours_start ?? '08:00',
                'closes' => $this->company->business_hours_end ?? '17:00',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    #[Computed]
    public function services(): array
    {
        return [
            ['name' => __('Interior Painting'), 'desc' => __('Walls, ceilings, trim, doors, and accent features')],
            ['name' => __('Exterior Painting'), 'desc' => __('Siding, trim, fascia, soffits, shutters, and doors')],
            ['name' => __('Cabinet Painting'), 'desc' => __('Kitchen and bathroom cabinet refinishing')],
            ['name' => __('Deck & Fence Staining'), 'desc' => __('Wood preservation and beautification')],
            ['name' => __('Commercial Painting'), 'desc' => __('Office, retail, and commercial spaces')],
            ['name' => __('Color Consultation'), 'desc' => __('Expert help choosing the perfect colors')],
        ];
    }
};
?>

<x-slot:structuredData>{!! $this->structuredData !!}</x-slot:structuredData>

<div>
    @section('meta_description', $area->meta_description)
    @section('canonical', route('areas.show', $area->slug))
    @section('og_title', $area->meta_title)
    @section('og_description', $area->meta_description)
    @section('og_url', route('areas.show', $area->slug))
    @section('og_type', 'website')
    @section('robots', 'index, follow')

    {{-- Hero --}}
    <section class="relative bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white overflow-hidden pt-12">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-5xl mx-auto px-4 sm:px-6 pt-16 sm:pt-24 pb-12 sm:pb-16 text-center">
            <nav class="mb-6">
                <a href="{{ route('areas.index') }}" class="text-blue-200 hover:text-white text-sm transition-colors">&larr; {{ __('All Service Areas') }}</a>
            </nav>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight mb-6 leading-tight">
                {{ __('Professional Painting Services in :city, :state', ['city' => $area->city, 'state' => $area->state]) }}
            </h1>
            <p class="text-lg sm:text-xl text-blue-100 mb-8 max-w-3xl mx-auto leading-relaxed">
                {{ $area->hero_text ?? __(':city homeowners get a stress-free painting experience with real-time project tracking, daily photo updates, and a satisfaction guarantee.', ['city' => $area->city]) }}
            </p>
            <a href="{{ route('home') }}#get-estimate" class="inline-flex items-center gap-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-xl">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                {{ __('Get Your Free Estimate in :city', ['city' => $area->city]) }}
            </a>
        </div>
    </section>

    {{-- Services Available --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Painting Services in :city', ['city' => $area->city]) }}</h2>
                @if($area->specialties_text)
                    <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ $area->specialties_text }}</p>
                @endif
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($this->services as $service)
                    <div class="bg-zinc-50 rounded-xl p-6">
                        <div class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-blue-100 text-blue-600 mb-3">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </div>
                        <h3 class="font-bold text-zinc-900 mb-1">{{ $service['name'] }}</h3>
                        <p class="text-sm text-zinc-600">{{ $service['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Local Team (if territory linked) --}}
    @if($this->teamMembers['manager'] || $this->teamMembers['estimators']->isNotEmpty())
    <section class="bg-zinc-50 py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Meet Your :city Team', ['city' => $area->city]) }}</h2>
                <p class="text-lg text-zinc-600">{{ __('Our local team knows :city inside and out.', ['city' => $area->city]) }}</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @if($this->teamMembers['manager'])
                    <div class="bg-white rounded-xl p-6 shadow-sm text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 text-blue-600 mb-4">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        </div>
                        <h3 class="font-bold text-zinc-900">{{ $this->teamMembers['manager']->name }}</h3>
                        <p class="text-sm text-blue-600">{{ __('Area Director') }}</p>
                    </div>
                @endif
                @foreach($this->teamMembers['estimators'] as $estimator)
                    <div class="bg-white rounded-xl p-6 shadow-sm text-center" wire:key="estimator-{{ $estimator->id }}">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 mb-4">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        </div>
                        <h3 class="font-bold text-zinc-900">{{ $estimator->name }}</h3>
                        <p class="text-sm text-emerald-600">{{ __('Estimator') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Why Choose Us --}}
    <section class="bg-white py-16 sm:py-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Why :city Homeowners Choose Us', ['city' => $area->city]) }}</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div class="bg-zinc-50 rounded-xl p-6 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 mb-4">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    </div>
                    <h3 class="font-bold text-zinc-900 mb-2">{{ __('Satisfaction Guaranteed') }}</h3>
                    <p class="text-sm text-zinc-600">{{ __('If you\'re not thrilled with the result, we\'ll make it right. Period.') }}</p>
                </div>
                <div class="bg-zinc-50 rounded-xl p-6 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-blue-600 mb-4">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" /></svg>
                    </div>
                    <h3 class="font-bold text-zinc-900 mb-2">{{ __('Real-Time Tracking') }}</h3>
                    <p class="text-sm text-zinc-600">{{ __('Track your project from estimate to completion with daily photo updates.') }}</p>
                </div>
                <div class="bg-zinc-50 rounded-xl p-6 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-amber-100 text-amber-600 mb-4">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                    </div>
                    <h3 class="font-bold text-zinc-900 mb-2">{{ __('Cleaner Than We Found It') }}</h3>
                    <p class="text-sm text-zinc-600">{{ __('Your home is protected and cleaned daily. We guarantee it.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-gradient-to-r from-emerald-500 to-emerald-600 py-12 sm:py-16">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <h2 class="text-2xl sm:text-3xl font-bold text-white mb-4">{{ __('Ready to Transform Your :city Home?', ['city' => $area->city]) }}</h2>
            <p class="text-emerald-100 text-lg mb-6">{{ __('Get a free, no-obligation estimate from our :city team. Most homeowners hear back within hours.', ['city' => $area->city]) }}</p>
            <a href="{{ route('home') }}#get-estimate" class="inline-flex items-center gap-2 bg-white text-emerald-700 font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-xl hover:bg-zinc-50">
                {{ __('Get Your Free Estimate') }}
            </a>
        </div>
    </section>

    {{-- Other Service Areas --}}
    <section class="bg-zinc-50 py-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <h3 class="text-xl font-bold text-zinc-900 mb-6 text-center">{{ __('Other Service Areas') }}</h3>
            <div class="flex flex-wrap justify-center gap-3">
                @foreach(ServiceArea::active()->where('id', '!=', $area->id)->orderBy('city')->get() as $otherArea)
                    <a href="{{ route('areas.show', $otherArea->slug) }}" wire:key="other-{{ $otherArea->id }}" class="text-sm px-3 py-1.5 bg-white rounded-full border border-zinc-200 text-zinc-700 hover:border-blue-300 hover:text-blue-600 transition-colors">
                        {{ $otherArea->city }}
                    </a>
                @endforeach
            </div>
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
                    <a href="{{ route('areas.index') }}" class="text-blue-200 hover:text-white transition-colors">{{ __('Service Areas') }}</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('legal.terms') }}" class="text-blue-200 hover:text-white transition-colors">{{ __('Terms') }}</a>
                    <span class="text-blue-400">|</span>
                    <a href="{{ route('legal.privacy') }}" class="text-blue-200 hover:text-white transition-colors">{{ __('Privacy') }}</a>
                </div>
            </div>
        </div>
    </footer>
</div>
