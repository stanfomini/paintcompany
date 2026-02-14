<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('user-password.edit')" wire:navigate>{{ __('Password') }}</flux:navlist.item>
            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                <flux:navlist.item :href="route('two-factor.show')" wire:navigate>{{ __('Two-Factor Auth') }}</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            @if (auth()->user()->type === 'internal')
                <flux:navlist.item :href="route('signature.edit')" wire:navigate>{{ __('Signature') }}</flux:navlist.item>
                @can('settings.company')
                    <flux:navlist.item :href="route('company.edit')" wire:navigate>{{ __('Company') }}</flux:navlist.item>
                @endcan
                @can('settings.packages')
                    <flux:navlist.item :href="route('packages.edit')" wire:navigate>{{ __('Packages') }}</flux:navlist.item>
                @endcan
                @can('settings.allocations')
                    <flux:navlist.item :href="route('allocations.edit')" wire:navigate>{{ __('Allocations') }}</flux:navlist.item>
                @endcan
                @can('team.manage_roles')
                    <flux:navlist.item :href="route('roles.edit')" wire:navigate>{{ __('Roles & Access') }}</flux:navlist.item>
                @endcan
                @can('settings.payment_integrations')
                    <flux:navlist.item :href="route('payment-integrations.edit')" wire:navigate>{{ __('Payment Integrations') }}</flux:navlist.item>
                @endcan
                @can('settings.service_categories')
                    <flux:navlist.item :href="route('service-categories.edit')" wire:navigate>{{ __('Service Categories') }}</flux:navlist.item>
                @endcan
                @can('settings.budget_mappings')
                    <flux:navlist.item :href="route('budget-mappings.edit')" wire:navigate>{{ __('Budget Mappings') }}</flux:navlist.item>
                @endcan
                @can('settings.referral')
                    <flux:navlist.item :href="route('referral.edit')" wire:navigate>{{ __('Referral') }}</flux:navlist.item>
                @endcan
                @can('settings.contracts')
                    <flux:navlist.item :href="route('contracts.edit')" wire:navigate>{{ __('Contracts') }}</flux:navlist.item>
                @endcan
                @can('settings.lead_sources')
                    <flux:navlist.item :href="route('lead-sources.edit')" wire:navigate>{{ __('Lead Sources') }}</flux:navlist.item>
                @endcan
                @can('settings.workflows')
                    <flux:navlist.item :href="route('workflows.edit')" wire:navigate>{{ __('Workflows') }}</flux:navlist.item>
                @endcan
            @endif
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full {{ ($fullWidth ?? false) ? 'max-w-5xl' : 'max-w-lg' }}">
            {{ $slot }}
        </div>
    </div>
</div>
