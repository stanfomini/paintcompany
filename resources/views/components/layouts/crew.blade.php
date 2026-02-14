<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth('crew')
    <meta name="user-id" content="{{ auth('crew')->id() }}">
    <meta name="user-type" content="crew">
    @endauth

    <title>{{ $title ?? __('Crew Portal') }} - {{ config('app.name') }}</title>

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
            <flux:navlist.item icon="cog-6-tooth" href="{{ route('crew.settings.profile') }}" :current="request()->routeIs('crew.settings') || request()->routeIs('crew.settings.*')">
                {{ __('Settings') }}
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        <div class="flex justify-center px-4 pb-1">
            <x-language-switcher compact />
        </div>

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile
                :avatar="auth('crew')->user()->getAvatarUrl()"
                :name="auth('crew')->user()->name"
                :initials="auth('crew')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="cog-6-tooth" href="{{ route('crew.settings.profile') }}">{{ __('Settings') }}</flux:menu.item>
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
                :avatar="auth('crew')->user()->getAvatarUrl()"
                :initials="auth('crew')->user()->initials()"
            />

            <flux:menu>
                <flux:menu.item icon="cog-6-tooth" href="{{ route('crew.settings.profile') }}">{{ __('Settings') }}</flux:menu.item>
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

        @auth('crew')
            @if(auth('crew')->user()->needs_onboarding && !request()->routeIs('crew.onboarding'))
                @php
                    $profile = auth('crew')->user()->crewProfile;
                    $step = $profile ? $profile->getCurrentOnboardingStep() : 1;
                    $progress = $profile ? $profile->getOnboardingProgressPercent() : 0;
                @endphp
                <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <flux:icon.clipboard-document-check class="size-5 text-amber-600 dark:text-amber-400" />
                            <div>
                                <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                                    {{ __('Complete your onboarding to accept jobs') }}
                                </p>
                                <p class="text-xs text-amber-600 dark:text-amber-400">
                                    {{ __('Step :step of 5', ['step' => $step]) }} &mdash; {{ $progress }}% {{ __('complete') }}
                                </p>
                            </div>
                        </div>
                        <flux:button href="{{ route('crew.onboarding') }}" variant="primary" size="sm">
                            {{ __('Continue Onboarding') }}
                        </flux:button>
                    </div>
                    <div class="mt-3">
                        <div class="h-1.5 w-full rounded-full bg-amber-200 dark:bg-amber-800">
                            <div class="h-1.5 rounded-full bg-amber-500 transition-all" style="width: {{ $progress }}%"></div>
                        </div>
                    </div>
                </div>
            @endif
        @endauth

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

    @fluxScripts
</body>
</html>
