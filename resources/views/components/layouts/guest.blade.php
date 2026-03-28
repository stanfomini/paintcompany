<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @production
        <script>
        (function() {
            var loaded = false;
            function loadAnalytics() {
                if (loaded) return;
                loaded = true;
                // Google Tag Manager
                (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
                new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
                j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
                'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
                })(window,document,'script','dataLayer','GTM-KPDL23JM');
                // Google Analytics
                var ga = document.createElement('script');
                ga.async = true;
                ga.src = 'https://www.googletagmanager.com/gtag/js?id=G-D4F4V2J77S';
                document.head.appendChild(ga);
                ga.onload = function() {
                    window.dataLayer = window.dataLayer || [];
                    function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());
                    gtag('config', 'G-D4F4V2J77S');
                };
            }
            ['scroll','mousemove','touchstart','keydown'].forEach(function(e) {
                window.addEventListener(e, loadAnalytics, {once: true, passive: true});
            });
            setTimeout(loadAnalytics, 5000);
        })();
        </script>
        @endproduction
        @include($headPartial ?? 'partials.head-public')
        @isset($structuredData)
        <script type="application/ld+json">
        {!! $structuredData !!}
        </script>
        @endisset
        @stack('google-maps')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-900">
        @production
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-KPDL23JM"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        @endproduction
        <div class="min-h-screen flex flex-col">
            <main class="flex-1">
                {{ $slot }}
            </main>

            <footer class="bg-brand-900 text-white">
                @php
                    $footerCompany = \App\Models\CompanySetting::getInstance();
                    $footerPhone = $footerCompany->company_phone ?? '(720) 999-9725';
                    $footerName = $footerCompany->company_name ?? config('app.name');
                    $footerAreas = \App\Models\ServiceArea::active()->orderBy('city')->limit(12)->get(['city', 'slug']);
                @endphp
                {{-- Top strip --}}
                <div class="border-b border-white/10">
                    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                            <div class="text-center sm:text-left">
                                <p class="font-heading text-2xl sm:text-3xl">{{ $footerName }}</p>
                                <p class="text-sm text-zinc-400 mt-1">{{ __('Licensed | Insured | Bonded') }}</p>
                            </div>
                            <a href="tel:{{ $footerPhone }}" class="inline-flex items-center gap-2 text-accent-gold hover:text-white text-xl sm:text-2xl font-semibold transition-colors">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                                {{ $footerPhone }}
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Navigation columns --}}
                <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10 sm:py-12">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-8">
                        {{-- Services --}}
                        <div>
                            <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">{{ __('Services') }}</h4>
                            <ul class="space-y-2">
                                <li><a href="{{ route('services.show', 'interior-painting') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Interior Painting') }}</a></li>
                                <li><a href="{{ route('services.show', 'exterior-painting') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Exterior Painting') }}</a></li>
                                <li><a href="{{ route('services.show', 'cabinet-painting') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Cabinet Painting') }}</a></li>
                                <li><a href="{{ route('services.show', 'deck-fence-staining') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Deck & Fence Staining') }}</a></li>
                                <li><a href="{{ route('services.index') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('All Services') }}</a></li>
                            </ul>
                        </div>

                        {{-- Service Areas --}}
                        <div>
                            <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">{{ __('Service Areas') }}</h4>
                            <ul class="space-y-2">
                                @foreach ($footerAreas as $footerArea)
                                    <li><a href="{{ route('areas.show', $footerArea->slug) }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ $footerArea->city }}</a></li>
                                @endforeach
                                <li><a href="{{ route('areas.index') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('All Areas') }}</a></li>
                            </ul>
                        </div>

                        {{-- Company --}}
                        <div>
                            <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">{{ __('Company') }}</h4>
                            <ul class="space-y-2">
                                <li><a href="{{ route('how-it-works') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('How It Works') }}</a></li>
                                <li><a href="{{ route('faq') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('FAQ') }}</a></li>
                                <li><a href="{{ route('careers') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Careers') }}</a></li>
                                <li><a href="{{ route('visualize') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Color Visualizer') }}</a></li>
                                <li><a href="{{ route('calculator') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Property Value Calculator') }}</a></li>
                                <li><a href="{{ route('live.index') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Live Events') }}</a></li>
                            </ul>
                        </div>

                        {{-- Legal --}}
                        <div>
                            <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">{{ __('Legal') }}</h4>
                            <ul class="space-y-2">
                                <li><a href="{{ route('legal.privacy') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Privacy Policy') }}</a></li>
                                <li><a href="{{ route('legal.terms') }}" class="text-sm text-zinc-400 hover:text-white transition-colors">{{ __('Terms & Conditions') }}</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Bottom copyright --}}
                <div class="border-t border-white/10">
                    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 flex flex-col sm:flex-row items-center justify-between gap-2">
                        <p class="text-xs text-zinc-500">&copy; {{ date('Y') }} {{ $footerName }}. {{ __('All rights reserved.') }}</p>
                        <p class="text-xs text-zinc-500">{{ __('Serving the Denver Metro Area — Fort Collins to Castle Rock') }}</p>
                    </div>
                </div>
            </footer>
        </div>
        @fluxScripts
        @include('partials.messenger-widget')
    </body>
</html>
