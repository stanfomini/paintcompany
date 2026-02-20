<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth('customer')
    <meta name="user-id" content="{{ auth('customer')->id() }}">
    <meta name="user-type" content="customer">
    @endauth

    <title>{{ $title ?? 'Customer Portal' }} - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800 overflow-x-hidden">
    <flux:sidebar sticky stashable class="border-e bg-zinc-50 border-zinc-200 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('customer.dashboard') }}" logo="{{ asset('images/logo.svg') }}" name="{{ config('app.name') }}" class="px-2" />

        <div class="flex justify-center px-4 pb-2">
            <x-language-switcher compact />
        </div>

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route('customer.dashboard') }}" :current="request()->routeIs('customer.dashboard')">
                {{ __('Dashboard') }}
            </flux:navlist.item>
            <flux:navlist.item icon="chat-bubble-left-right" href="{{ route('customer.chat.index') }}" :current="request()->routeIs('customer.chat.*')">
                {{ __('Messages') }}
            </flux:navlist.item>
            <flux:navlist.item icon="credit-card" href="{{ route('customer.invoices.index') }}" :current="request()->routeIs('customer.invoices.*')">
                {{ __('Invoices') }}
            </flux:navlist.item>
            <flux:navlist.item icon="gift" href="{{ route('customer.referrals') }}" :current="request()->routeIs('customer.referrals')">
                {{ __('Referrals') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile
                :avatar="auth('customer')->user()->getAvatarUrl()"
                :name="auth('customer')->user()->name"
                :initials="auth('customer')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="cog-6-tooth" href="{{ route('customer.settings.profile') }}">{{ __('Settings') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('customer.logout') }}">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">
                        {{ __('Log Out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile
                :avatar="auth('customer')->user()->getAvatarUrl()"
                :initials="auth('customer')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="cog-6-tooth" href="{{ route('customer.settings.profile') }}">{{ __('Settings') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('customer.logout') }}">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">
                        {{ __('Log Out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main>
        @include('partials.flash-messages')
        {{ $slot }}
    </flux:main>

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.interceptRequest(({ onError }) => {
                onError(({ response, preventDefault }) => {
                    if (response.status === 419 || response.status === 401) {
                        preventDefault();
                        window.location.reload();
                    }
                });
            });
        });
    </script>

    @livewire('shared.toast-container')
    @fluxScripts
</body>
</html>
