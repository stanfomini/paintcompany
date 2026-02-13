<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Crew Registration')" :description="__('Create your crew account to get started.')" />

        <form method="POST" action="{{ route('crew.register') }}" class="flex flex-col gap-6">
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
                :placeholder="__('John Doe')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                :placeholder="__('crew@example.com')"
            />

            <!-- Company Name -->
            <flux:input
                name="company_name"
                :label="__('Company / Crew Name')"
                :value="old('company_name')"
                type="text"
                autocomplete="organization"
                :placeholder="__('Smith Painting Co.')"
            />

            <!-- Phone -->
            <flux:input
                name="phone"
                :label="__('Phone Number')"
                :value="old('phone')"
                type="tel"
                autocomplete="tel"
                :placeholder="__('(555) 123-4567')"
            />

            <!-- Crew Size -->
            <flux:input
                name="crew_size"
                :label="__('Crew Size (number of painters)')"
                :value="old('crew_size', 1)"
                type="number"
                min="1"
                max="50"
                :placeholder="__('1')"
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
            <flux:link :href="route('crew.login')">{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
