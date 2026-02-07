<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('people.view')
                        <flux:sidebar.item icon="users" :href="route('people.index')" :current="request()->routeIs('people.*') || request()->routeIs('customers.*') || request()->routeIs('agents.*') || request()->routeIs('crews.*')" wire:navigate>
                            {{ __('People') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('properties.view')
                        <flux:sidebar.item icon="home" :href="route('properties.index')" :current="request()->routeIs('properties.*')" wire:navigate>
                            {{ __('Properties') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('deals.view')
                        <flux:sidebar.item icon="briefcase" :href="route('deals.index')" :current="request()->routeIs('deals.*')" wire:navigate>
                            {{ __('Deals') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('estimates.view')
                        <flux:sidebar.item icon="document-text" :href="route('estimates.index')" :current="request()->routeIs('estimates.*')" wire:navigate>
                            {{ __('Estimates') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('jobs.view')
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('jobs.index')" :current="request()->routeIs('jobs.*')" wire:navigate>
                            {{ __('Jobs') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('planner.view')
                        <flux:sidebar.item icon="check-circle" :href="route('planner.index')" :current="request()->routeIs('planner.*')" wire:navigate>
                            {{ __('Planner') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('production_rates.view')
                        <flux:sidebar.item icon="chart-bar" :href="route('production-rates.index')" :current="request()->routeIs('production-rates.*')" wire:navigate>
                            {{ __('Production Rates') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('chat.access')
                        <flux:sidebar.item icon="chat-bubble-left-right" :href="route('chat.index')" :current="request()->routeIs('chat.*')" wire:navigate>
                            {{ __('Messages') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('business_plan.view')
                        <flux:sidebar.item icon="presentation-chart-bar" :href="route('business-plan.index')" :current="request()->routeIs('business-plan.*')" wire:navigate>
                            {{ __('Business Plan') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('invoices.view')
                        <flux:sidebar.item icon="document-currency-dollar" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>
                            {{ __('Invoices') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @if (auth()->user() && app(\App\Services\InvitationService::class)->canSendInvitations(auth()->user()))
                    <flux:sidebar.group :heading="__('Team')" class="grid">
                        <flux:sidebar.item icon="envelope" :href="route('invitations')" :current="request()->routeIs('invitations')" wire:navigate>
                            {{ __('Invitations') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @can('settings.company')
                    <flux:sidebar.group :heading="__('Settings')" class="grid">
                        <flux:sidebar.item icon="cog-6-tooth" :href="route('company.edit')" :current="request()->routeIs('company.edit')" wire:navigate>
                            {{ __('Company') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            @auth
                <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
            @endauth
        </flux:sidebar>


        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            @auth
                <flux:dropdown position="top" align="end">
                    <flux:profile
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                    />

                    <flux:menu>
                        <flux:menu.radio.group>
                            <div class="p-0 text-sm font-normal">
                                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                    <flux:avatar
                                        :name="auth()->user()->name"
                                        :initials="auth()->user()->initials()"
                                    />

                                    <div class="grid flex-1 text-start text-sm leading-tight">
                                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                        <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                    </div>
                                </div>
                            </div>
                        </flux:menu.radio.group>

                        <flux:menu.separator />

                        <flux:menu.radio.group>
                            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                {{ __('Settings') }}
                            </flux:menu.item>
                        </flux:menu.radio.group>

                        <flux:menu.separator />

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item
                                as="button"
                                type="submit"
                                icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                {{ __('Log Out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            @endauth
        </flux:header>

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-4">
            @include('partials.flash-messages')
        </div>

        {{ $slot }}

        {{-- Global notification listener for internal users --}}
        @auth
            @if (auth()->user()->isInternal())
                @livewire('notifications.new-lead-alert')
            @endif
        @endauth

        @fluxScripts
    </body>
</html>
