<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth('crew')
    <meta name="user-id" content="{{ auth('crew')->id() }}">
    <meta name="user-type" content="crew">
    @endauth

    <title>{{ $title ?? 'Crew Portal' }} - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky collapsible="mobile" class="border-e bg-zinc-50 border-zinc-200 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('crew.dashboard') }}" logo="{{ asset('images/logo.svg') }}" name="{{ config('app.name') }}" class="px-2" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route('crew.dashboard') }}" :current="request()->routeIs('crew.dashboard')">
                {{ __('Dashboard') }}
            </flux:navlist.item>
            <flux:navlist.item icon="briefcase" href="{{ route('crew.jobs.available') }}" :current="request()->routeIs('crew.jobs.available')">
                {{ __('Available Jobs') }}
            </flux:navlist.item>
            <flux:navlist.item icon="clipboard-document-check" href="{{ route('crew.jobs.mine') }}" :current="request()->routeIs('crew.jobs.mine') || request()->routeIs('crew.jobs.show')">
                {{ __('My Jobs') }}
            </flux:navlist.item>
            <flux:navlist.item icon="chat-bubble-left-right" href="{{ route('crew.chat.index') }}" :current="request()->routeIs('crew.chat.*')">
                {{ __('Messages') }}
            </flux:navlist.item>
            <flux:navlist.item icon="gift" href="{{ route('crew.referrals') }}" :current="request()->routeIs('crew.referrals')">
                {{ __('Referrals') }}
            </flux:navlist.item>
            <flux:navlist.item icon="cog-6-tooth" href="{{ route('crew.settings') }}" :current="request()->routeIs('crew.settings')">
                {{ __('Settings') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile
                :name="auth('crew')->user()->name"
                :initials="auth('crew')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="cog-6-tooth" href="{{ route('crew.settings') }}">{{ __('Settings') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('crew.logout') }}">
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
                :initials="auth('crew')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="cog-6-tooth" href="{{ route('crew.settings') }}">{{ __('Settings') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('crew.logout') }}">
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

    @fluxScripts
</body>
</html>
