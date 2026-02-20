<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-zinc-900 flex items-center justify-center p-6">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <flux:heading size="2xl" class="mb-2">{{ config('app.name') }}</flux:heading>
            <flux:text class="text-zinc-600 dark:text-zinc-400">
                {{ __('Professional Painting CRM') }}
            </flux:text>
        </div>

        <flux:card class="p-6">
            <flux:heading size="lg" class="mb-6 text-center">{{ __('Sign In') }}</flux:heading>

            <div class="space-y-4">
                {{-- Internal Team Login --}}
                <a href="{{ route('login') }}" class="block">
                    <flux:button variant="primary" class="w-full justify-center">
                        <flux:icon name="building-office-2" class="mr-2" />
                        {{ __('Team Login') }}
                    </flux:button>
                </a>

                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-2 bg-white dark:bg-zinc-800 text-zinc-500">{{ __('or') }}</span>
                    </div>
                </div>

                {{-- Agent Portal --}}
                <a href="{{ route('agent.login') }}" class="block">
                    <flux:button variant="ghost" class="w-full justify-center border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="user-group" class="mr-2" />
                        {{ __('Agent Portal') }}
                    </flux:button>
                </a>

                {{-- Customer Portal --}}
                <a href="{{ route('customer.login') }}" class="block">
                    <flux:button variant="ghost" class="w-full justify-center border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="home" class="mr-2" />
                        {{ __('Customer Portal') }}
                    </flux:button>
                </a>

                {{-- Crew Portal --}}
                <a href="{{ route('crew.login') }}" class="block">
                    <flux:button variant="ghost" class="w-full justify-center border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="paint-brush" class="mr-2" />
                        {{ __('Crew Portal') }}
                    </flux:button>
                </a>
            </div>

            <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                <flux:text size="sm" class="text-center text-zinc-500">
                    {{ __('Need an account? Contact your administrator or agent for an invitation.') }}
                </flux:text>
            </div>
        </flux:card>

        <flux:text size="sm" class="text-center mt-6 text-zinc-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
        </flux:text>
    </div>

    @fluxScripts
</body>
</html>
