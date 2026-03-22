<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        @isset($structuredData)
        <script type="application/ld+json">
        {!! $structuredData !!}
        </script>
        @endisset
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-900">
        <div class="min-h-screen flex flex-col">
            <main class="flex-1">
                {{ $slot }}
            </main>

            <footer class="py-6 px-6">
                <div class="max-w-4xl mx-auto text-center">
                    <div class="flex justify-center flex-wrap items-center gap-x-4 gap-y-1">
                        <a href="tel:+17209999725" class="text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 text-sm font-medium">(720) 999-9725</a>
                        <span class="text-zinc-300 dark:text-zinc-600">|</span>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Denver, CO</flux:text>
                    </div>
                    <flux:text size="xs" class="mt-1 text-zinc-400 dark:text-zinc-500">Serving the Denver Metro Area</flux:text>
                    <div class="mt-3 flex justify-center flex-wrap gap-4">
                        <a href="{{ route('how-it-works') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">How It Works</a>
                        <a href="{{ route('faq') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">FAQ</a>
                        <a href="{{ route('visualize') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">Color Visualizer</a>
                        <a href="{{ route('areas.index') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">Service Areas</a>
                        <a href="{{ route('legal.privacy') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">Privacy Policy</a>
                        <a href="{{ route('legal.terms') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">Terms &amp; Conditions</a>
                    </div>
                    <flux:text size="sm" class="mt-3 text-zinc-500 dark:text-zinc-400">
                        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                    </flux:text>
                </div>
            </footer>
        </div>
        @fluxScripts
        @include('partials.messenger-widget')
    </body>
</html>
