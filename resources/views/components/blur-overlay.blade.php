@props([
    'show' => false,
    'ctaText' => __('Login'),
    'ctaUrl' => '#',
    'message' => __('Log in to see your project details.'),
])

@if($show)
    <div class="relative">
        <div class="select-none pointer-events-none" style="filter: blur(6px)">
            {{ $slot }}
        </div>
        <div class="absolute inset-0 flex items-center justify-center bg-white/60 dark:bg-zinc-900/60 backdrop-blur-sm rounded-lg">
            <div class="text-center px-4 py-3">
                <flux:icon name="lock-closed" class="w-6 h-6 mx-auto text-zinc-400 dark:text-zinc-500 mb-2" />
                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mb-3">{{ $message }}</flux:text>
                <flux:button variant="primary" size="sm" :href="$ctaUrl">{{ $ctaText }}</flux:button>
            </div>
        </div>
    </div>
@else
    {{ $slot }}
@endif
