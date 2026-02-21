<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800 overflow-x-hidden">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <div class="flex justify-center px-4 pb-2">
                <x-language-switcher compact />
            </div>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @can('people.view')
                        <flux:sidebar.item icon="magnifying-glass" :href="route('people.index')" :current="request()->routeIs('people.*') || request()->routeIs('customers.*') || request()->routeIs('agents.*') || request()->routeIs('crews.*') || request()->routeIs('properties.*')" wire:navigate>
                            {{ __('Search') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('deals.view')
                        <flux:sidebar.item icon="briefcase" :href="route('deals.index')" :current="request()->routeIs('deals.*')" wire:navigate>
                            <div class="flex items-center justify-between w-full">
                                {{ __('Active Leads') }}
                                @livewire('deals.new-leads-badge')
                            </div>
                        </flux:sidebar.item>
                    @endcan
                    @can('jobs.view')
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('jobs.index')" :current="request()->routeIs('jobs.*')" wire:navigate>
                            <div class="flex items-center justify-between w-full">
                                {{ __('Jobs') }}
                                @livewire('jobs.accepted-badge')
                            </div>
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
                            <div class="flex items-center justify-between w-full">
                                {{ __('Messages') }}
                                @livewire('chat.unread-badge')
                            </div>
                        </flux:sidebar.item>
                    @endcan
                    @can('business_plan.view')
                        <flux:sidebar.item icon="presentation-chart-bar" :href="route('business-plan.index')" :current="request()->routeIs('business-plan.*')" wire:navigate>
                            {{ __('Business Plan') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('media.view')
                        <flux:sidebar.item icon="film" :href="route('media.index')" :current="request()->routeIs('media.*')" wire:navigate>
                            {{ __('Media Hub') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('referrals.view')
                        <flux:sidebar.item icon="gift" :href="route('referrals.index')" :current="request()->routeIs('referrals.*')" wire:navigate>
                            {{ __('Referrals') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @canany(['sales_territories.view', 'door_knocks.record', 'territory_dispatch.claim'])
                    <flux:sidebar.group :heading="__('Sales')" class="grid">
                        @can('territory_dispatch.claim')
                            <flux:sidebar.item icon="signal" :href="route('territory-queue')" :current="request()->routeIs('territory-queue')" wire:navigate>
                                {{ __('Territory Queue') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('door_knocks.record')
                            <flux:sidebar.item icon="map-pin" :href="route('door-knocks.index')" :current="request()->routeIs('door-knocks.*')" wire:navigate>
                                {{ __('Door Knocks') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('sales_territories.manage')
                            <flux:sidebar.item icon="map" :href="route('territories.index')" :current="request()->routeIs('territories.*')" wire:navigate>
                                {{ __('Territories') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('sales_territories.view')
                            <flux:sidebar.item icon="chart-bar-square" :href="route('territory-dashboard')" :current="request()->routeIs('territory-dashboard')" wire:navigate>
                                {{ __('Sales Dashboard') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('sales_territories.view')
                            <flux:sidebar.item icon="presentation-chart-line" :href="route('territory-analytics')" :current="request()->routeIs('territory-analytics')" wire:navigate>
                                {{ __('Territory Analytics') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('compensation.planner')
                            <flux:sidebar.item icon="calculator" :href="route('compensation-planner')" :current="request()->routeIs('compensation-planner')" wire:navigate>
                                {{ __('Compensation Planner') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany

                <flux:sidebar.group :heading="__('Team')" class="grid">
                    @can('employees.view')
                        <flux:sidebar.item icon="users" :href="route('employees.index')" :current="request()->routeIs('employees.*')" wire:navigate>
                            {{ __('Employees') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="folder-open" :href="route('document-center')" :current="request()->routeIs('document-center')" wire:navigate>
                            {{ __('Documents') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('hiring.view')
                        <flux:sidebar.item icon="user-plus" :href="route('hiring.index')" :current="request()->routeIs('hiring.*')" wire:navigate>
                            {{ __('Hiring') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('time_tracking.view')
                        <flux:sidebar.item icon="clock" :href="route('time-tracking.index')" :current="request()->routeIs('time-tracking.*')" wire:navigate>
                            {{ __('Time Tracking') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('scheduling.view')
                        <flux:sidebar.item icon="calendar-days" :href="route('scheduling.index')" :current="request()->routeIs('scheduling.*')" wire:navigate>
                            {{ __('Scheduling') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('payroll.view')
                        <flux:sidebar.item icon="banknotes" :href="route('payroll.index')" :current="request()->routeIs('payroll.index') || request()->routeIs('payroll.run') || request()->routeIs('payroll.stub') || request()->routeIs('payroll.deductions') || request()->routeIs('payroll.check')" wire:navigate>
                            {{ __('Payroll') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('payroll.manage')
                        <flux:sidebar.item icon="currency-dollar" :href="route('payroll.commissions')" :current="request()->routeIs('payroll.commissions')" wire:navigate>
                            {{ __('Commissions') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('settings.contracts')
                        <flux:sidebar.item icon="document-text" :href="route('contracts.templates')" :current="request()->routeIs('contracts.*')" wire:navigate>
                            {{ __('Contracts') }}
                        </flux:sidebar.item>
                    @endcan
                    @can('onboarding.view')
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('onboarding.index')" :current="request()->routeIs('onboarding.*')" wire:navigate>
                            {{ __('Onboarding') }}
                        </flux:sidebar.item>
                    @endcan
                    @if (auth()->user() && app(\App\Services\InvitationService::class)->canSendInvitations(auth()->user()))
                        <flux:sidebar.item icon="envelope" :href="route('invitations')" :current="request()->routeIs('invitations')" wire:navigate>
                            {{ __('Invitations') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                @if(auth()->user()?->employeeProfile)
                    <flux:sidebar.group :heading="__('My Work')" class="grid">
                        <flux:sidebar.item icon="user" :href="route('my.profile')" :current="request()->routeIs('my.profile')" wire:navigate>
                            {{ __('My Profile') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="calendar" :href="route('my.schedule')" :current="request()->routeIs('my.schedule')" wire:navigate>
                            {{ __('My Schedule') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clock" :href="route('my.timesheet')" :current="request()->routeIs('my.timesheet')" wire:navigate>
                            {{ __('My Timesheet') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="banknotes" :href="route('my.pay-stubs')" :current="request()->routeIs('my.pay-stubs')" wire:navigate>
                            {{ __('My Pay Stubs') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="document-text" :href="route('my.contracts')" :current="request()->routeIs('my.contracts')" wire:navigate>
                            {{ __('My Contracts') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="folder-open" :href="route('my.documents')" :current="request()->routeIs('my.documents')" wire:navigate>
                            {{ __('My Documents') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="sun" :href="route('my.pto')" :current="request()->routeIs('my.pto')" wire:navigate>
                            {{ __('My PTO') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="academic-cap" :href="route('my.training')" :current="request()->routeIs('my.training')" wire:navigate>
                            {{ __('My Training') }}
                        </flux:sidebar.item>
                        @if(auth()->user()->employeeProfile->hasCommissionRules())
                            <flux:sidebar.item icon="currency-dollar" :href="route('my.commissions')" :current="request()->routeIs('my.commissions')" wire:navigate>
                                {{ __('My Commissions') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                @canany(['ar.view', 'invoices.view'])
                    <flux:sidebar.group :heading="__('Accounting')" class="grid">
                        @can('ar.view')
                            <flux:sidebar.item icon="banknotes" :href="route('ar.index')" :current="request()->routeIs('ar.*')" wire:navigate>
                                {{ __('AR Portal') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('invoices.view')
                            <flux:sidebar.item icon="document-text" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>
                                {{ __('Invoices') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('ar.view')
                            <flux:sidebar.item icon="credit-card" :href="route('payments.index')" :current="request()->routeIs('payments.*')" wire:navigate>
                                {{ __('Payments') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('vendors.view')
                            <flux:sidebar.item icon="building-storefront" :href="route('vendors.edit')" :current="request()->routeIs('vendors.*')" wire:navigate>
                                {{ __('Vendors') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany

                @can('settings.company')
                    <flux:sidebar.group :heading="__('Settings')" class="grid">
                        @can('settings.company')
                            <flux:sidebar.item icon="cog-6-tooth" :href="route('company.edit')" :current="request()->routeIs('company.edit')" wire:navigate>
                                {{ __('Company') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="video-camera" :href="route('studio')" :current="request()->routeIs('studio')">
                                {{ __('Studio') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            @if(auth()->user()?->employeeProfile)
                <div class="border-t border-zinc-200 dark:border-zinc-700 px-1 py-1">
                    <livewire:employees.clock-button />
                </div>
            @endif

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
                        :avatar="auth()->user()->getAvatarUrl()"
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                    />

                    <flux:menu>
                        <flux:menu.radio.group>
                            <div class="p-0 text-sm font-normal">
                                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                    <flux:avatar
                                        :src="auth()->user()->getAvatarUrl()"
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
                {{-- Floating bottom-left button bar (bell + record) --}}
                <div class="fixed bottom-4 inset-x-0 z-50 pointer-events-none lg:pl-64">
                    <div class="pl-4 flex items-end gap-2">
                        @livewire('notifications.notification-center')
                        @can('media.record')
                            @livewire('media.quick-recorder')
                        @endcan
                    </div>
                </div>
                @livewire('notifications.new-lead-alert')
                @can('chat.access')
                    @persist('chat-dock')
                        @livewire('chat.chat-dock')
                    @endpersist
                @endcan
            @endif
        @endauth

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
