<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="overflow-y-auto">
        <div class="mx-auto max-w-7xl">
            @include('partials.flash-messages')
        </div>
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
