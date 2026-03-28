<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-900">
        @php
            $company = \App\Models\CompanySetting::getInstance();
        @endphp

        <header class="border-b border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if($company->logo_path)
                            <a href="{{ route('watch.index') }}">
                                <img src="{{ storage_url($company->logo_path) }}" alt="{{ $company->company_name }}" class="h-8 w-auto">
                            </a>
                        @endif
                        <a href="{{ route('watch.index') }}" class="text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ $company->company_name }}
                        </a>
                    </div>
                    <nav class="flex items-center gap-4">
                        <a href="{{ route('watch.index') }}" class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            {{ __('Videos') }}
                        </a>
                        <a href="{{ route('live.index') }}" class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            {{ __('Live') }}
                        </a>
                        <a href="/" class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            {{ __('Home') }}
                        </a>
                    </nav>
                </div>
            </div>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer class="mt-16 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                    <p>&copy; {{ date('Y') }} {{ $company->company_name }}</p>
                </div>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
