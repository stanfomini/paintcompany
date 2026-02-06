<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth('customer')
    <meta name="user-id" content="{{ auth('customer')->id() }}">
    <meta name="user-type" content="customer">
    @endauth

    <title>{{ $title ?? 'Customer Portal' }} - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky stashable class="border-e bg-zinc-50 border-zinc-200 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('customer.dashboard') }}" logo="{{ asset('images/logo.svg') }}" name="{{ config('app.name') }}" class="px-2" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route('customer.dashboard') }}" :current="request()->routeIs('customer.dashboard')">
                {{ __('Dashboard') }}
            </flux:navlist.item>
            <flux:navlist.item icon="briefcase" href="{{ route('customer.projects.index') }}" :current="request()->routeIs('customer.projects.*')">
                {{ __('My Projects') }}
            </flux:navlist.item>
            <flux:navlist.item icon="home" href="{{ route('customer.properties.index') }}" :current="request()->routeIs('customer.properties.*')">
                {{ __('My Properties') }}
            </flux:navlist.item>
            <flux:navlist.item icon="document-text" href="{{ route('customer.estimates.index') }}" :current="request()->routeIs('customer.estimates.*')">
                {{ __('Estimates') }}
            </flux:navlist.item>
            <flux:navlist.item icon="clipboard-document-check" href="{{ route('customer.jobs.index') }}" :current="request()->routeIs('customer.jobs.*')">
                {{ __('My Jobs') }}
            </flux:navlist.item>
            <flux:navlist.item icon="credit-card" href="#" :current="false">
                {{ __('Invoices') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="chat-bubble-left-right" href="{{ route('customer.chat.index') }}" :current="request()->routeIs('customer.chat.*')">
                {{ __('Messages') }}
            </flux:navlist.item>
            <flux:navlist.item icon="question-mark-circle" href="#" :current="false">
                {{ __('Support') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile
                :name="auth('customer')->user()->name"
                :initials="auth('customer')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="user" href="#">{{ __('Profile') }}</flux:menu.item>
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
                :initials="auth('customer')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="user" href="#">{{ __('Profile') }}</flux:menu.item>
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

    @fluxScripts
</body>
</html>
