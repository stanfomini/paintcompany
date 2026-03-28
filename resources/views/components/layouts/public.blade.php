<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta name="format-detection" content="telephone=no" />

        <title>{{ $title ?? config('app.name') }}</title>

        <link rel="canonical" href="@yield('canonical', url()->current())">
        @hasSection('robots')
        <meta name="robots" content="@yield('robots')">
        @endif

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preload" href="/fonts/instrument-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/instrument-sans-latin-500-normal.woff2" as="font" type="font/woff2" crossorigin>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-900">
        <div class="min-h-screen flex flex-col">
            <!-- Header -->
            <header class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 py-4 px-6">
                <div class="max-w-4xl mx-auto flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <flux:heading size="lg">{{ config('app.name') }}</flux:heading>
                    </div>
                    @if(auth('customer')->check())
                        <flux:button variant="ghost" size="sm" :href="route('customer.dashboard')" icon="home">
                            {{ __('My Dashboard') }}
                        </flux:button>
                    @endif
                </div>
            </header>

            <!-- Main Content -->
            <main class="flex-1">
                {{ $slot }}
            </main>

            <!-- Footer -->
            <footer class="bg-white dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700 py-6 px-6">
                <div class="max-w-4xl mx-auto text-center">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                    </flux:text>
                    <div class="mt-2 flex justify-center gap-4">
                        <a href="{{ route('legal.privacy') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">Privacy Policy</a>
                        <a href="{{ route('legal.terms') }}" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 text-xs underline">Terms &amp; Conditions</a>
                    </div>
                </div>
            </footer>
        </div>

        @fluxScripts
        @include('partials.messenger-widget')
    </body>
</html>
