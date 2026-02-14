@props(['routePrefix', 'heading' => '', 'subheading' => '', 'userType', 'fullWidth' => false])

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route($routePrefix . '.profile')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route($routePrefix . '.password')" wire:navigate>{{ __('Password') }}</flux:navlist.item>
            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                <flux:navlist.item :href="route($routePrefix . '.two-factor')" wire:navigate>{{ __('Two-Factor Auth') }}</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route($routePrefix . '.appearance')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            @if ($userType === 'crew')
                <flux:navlist.item :href="route('crew.settings')" wire:navigate>{{ __('Crew Profile') }}</flux:navlist.item>
            @endif
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div class="mt-5 w-full {{ $fullWidth ? 'max-w-5xl' : 'max-w-lg' }}">
            {{ $slot }}
        </div>
    </div>
</div>
