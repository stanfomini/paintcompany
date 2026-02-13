<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-9 w-9 mb-1 items-center justify-center rounded-md">
                        <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex justify-center">
                    <x-language-switcher compact />
                </div>
                @include('partials.flash-messages')
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const pageLoadTime = Date.now();
                const sessionLifetimeMs = {{ config('session.lifetime', 120) }} * 60 * 1000;
                const warningMs = Math.min(30 * 60 * 1000, sessionLifetimeMs * 0.75);
                let warningShown = false;

                function showSessionWarning() {
                    if (warningShown) return;
                    warningShown = true;

                    const banner = document.createElement('div');
                    banner.id = 'session-expired-banner';
                    banner.className = 'mb-4 rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20 cursor-pointer';
                    banner.innerHTML = `
                        <div class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                            </svg>
                            <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                Your session may have expired. <span class="underline">Click here to refresh</span> before logging in.
                            </p>
                        </div>
                    `;
                    banner.addEventListener('click', () => window.location.reload());

                    const container = document.querySelector('.flex.w-full.max-w-sm.flex-col');
                    if (container) {
                        container.insertBefore(banner, container.children[1]);
                    }
                }

                // Show warning banner after warningMs
                setTimeout(showSessionWarning, warningMs);

                // Auto-reload after full session lifetime
                setTimeout(() => window.location.reload(), sessionLifetimeMs);

                // Block form submission when page is stale
                document.querySelectorAll('form').forEach(form => {
                    form.addEventListener('submit', (e) => {
                        if (Date.now() - pageLoadTime > warningMs) {
                            e.preventDefault();
                            showSessionWarning();
                        }
                    });
                });
            });
        </script>

        @fluxScripts
    </body>
</html>
