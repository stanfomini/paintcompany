<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth('agent')
    <meta name="user-id" content="{{ auth('agent')->id() }}">
    <meta name="user-type" content="agent">
    @endauth

    <title>{{ $title ?? 'Agent Portal' }} - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky stashable class="border-e bg-zinc-50 border-zinc-200 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('agent.dashboard') }}" logo="{{ asset('images/logo.svg') }}" name="{{ config('app.name') }}" class="px-2" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route('agent.dashboard') }}" :current="request()->routeIs('agent.dashboard')">
                {{ __('Dashboard') }}
            </flux:navlist.item>
            <flux:navlist.item icon="building-office" href="{{ route('agent.listings.index') }}" :current="request()->routeIs('agent.listings.*')">
                {{ __('My Listings') }}
            </flux:navlist.item>
            <flux:navlist.item icon="users" href="{{ route('agent.customers.index') }}" :current="request()->routeIs('agent.customers.*')">
                {{ __('My Customers') }}
            </flux:navlist.item>
            <flux:navlist.item icon="currency-dollar" href="{{ route('agent.deals.index') }}" :current="request()->routeIs('agent.deals.*')">
                {{ __('My Deals') }}
            </flux:navlist.item>
            <flux:navlist.item icon="chat-bubble-left-right" href="{{ route('agent.chat.index') }}" :current="request()->routeIs('agent.chat.*')">
                {{ __('Messages') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="envelope" href="{{ route('agent.invitations') }}" :current="request()->routeIs('agent.invitations')">
                {{ __('Invite Customers') }}
            </flux:navlist.item>
            <flux:navlist.item icon="cog-6-tooth" href="#" :current="false">
                {{ __('Settings') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile
                :name="auth('agent')->user()->name"
                :initials="auth('agent')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="user" href="#">{{ __('Profile') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('agent.logout') }}">
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
                :initials="auth('agent')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="user" href="#">{{ __('Profile') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('agent.logout') }}">
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
