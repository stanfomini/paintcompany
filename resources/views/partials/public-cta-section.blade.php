@props([
    'heading' => __('Ready to Transform Your Home?'),
    'description' => __('Get a free estimate and see why Denver homeowners trust us with their painting projects.'),
    'ctaText' => __('Get Your Free Estimate'),
    'ctaUrl' => route('booking.general'),
    'variant' => 'dark',
])

@php
    $companyPhone = \App\Models\CompanySetting::getInstance()->company_phone;

    $sectionClasses = match ($variant) {
        'dark' => 'bg-brand-900 text-white',
        'light' => 'bg-warm-50',
        'accent' => 'bg-cta text-white',
        default => 'bg-brand-900 text-white',
    };
    $headingClasses = match ($variant) {
        'dark' => 'text-white',
        'light' => 'text-zinc-900',
        'accent' => 'text-white',
        default => 'text-white',
    };
    $descClasses = match ($variant) {
        'dark' => 'text-zinc-300',
        'light' => 'text-zinc-600',
        'accent' => 'text-white/90',
        default => 'text-zinc-300',
    };
    $btnClasses = match ($variant) {
        'dark' => 'bg-cta hover:bg-cta-hover text-white',
        'light' => 'bg-cta hover:bg-cta-hover text-white',
        'accent' => 'bg-white text-cta hover:bg-zinc-50',
        default => 'bg-cta hover:bg-cta-hover text-white',
    };
    $phoneClasses = match ($variant) {
        'dark' => 'text-accent-gold hover:text-white',
        'light' => 'text-cta hover:text-cta-hover',
        'accent' => 'text-white/90 hover:text-white',
        default => 'text-accent-gold hover:text-white',
    };
@endphp

<section class="{{ $sectionClasses }} py-16 sm:py-20">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
        <h2 class="font-heading text-3xl sm:text-4xl {{ $headingClasses }} mb-4">{{ $heading }}</h2>
        <p class="text-lg {{ $descClasses }} mb-8 max-w-2xl mx-auto">{{ $description }}</p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
            <a href="{{ $ctaUrl }}" class="inline-flex items-center gap-3 {{ $btnClasses }} font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                {{ $ctaText }}
            </a>
            @if($companyPhone)
                <a href="tel:{{ $companyPhone }}" class="inline-flex items-center gap-2 text-lg font-semibold {{ $phoneClasses }} transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                    {{ $companyPhone }}
                </a>
            @endif
        </div>
    </div>
</section>
