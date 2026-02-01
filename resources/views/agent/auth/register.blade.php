<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Agent Registration')" :description="__('Create your agent account to get started.')" />

        <form method="POST" action="{{ route('agent.register') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Full Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                placeholder="John Doe"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="agent@example.com"
            />

            <!-- Company Name -->
            <flux:input
                name="company_name"
                :label="__('Company Name')"
                :value="old('company_name')"
                type="text"
                autocomplete="organization"
                placeholder="ABC Realty"
            />

            <!-- License Number -->
            <flux:input
                name="license_number"
                :label="__('License Number')"
                :value="old('license_number')"
                type="text"
                placeholder="RE-123456"
            />

            <!-- Phone -->
            <flux:input
                name="phone"
                :label="__('Phone Number')"
                :value="old('phone')"
                type="tel"
                autocomplete="tel"
                placeholder="(555) 123-4567"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm Password')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full">
                    {{ __('Create Account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('agent.login')">{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
