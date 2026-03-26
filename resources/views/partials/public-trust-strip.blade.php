@props(['variant' => 'dark'])

@php
    $items = [
        ['label' => __('Licensed & Insured'), 'icon' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
        ['label' => __('5-Star Rated'), 'icon' => 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z'],
        ['label' => __('Satisfaction Guaranteed'), 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.745 3.745 0 011.043 3.296A3.745 3.745 0 0121 12z'],
        ['label' => __('Same-Day Estimates'), 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];

    $wrapperClass = $variant === 'light'
        ? 'border-y border-white/10'
        : 'border-y border-zinc-200 bg-warm-50';
    $textClass = $variant === 'light'
        ? 'text-white/80'
        : 'text-zinc-600';
    $iconClass = $variant === 'light'
        ? 'text-accent-gold'
        : 'text-cta';
    $separatorClass = $variant === 'light'
        ? 'bg-white/20'
        : 'bg-zinc-300';
@endphp

<div class="{{ $wrapperClass }} py-4">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-3 sm:gap-x-8">
            @foreach ($items as $index => $item)
                @if ($index > 0)
                    <div class="hidden sm:block w-px h-4 {{ $separatorClass }}"></div>
                @endif
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 {{ $iconClass }} shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                    </svg>
                    <span class="text-sm font-medium {{ $textClass }}">{{ $item['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
