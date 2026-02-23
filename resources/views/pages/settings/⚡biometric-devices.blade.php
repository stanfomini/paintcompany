<?php

use App\Models\WebauthnCredential;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->check()) {
            abort(403);
        }
    }

    #[Computed]
    public function credentials()
    {
        return auth()->user()->webauthnCredentials()
            ->orderByDesc('created_at')
            ->get();
    }

    public function removeDevice(string $credentialId): void
    {
        auth()->user()->webauthnCredentials()
            ->where('credential_id', $credentialId)
            ->delete();

        unset($this->credentials);
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Biometric Login Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Biometric Login')" :subheading="__('Use Face ID, Touch ID, or fingerprint to sign in quickly.')">
        {{-- Browser Support Check --}}
        <div x-data="{ supported: !!window.PublicKeyCredential, registering: false, error: '' }" class="mt-6">
            <template x-if="!supported">
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                    <div class="flex items-center gap-3">
                        <flux:icon name="exclamation-triangle" class="size-5 text-amber-600 dark:text-amber-400" />
                        <p class="text-sm text-amber-800 dark:text-amber-200">{{ __('Your browser does not support biometric login.') }}</p>
                    </div>
                </div>
            </template>

            <template x-if="supported">
                <div>
                    {{-- Register Button --}}
                    <div class="mb-6">
                        <flux:button
                            variant="primary"
                            icon="finger-print"
                            x-on:click="
                                registering = true;
                                error = '';
                                try {
                                    const success = await window.webauthn.register();
                                    if (success) {
                                        $wire.$refresh();
                                    } else {
                                        error = '{{ __('Registration was cancelled or failed.') }}';
                                    }
                                } catch (e) {
                                    error = e.message || '{{ __('Registration failed. Please try again.') }}';
                                }
                                registering = false;
                            "
                            x-bind:disabled="registering"
                        >
                            <span x-show="!registering">{{ __('Register This Device') }}</span>
                            <span x-show="registering" x-cloak>{{ __('Waiting for biometric...') }}</span>
                        </flux:button>
                        <p x-show="error" x-text="error" class="mt-2 text-sm text-red-600 dark:text-red-400" x-cloak></p>
                    </div>
                </div>
            </template>
        </div>

        {{-- Registered Devices List --}}
        @if ($this->credentials->isNotEmpty())
            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Registered Devices') }}</flux:heading>
                @foreach ($this->credentials as $credential)
                    <div wire:key="cred-{{ $credential->credential_id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <div class="rounded-lg bg-zinc-100 p-2 dark:bg-zinc-800">
                                <flux:icon name="device-phone-mobile" class="size-5 text-zinc-600 dark:text-zinc-400" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $credential->device_name }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('Registered') }} {{ $credential->created_at->diffForHumans() }}
                                    @if ($credential->last_used_at)
                                        &middot; {{ __('Last used') }} {{ $credential->last_used_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            wire:click="removeDevice('{{ $credential->credential_id }}')"
                            wire:confirm="{{ __('Remove this device? You will need to register it again to use biometric login.') }}"
                        />
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
                <flux:icon name="finger-print" class="mx-auto mb-2 size-8 text-zinc-400" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No devices registered yet. Register this device to enable biometric login.') }}</p>
            </div>
        @endif
    </x-pages::settings.layout>
</section>
