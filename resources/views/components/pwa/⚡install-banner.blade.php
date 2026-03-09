<?php

use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        // Only render for authenticated internal users
        if (! auth()->user()?->isInternal()) {
            $this->skipRender();
        }
    }
};
?>

<div
    x-data="{
        platform: 'unknown',
        canInstall: false,
        showBanner: false,
        showUpdateToast: false,
        showIosInstructions: false,

        init() {
            this.platform = window.getPwaPlatform ? window.getPwaPlatform() : 'unknown';

            // Don't show if already installed
            if (this.platform === 'installed') return;

            // Don't show if dismissed within 7 days
            const dismissed = localStorage.getItem('pwa-banner-dismissed');
            if (dismissed && Date.now() - parseInt(dismissed) < 7 * 24 * 60 * 60 * 1000) return;

            // Android: wait for install prompt
            if (this.platform === 'android') {
                window.addEventListener('pwa-installable', () => {
                    this.canInstall = true;
                    this.showBanner = true;
                });
            }

            // iOS Safari: show instructions banner
            if (this.platform === 'ios-safari') {
                this.showBanner = true;
            }

            // iOS Chrome: show Safari redirect message
            if (this.platform === 'ios-chrome') {
                this.showBanner = true;
            }

            // Desktop: show if install prompt available
            if (this.platform === 'desktop') {
                window.addEventListener('pwa-installable', () => {
                    this.canInstall = true;
                    this.showBanner = true;
                });
            }

            // SW update available
            window.addEventListener('sw-update-available', () => {
                this.showUpdateToast = true;
            });

            // Hide after install
            window.addEventListener('pwa-installed', () => {
                this.showBanner = false;
                this.canInstall = false;
            });
        },

        async install() {
            if (window.pwaInstall) {
                const accepted = await window.pwaInstall();
                if (accepted) this.showBanner = false;
            }
        },

        dismiss() {
            this.showBanner = false;
            localStorage.setItem('pwa-banner-dismissed', Date.now().toString());
        },

        update() {
            if (window.pwaUpdate) window.pwaUpdate();
            this.showUpdateToast = false;
        },
    }"
>
    {{-- Install Banner --}}
    <div
        x-show="showBanner"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-full opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-full opacity-0"
        class="fixed bottom-4 left-4 right-4 z-50 mx-auto max-w-md rounded-xl border border-zinc-200 bg-white p-4 shadow-2xl dark:border-zinc-700 dark:bg-zinc-800 sm:left-auto"
        x-cloak
    >
        {{-- Android / Desktop Install --}}
        <template x-if="canInstall && (platform === 'android' || platform === 'desktop')">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-lg bg-blue-100 p-2 dark:bg-blue-900/50">
                    <flux:icon name="device-phone-mobile" class="size-6 text-blue-600 dark:text-blue-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Install Painting Made Easy Denver') }}</p>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Get push notifications and quick access from your home screen.') }}</p>
                    <div class="mt-3 flex items-center gap-2">
                        <flux:button size="sm" variant="primary" @click="install()">{{ __('Install') }}</flux:button>
                        <flux:button size="sm" variant="ghost" @click="dismiss()">{{ __('Not now') }}</flux:button>
                    </div>
                </div>
                <button @click="dismiss()" class="shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>
        </template>

        {{-- iOS Safari Instructions --}}
        <template x-if="platform === 'ios-safari'">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-lg bg-blue-100 p-2 dark:bg-blue-900/50">
                    <flux:icon name="device-phone-mobile" class="size-6 text-blue-600 dark:text-blue-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Install Painting Made Easy Denver') }}</p>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Tap') }}
                        <svg class="inline size-4 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                        {{ __('Share, then "Add to Home Screen"') }}
                    </p>
                    <div class="mt-3">
                        <flux:button size="sm" variant="ghost" @click="dismiss()">{{ __('Got it') }}</flux:button>
                    </div>
                </div>
                <button @click="dismiss()" class="shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>
        </template>

        {{-- iOS Chrome Message --}}
        <template x-if="platform === 'ios-chrome'">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-lg bg-amber-100 p-2 dark:bg-amber-900/50">
                    <flux:icon name="exclamation-triangle" class="size-6 text-amber-600 dark:text-amber-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Open in Safari') }}</p>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('For the best experience with push notifications, open this page in Safari and install from there.') }}</p>
                    <div class="mt-3">
                        <flux:button size="sm" variant="ghost" @click="dismiss()">{{ __('Dismiss') }}</flux:button>
                    </div>
                </div>
                <button @click="dismiss()" class="shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </div>
        </template>
    </div>

    {{-- Update Available Toast --}}
    <div
        x-show="showUpdateToast"
        x-transition
        class="fixed bottom-4 right-4 z-50 rounded-lg border border-green-200 bg-white p-3 shadow-lg dark:border-green-800 dark:bg-zinc-800"
        x-cloak
    >
        <div class="flex items-center gap-3">
            <div class="shrink-0 rounded-full bg-green-100 p-1.5 dark:bg-green-900/50">
                <flux:icon name="arrow-path" class="size-4 text-green-600 dark:text-green-400" />
            </div>
            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Update available') }}</p>
            <flux:button size="sm" variant="primary" @click="update()">{{ __('Refresh') }}</flux:button>
        </div>
    </div>
</div>
